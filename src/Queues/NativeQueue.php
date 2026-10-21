<?php

declare(strict_types=1);

namespace Belvedere\Queues;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class NativeQueue
{
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

        $rows = $this->table($connection)
            ->selectRaw(
                'queue,'
                .' sum(case when reserved_at is null and available_at <= ? then 1 else 0 end) as pending,'
                .' sum(case when reserved_at is null and available_at > ? then 1 else 0 end) as delayed,'
                .' sum(case when reserved_at is not null and reserved_at >= ? then 1 else 0 end) as running,'
                .' sum(case when reserved_at is not null and reserved_at < ? then 1 else 0 end) as stuck',
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
                pending: (int) ($row->pending ?? 0),
                delayed: (int) ($row->delayed ?? 0),
                running: (int) ($row->running ?? 0),
                stuck: (int) ($row->stuck ?? 0),
                paused: $this->pauser->isPaused($connection, $queue),
            );
        });
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
