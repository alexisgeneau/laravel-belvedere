<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

/**
 * The options of one supervisor: the keys of config/belvedere.php, resolved for an
 * environment, as Horizon's SupervisorOptions carries them.
 */
final readonly class SupervisorOptions
{
    public function __construct(
        public string $name,
        public string $connection,
        public string $queue,
        public string|false $balance = 'auto',
        public string $autoScalingStrategy = 'time',
        public int $minProcesses = 1,
        public int $maxProcesses = 1,
        public int $balanceMaxShift = 1,
        public int $balanceCooldown = 3,
        public int $memory = 128,
        public int $tries = 1,
        public int $timeout = 60,
        public string $backoff = '0',
        public int $maxTime = 0,
        public int $maxJobs = 0,
        public int $sleep = 3,
        public int $rest = 0,
        public bool $force = false,
        public int $nice = 0,
        public int $parentId = 0,
        public ?string $directory = null,
    ) {}

    public function withQueue(string $queue): self
    {
        return self::fromArray([...$this->toArray(), 'queue' => $queue]);
    }

    /** @return list<string> */
    public function queues(): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $this->queue)), static fn (string $queue): bool => $queue !== ''));
    }

    /** One pool per queue, scaled between them. */
    public function balancing(): bool
    {
        return in_array($this->balance, ['auto', 'simple'], true);
    }

    /** The number of processes follows the load. */
    public function autoScaling(): bool
    {
        return $this->balance !== 'simple';
    }

    public function autoScalesBySize(): bool
    {
        return $this->autoScalingStrategy === 'size';
    }

    /** The configuration key, without the master's name. */
    public function shortName(): string
    {
        $position = strrpos($this->name, ':');

        return $position === false ? $this->name : substr($this->name, $position + 1);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'balance' => $this->balance,
            'autoScalingStrategy' => $this->autoScalingStrategy,
            'minProcesses' => $this->minProcesses,
            'maxProcesses' => $this->maxProcesses,
            'balanceMaxShift' => $this->balanceMaxShift,
            'balanceCooldown' => $this->balanceCooldown,
            'memory' => $this->memory,
            'tries' => $this->tries,
            'timeout' => $this->timeout,
            'backoff' => $this->backoff,
            'maxTime' => $this->maxTime,
            'maxJobs' => $this->maxJobs,
            'sleep' => $this->sleep,
            'rest' => $this->rest,
            'force' => $this->force,
            'nice' => $this->nice,
            'parentId' => $this->parentId,
            'directory' => $this->directory,
        ];
    }

    /** @param  array<string, mixed>  $options */
    public static function fromArray(array $options): self
    {
        return new self(
            name: (string) $options['name'],
            connection: (string) $options['connection'],
            queue: (string) ($options['queue'] ?? 'default'),
            balance: self::balanceFrom($options['balance'] ?? 'auto'),
            autoScalingStrategy: (string) ($options['autoScalingStrategy'] ?? 'time'),
            minProcesses: (int) ($options['minProcesses'] ?? 1),
            maxProcesses: (int) ($options['maxProcesses'] ?? 1),
            balanceMaxShift: (int) ($options['balanceMaxShift'] ?? 1),
            balanceCooldown: (int) ($options['balanceCooldown'] ?? 3),
            memory: (int) ($options['memory'] ?? 128),
            tries: (int) ($options['tries'] ?? 1),
            timeout: (int) ($options['timeout'] ?? 60),
            backoff: (string) ($options['backoff'] ?? '0'),
            maxTime: (int) ($options['maxTime'] ?? 0),
            maxJobs: (int) ($options['maxJobs'] ?? 0),
            sleep: (int) ($options['sleep'] ?? 3),
            rest: (int) ($options['rest'] ?? 0),
            force: (bool) ($options['force'] ?? false),
            nice: (int) ($options['nice'] ?? 0),
            parentId: (int) ($options['parentId'] ?? 0),
            directory: isset($options['directory']) ? (string) $options['directory'] : null,
        );
    }

    public static function balanceFrom(mixed $value): string|false
    {
        return match (true) {
            $value === false, $value === 'false', $value === 'off', $value === null => false,
            default => (string) $value,
        };
    }
}
