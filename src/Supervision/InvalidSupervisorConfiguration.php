<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

use InvalidArgumentException;

final class InvalidSupervisorConfiguration extends InvalidArgumentException
{
    public static function key(string $environment, string $supervisor, string $key, string $problem): self
    {
        return new self("belvedere.supervisor.environments.{$environment}.{$supervisor}.{$key} {$problem}");
    }
}
