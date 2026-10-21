<?php

declare(strict_types=1);

namespace Belvedere\Queues;

use Belvedere\Support\Payload;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class NativeQueue
{
    private const CHUNK = 1000;

    /** Bytes of payload read to find the uuid Laravel writes first. */
    private const PAYLOAD_HEAD = 512;

    public function __construct(
        private DatabaseManager $db,
        private Repository $config,
        private QueueFactory $queues,
        private QueuePauser $pauser,
    ) {}

    /** @return list<string> */
    public function connections(): array
    {
        $configured = (array) $this->config->get('belvedere.connections', []);

        return array_values(array_filter(
            $configured,
            fn (mixed $name): bool => is_string($name) && $this->config->has("queue.connections.{$name}"),
        ));
    }

    public function supports(string $connection): bool
    {
        return $this->config->get("queue.connections.{$connection}.driver") === 'database';
    }

    /** @return Collection<int, QueueCounts> */
    public function counts(string $connection): Collection
    {
        if (! $this->supports($connection)) {
            $queue = $this->defaultQueue($connection);

            return collect([new QueueCounts(
                connection: $connection,
                queue: $queue,
                pending: $this->queues->connection($connection)->size($queue),
                delayed: 0,
                running: 0,
                stuck: 0,
                paused: $this->pauser->isPaused($connection, $queue),
            )]);
        }

        $now = Carbon::now()->getTimestamp();
        $expired = $now - $this->retryAfter($connection);

        // Aliases are suffixed because "delayed" is a reserved word on MySQL and MariaDB.
        $rows = $this->table($connection)
            ->selectRaw(
                'queue,'
                .' sum(case when reserved_at is null and available_at <= ? then 1 else 0 end) as pending_jobs,'
                .' sum(case when reserved_at is null and available_at > ? then 1 else 0 end) as delayed_jobs,'
                .' sum(case when reserved_at is not null and reserved_at >= ? then 1 else 0 end) as running_jobs,'
                .' sum(case when reserved_at is not null and reserved_at < ? then 1 else 0 end) as stuck_jobs',
                [$now, $now, $expired, $expired],
            )
            ->groupBy('queue')
            ->get()
            ->keyBy('queue');

        $queues = $rows->keys()->push($this->defaultQueue($connection))->unique()->sort()->values();

        return $queues->map(function (string $queue) use ($connection, $rows): QueueCounts {
            $row = $rows->get($queue);

            return new QueueCounts(
                connection: $connection,
                queue: $queue,
                pending: (int) ($row->pending_jobs ?? 0),
                delayed: (int) ($row->delayed_jobs ?? 0),
                running: (int) ($row->running_jobs ?? 0),
                stuck: (int) ($row->stuck_jobs ?? 0),
                paused: $this->pauser->isPaused($connection, $queue),
            );
        });
    }

    /**
     * How long the job at the head of each queue has been waiting, in seconds.
     *
     * @return Collection<string, int<0, max>> keyed by queue, only the queues with a job ready to run
     */
    public function oldestWaits(string $connection): Collection
    {
        if (! $this->supports($connection)) {
            return collect();
        }

        $now = Carbon::now()->getTimestamp();

        return $this->table($connection)
            ->selectRaw('queue, min(available_at) as oldest_available_at')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now)
            ->groupBy('queue')
            ->orderBy('queue')
            ->get()
            ->mapWithKeys(static fn (object $row): array => [(string) $row->queue => max(0, $now - (int) $row->oldest_available_at)]);
    }

    /** @return Collection<int, NativeJob> */
    public function jobs(string $connection, NativeState $state, ?string $queue, int $limit, ?int $beforeId): Collection
    {
        $this->assertSupported($connection);

        $now = Carbon::now()->getTimestamp();
        $retryAfter = $this->retryAfter($connection);
        $expired = $now - $retryAfter;

        $query = $this->table($connection)
            ->when($queue !== null, fn (Builder $query): Builder => $query->where('queue', $queue))
            ->when($beforeId !== null, fn (Builder $query): Builder => $query->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($limit);

        match ($state) {
            NativeState::Pending => $query->whereNull('reserved_at')->where('available_at', '<=', $now),
            NativeState::Delayed => $query->whereNull('reserved_at')->where('available_at', '>', $now),
            NativeState::Running => $query->whereNotNull('reserved_at')->where('reserved_at', '>=', $expired),
            NativeState::Stuck => $query->whereNotNull('reserved_at')->where('reserved_at', '<', $expired),
        };

        return $query->get()->map(fn (object $row): NativeJob => NativeJob::fromRow($row, $connection, $retryAfter, $now));
    }

    public function find(string $connection, int $id): ?NativeJob
    {
        $this->assertSupported($connection);

        $row = $this->table($connection)->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        return NativeJob::fromRow($row, $connection, $this->retryAfter($connection), Carbon::now()->getTimestamp());
    }

    public function delete(string $connection, int $id): bool
    {
        $this->assertSupported($connection);

        return $this->table($connection)->where('id', $id)->whereNull('reserved_at')->delete() > 0;
    }

    public function runNow(string $connection, int $id): bool
    {
        $this->assertSupported($connection);

        return $this->table($connection)
            ->where('id', $id)
            ->whereNull('reserved_at')
            ->update(['available_at' => Carbon::now()->getTimestamp()]) > 0;
    }

    public function release(string $connection, int $id): bool
    {
        $this->assertSupported($connection);

        return $this->table($connection)
            ->where('id', $id)
            ->whereNotNull('reserved_at')
            ->update(['reserved_at' => null, 'available_at' => Carbon::now()->getTimestamp()]) > 0;
    }

    public function purge(string $connection, string $queue): int
    {
        $this->assertSupported($connection);

        return $this->table($connection)->where('queue', $queue)->whereNull('reserved_at')->delete();
    }

    /**
     * The uuids of the jobs stored for a queue, as a set.
     *
     * Laravel writes the uuid at the start of every payload, so only the head
     * of each payload is read; the rare payloads built differently are read whole.
     *
     * @return array<string, true>
     */
    public function uuids(string $connection, string $queue, bool $unreservedOnly = false): array
    {
        $this->assertSupported($connection);

        $uuids = [];
        $lastId = 0;

        do {
            $rows = $this->table($connection)
                ->selectRaw('id, substr(payload, 1, '.self::PAYLOAD_HEAD.') as payload_head')
                ->where('queue', $queue)
                ->when($unreservedOnly, fn (Builder $query): Builder => $query->whereNull('reserved_at'))
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get();

            $unparsed = [];

            foreach ($rows as $row) {
                $lastId = (int) $row->id;

                if (preg_match('/"uuid"\s*:\s*"([^"]{1,64})"/', (string) $row->payload_head, $matches) === 1) {
                    $uuids[$matches[1]] = true;
                } else {
                    $unparsed[] = $lastId;
                }
            }

            if ($unparsed !== []) {
                foreach ($this->table($connection)->whereIn('id', $unparsed)->pluck('payload') as $payload) {
                    $uuid = Payload::tryFromJson((string) $payload)?->uuid();

                    if ($uuid !== null) {
                        $uuids[$uuid] = true;
                    }
                }
            }
        } while ($rows->count() === self::CHUNK);

        return $uuids;
    }

    public function defaultQueue(string $connection): string
    {
        return (string) $this->config->get("queue.connections.{$connection}.queue", 'default');
    }

    public function retryAfter(string $connection): int
    {
        return (int) $this->config->get("queue.connections.{$connection}.retry_after", 90);
    }

    private function table(string $connection): Builder
    {
        $database = $this->config->get("queue.connections.{$connection}.connection");
        $table = (string) $this->config->get("queue.connections.{$connection}.table", 'jobs');

        return $this->db->connection($database)->table($table);
    }

    private function assertSupported(string $connection): void
    {
        if (! $this->supports($connection)) {
            throw new InvalidArgumentException("Queue connection [{$connection}] does not use the database driver.");
        }
    }
}
