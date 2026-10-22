<?php

declare(strict_types=1);

namespace Belvedere\Supervision\Repositories;

use Belvedere\Support\Storage;
use Illuminate\Support\Carbon;

/**
 * The `belvedere_commands` table: the orders a master or a supervisor receives
 * from the dashboard or from another host, read and deleted at its heartbeat.
 * Signals do the same job faster on the host itself.
 */
final readonly class CommandQueue
{
    public const PAUSE = 'pause';

    public const CONTINUE = 'continue';

    public const RESTART = 'restart';

    public const TERMINATE = 'terminate';

    /** Orders older than this are stale: nobody waited for them. */
    private const EXPIRES_AFTER_MINUTES = 60;

    public function __construct(private Storage $storage) {}

    /** @param  array<string, mixed>  $payload */
    public function push(string $target, string $command, array $payload = []): void
    {
        $this->storage->query('commands')->insert([
            'target' => $target,
            'command' => $command,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * The orders waiting for a target, oldest first, removed as they are read.
     *
     * @return list<array{command: string, payload: array<string, mixed>}>
     */
    public function pending(string $target): array
    {
        $rows = $this->storage->query('commands')->where('target', $target)->orderBy('id')->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $this->storage->query('commands')->whereIn('id', $rows->pluck('id')->all())->delete();

        $fresh = Carbon::now()->subMinutes(self::EXPIRES_AFTER_MINUTES);
        $commands = [];

        foreach ($rows as $row) {
            if (Carbon::parse((string) $row->created_at)->lt($fresh)) {
                continue;
            }

            $payload = json_decode((string) ($row->payload ?? '{}'), true);

            $commands[] = ['command' => (string) $row->command, 'payload' => is_array($payload) ? $payload : []];
        }

        return $commands;
    }

    public function flush(string $target): void
    {
        $this->storage->query('commands')->where('target', $target)->delete();
    }
}
