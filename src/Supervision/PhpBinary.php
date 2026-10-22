<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

use Symfony\Component\Process\PhpExecutableFinder;

/**
 * The PHP binary the master and the supervisors start their children with.
 * BELVEDERE_PHP overrides the detection for the setups where PHP_BINARY is not
 * a plain CLI (FrankenPHP in CLI mode, for instance: `frankenphp php-cli`).
 */
final class PhpBinary
{
    private static ?string $path = null;

    public static function path(): string
    {
        if (self::$path !== null) {
            return self::$path;
        }

        $override = getenv('BELVEDERE_PHP');

        if (is_string($override) && trim($override) !== '') {
            return self::$path = trim($override);
        }

        $found = (new PhpExecutableFinder)->find(false);

        return self::$path = $found === false ? 'php' : $found;
    }

    public static function use(?string $path): void
    {
        self::$path = $path;
    }
}
