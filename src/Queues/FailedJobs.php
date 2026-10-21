<?php

declare(strict_types=1);

namespace Belvedere\Queues;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Collection;

final readonly class FailedJobs
{
    public function __construct(
        private DatabaseManager $db,
        private Repository $config,
        private FailedJobProviderInterface $provider,
    ) {}

    /** @return Collection<int, FailedJob> */
    public function list(?string $connection, ?string $queue, ?string $name, ?string $search, int $limit, ?int $beforeRowId): Collection
    {
        return $this->filtered($connection, $queue, $name, $search)
            ->when($beforeRowId !== null, fn (Builder $query): Builder => $query->where('id', '<', $beforeRowId))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): FailedJob => $this->hydrate($row));
    }

    /** @return list<string> */
    public function ids(?string $connection, ?string $queue, ?string $name, ?string $search): array
    {
        return $this->filtered($connection, $queue, $name, $search)
            ->orderBy('id')
            ->get(['id', 'uuid'])
            ->map(fn (object $row): string => $this->identifiedByUuid() && isset($row->uuid) ? (string) $row->uuid : (string) $row->id)
            ->all();
    }

    public function count(?string $connection = null, ?string $queue = null): int
    {
        return $this->filtered($connection, $queue, null, null)->count();
    }

    public function find(string $id): ?FailedJob
    {
        $row = $this->provider->find($id);

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * @param  list<string>  $uuids
     * @return Collection<int, FailedJob>
     */
    public function findByUuids(array $uuids): Collection
    {
        if ($uuids === []) {
            return new Collection;
        }

        return $this->table()
            ->when(
                $this->identifiedByUuid(),
                fn (Builder $query): Builder => $query->whereIn('uuid', $uuids),
                fn (Builder $query): Builder => $query->where(function (Builder $query) use ($uuids): void {
                    foreach ($uuids as $uuid) {
                        $query->orWhere('payload', 'like', '%"uuid":"'.addcslashes($uuid, '%_\\').'"%');
                    }
                }),
            )
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $row): FailedJob => $this->hydrate($row));
    }

    public function forget(string $id): bool
    {
        return $this->provider->forget($id);
    }

    public function flush(): void
    {
        $this->provider->flush();
    }

    private function hydrate(object $row): FailedJob
    {
        return FailedJob::fromRow($row, $this->identifiedByUuid());
    }

    private function identifiedByUuid(): bool
    {
        return $this->provider instanceof DatabaseUuidFailedJobProvider;
    }

    private function filtered(?string $connection, ?string $queue, ?string $name, ?string $search): Builder
    {
        return $this->table()
            ->when($connection !== null, fn (Builder $query): Builder => $query->where('connection', $connection))
            ->when($queue !== null, fn (Builder $query): Builder => $query->where('queue', $queue))
            ->when($name !== null, fn (Builder $query): Builder => $query->where('payload', 'like', '%'.addcslashes($name, '%_\\').'%'))
            ->when($search !== null, fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';

                $query->where('uuid', 'like', $term)
                    ->orWhere('exception', 'like', $term)
                    ->orWhere('payload', 'like', $term);
            }));
    }

    private function table(): Builder
    {
        $database = $this->config->get('queue.failed.database');
        $table = (string) $this->config->get('queue.failed.table', 'failed_jobs');

        return $this->db->connection($database)->table($table);
    }
}
