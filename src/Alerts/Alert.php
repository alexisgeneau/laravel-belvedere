<?php

declare(strict_types=1);

namespace Belvedere\Alerts;

use Illuminate\Notifications\Notification;

final readonly class Alert
{
    public function __construct(
        public string $fingerprint,
        public string $message,
        public Notification $notification,
    ) {}
}
