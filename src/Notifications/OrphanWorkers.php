<?php

declare(strict_types=1);

namespace Belvedere\Notifications;

final class OrphanWorkers extends AlertNotification
{
    public function __construct(
        public readonly string $hostname,
        public readonly int $count,
    ) {}

    public function title(): string
    {
        return "{$this->count} orphan worker".($this->count > 1 ? 's' : '')." on {$this->hostname}";
    }

    public function lines(): array
    {
        return [
            "{$this->count} worker".($this->count > 1 ? 's' : '')." on [{$this->hostname}] still report".($this->count > 1 ? '' : 's').' under a supervisor that no longer runs them: they process jobs, but nobody restarts or scales them.',
            'Run `php artisan belvedere:orphans --kill` on that host to stop them; the supervisors start fresh ones when needed.',
        ];
    }
}
