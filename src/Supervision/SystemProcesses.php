<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

use Closure;

/**
 * The `queue:work` processes of this machine, found by the `--name` the
 * supervisors give them: what `ps` knows that the tables may not, after a kill.
 */
final class SystemProcesses
{
    private const MAX_ANCESTRY = 64;

    /** @var Closure(string): list<string> */
    private readonly Closure $exec;

    /** @param  (Closure(string): list<string>)|null  $exec  Runs a shell command and returns its lines. */
    public function __construct(?Closure $exec = null)
    {
        $this->exec = $exec ?? static function (string $command): array {
            exec($command, $lines);

            return array_map(strval(...), $lines);
        };
    }

    /**
     * The workers whose `--name` starts with the prefix: pid => name.
     *
     * @return array<int, string>
     */
    public function workersNamed(string $prefix): array
    {
        return array_map(static fn (array $worker): string => $worker['name'], $this->scan($prefix)['workers']);
    }

    public function countWorkersNamed(string $name): int
    {
        return count(array_filter($this->workersNamed($name), static fn (string $value): bool => $value === $name));
    }

    /**
     * The workers whose `--name` starts with the prefix and that none of the given
     * supervisors started: their supervisor is gone, or it is a new process of the same
     * name and they are not among its descendants. pid => name.
     *
     * @param  array<string, int>  $supervisors  name => pid of the supervisors alive
     * @return array<int, string>
     */
    public function orphansNamed(string $prefix, array $supervisors): array
    {
        ['workers' => $workers, 'parents' => $parents] = $this->scan($prefix);

        $orphans = [];

        foreach ($workers as $pid => ['name' => $name]) {
            if (! isset($supervisors[$name]) || ! $this->descends($pid, $supervisors[$name], $parents)) {
                $orphans[$pid] = $name;
            }
        }

        return $orphans;
    }

    public function isAlive(int $pid): bool
    {
        return $pid > 0 && function_exists('posix_kill') && posix_kill($pid, 0);
    }

    /**
     * @return array{workers: array<int, array{name: string}>, parents: array<int, int>}
     */
    private function scan(string $prefix): array
    {
        $workers = [];
        $parents = [];

        foreach (($this->exec)('ps -e -ww -o pid=,ppid=,args=') as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+(.*)$/', $line, $parts) !== 1) {
                continue;
            }

            $parents[(int) $parts[1]] = (int) $parts[2];

            if (! str_contains($parts[3], 'queue:work') || preg_match('/--name[= ](?:\'([^\']*)\'|"([^"]*)"|(\S+))/', $parts[3], $name) !== 1) {
                continue;
            }

            $value = $name[3] ?? $name[2] ?? $name[1];

            if ($value !== '' && str_starts_with($value, $prefix)) {
                $workers[(int) $parts[1]] = ['name' => $value];
            }
        }

        return ['workers' => $workers, 'parents' => $parents];
    }

    /** @param  array<int, int>  $parents */
    private function descends(int $pid, int $ancestor, array $parents): bool
    {
        for ($depth = 0; $depth < self::MAX_ANCESTRY && isset($parents[$pid]); $depth++) {
            $pid = $parents[$pid];

            if ($pid === $ancestor) {
                return true;
            }
        }

        return false;
    }
}
