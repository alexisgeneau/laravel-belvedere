<?php

declare(strict_types=1);

namespace Belvedere\Notifications;

use Illuminate\Support\Carbon;

final class SupervisorDown extends AlertNotification
{
    public function __construct(
        public readonly string $master,
        public readonly string $hostname,
        public readonly Carbon $lastSeenAt,
    ) {}

    public function title(): string
    {
        return "Supervisor down on {$this->hostname}";
    }

    public function lines(): array
    {
        return [
            "The master supervisor [{$this->master}] on [{$this->hostname}] has not reported since {$this->lastSeenAt->toDateTimeString()} UTC, and no other master took over on that host.",
            'Check the process monitor (Supervisor, systemd, Docker) that keeps `php artisan belvedere` running there.',
        ];
    }
}
