<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;

/**
 * The supervisors to start on a master, read from the `supervisor` section of
 * config/belvedere.php: `defaults` merged under every environment, the keys
 * converted and checked, the way Horizon's ProvisioningPlan does it.
 */
final class ProvisioningPlan
{
    /** @var array<string, array<string, SupervisorOptions>> environment => supervisor => options */
    private array $parsed = [];

    /** @var array<string, list<string>> environment => what the validation changed on its own */
    private array $warnings = [];

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $environments
     * @param  array<string, array<string, mixed>>  $defaults
     */
    public function __construct(
        private readonly string $master,
        array $environments,
        array $defaults,
        private readonly Repository $config,
        private readonly ?string $directory = null,
    ) {
        foreach ($environments as $environment => $supervisors) {
            foreach ((array) $supervisors as $supervisor => $options) {
                $merged = array_replace_recursive($defaults[$supervisor] ?? [], (array) $options);

                $this->parsed[(string) $environment][(string) $supervisor] = $this->convert((string) $environment, (string) $supervisor, $merged);
            }
        }
    }

    public static function fromConfig(Repository $config, string $master, ?string $directory = null): self
    {
        return new self(
            $master,
            (array) $config->get('belvedere.supervisor.environments', []),
            (array) $config->get('belvedere.supervisor.defaults', []),
            $config,
            $directory,
        );
    }

    /** @return list<string> */
    public function environments(): array
    {
        return array_keys($this->parsed);
    }

    /**
     * The supervisors of an environment: the exact name first, then Horizon's
     * wildcards (`production-*`, `*`), in the order of the configuration.
     *
     * @return array<string, SupervisorOptions>
     */
    public function forEnvironment(string $environment): array
    {
        $key = $this->keyFor($environment);

        return $key === null ? [] : $this->parsed[$key];
    }

    /**
     * What the validation changed on its own for an environment, to print at start.
     *
     * @return list<string>
     */
    public function warnings(string $environment): array
    {
        $key = $this->keyFor($environment);

        return $key === null ? [] : ($this->warnings[$key] ?? []);
    }

    private function keyFor(string $environment): ?string
    {
        if (isset($this->parsed[$environment])) {
            return $environment;
        }

        foreach (array_keys($this->parsed) as $pattern) {
            if (Str::is($pattern, $environment)) {
                return $pattern;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $options */
    private function convert(string $environment, string $supervisor, array $options): SupervisorOptions
    {
        $converted = [];

        foreach ($options as $key => $value) {
            $key = match ($key) {
                'processes' => 'maxProcesses',
                'max_tries' => 'tries',
                default => Str::camel((string) $key),
            };

            if (in_array($key, ['queue', 'backoff'], true) && is_array($value)) {
                $value = implode(',', $value);
            }

            $converted[$key] = $value;
        }

        $converted['name'] = "{$this->master}:{$supervisor}";
        $converted['parentId'] = (int) getmypid();
        $converted['directory'] = $this->directory;
        $converted['connection'] = (string) ($converted['connection'] ?? 'database');
        $converted['queue'] = (string) ($converted['queue'] ?? $this->config->get("queue.connections.{$converted['connection']}.queue", 'default'));

        $this->validate($environment, $supervisor, $converted);

        return SupervisorOptions::fromArray($converted);
    }

    /** @param  array<string, mixed>  $options */
    private function validate(string $environment, string $supervisor, array &$options): void
    {
        $fail = static fn (string $key, string $problem): never => throw InvalidSupervisorConfiguration::key($environment, $supervisor, $key, $problem);

        $connection = (string) $options['connection'];

        if (! $this->config->has("queue.connections.{$connection}")) {
            $fail('connection', "names a queue connection that does not exist: [{$connection}].");
        }

        $driver = (string) $this->config->get("queue.connections.{$connection}.driver");

        if ($driver !== 'database') {
            $fail('connection', "must use the database driver; [{$connection}] uses [{$driver}]. The supervisor reads the load from the jobs table.");
        }

        $balance = SupervisorOptions::balanceFrom($options['balance'] ?? 'auto');

        if (! in_array($balance, ['auto', 'simple', false], true)) {
            $fail('balance', 'must be "auto", "simple" or false.');
        }

        $strategy = (string) ($options['autoScalingStrategy'] ?? 'time');

        if (! in_array($strategy, ['time', 'size'], true)) {
            $fail('autoScalingStrategy', 'must be "time" or "size".');
        }

        $min = (int) ($options['minProcesses'] ?? 1);
        $max = (int) ($options['maxProcesses'] ?? 1);
        $queues = array_values(array_filter(array_map(trim(...), explode(',', (string) $options['queue']))));

        if ($queues === []) {
            $fail('queue', 'must name at least one queue.');
        }

        if ($min < 1) {
            $fail('minProcesses', 'must be at least 1.');
        }

        if ($max < 0) {
            $fail('maxProcesses', 'must be 0 (supervisor disabled) or more.');
        }

        $floor = $balance === false ? $min : $min * count($queues);

        if ($max > 0 && $max < $floor) {
            $fail('maxProcesses', "must be at least {$floor}: minProcesses ({$min}) for each of the ".count($queues).' queues.');
        }

        foreach (['balanceMaxShift' => 1, 'balanceCooldown' => 1, 'memory' => 1, 'tries' => 0, 'timeout' => 0, 'maxTime' => 0, 'maxJobs' => 0, 'sleep' => 0, 'rest' => 0] as $key => $minimum) {
            if (isset($options[$key]) && (int) $options[$key] < $minimum) {
                $fail($key, "must be at least {$minimum}.");
            }
        }

        if ($max > 1 && $this->usesSqlite($connection)) {
            $options['maxProcesses'] = 1;
            $options['minProcesses'] = 1;
            $pooled = count($queues) > 1 && $balance !== false;

            if ($pooled) {
                $options['balance'] = false;
            }

            $this->warnings[$environment][] = sprintf(
                '[%s] runs one process instead of %d%s: connection [%s] is SQLite, which Laravel\'s database queue cannot pop from several processes at once.',
                $supervisor,
                $max,
                $pooled ? ', on '.implode(',', $queues).' without balancing' : '',
                $connection,
            );
        }
    }

    private function usesSqlite(string $connection): bool
    {
        $database = $this->config->get("queue.connections.{$connection}.connection") ?? $this->config->get('database.default');

        return $this->config->get("database.connections.{$database}.driver") === 'sqlite';
    }
}
