<?php

declare(strict_types=1);

namespace Belvedere\Queues;

use Illuminate\Contracts\Cache\Repository as Cache;

final class QueuePauser
{
    private const KEY = 'belvedere:paused-queues';

    private const MEMO_SECONDS = 2;

    /** @var list<string>|null */
    private ?array $memo = null;

    private float $memoizedAt = 0.0;

    public function __construct(private readonly Cache $cache) {}

    public function pause(string $connection, string $queue): void
    {
        $this->store(array_values(array_unique([...$this->all(), self::key($connection, $queue)])));
    }

    public function resume(string $connection, string $queue): void
    {
        $key = self::key($connection, $queue);

        $this->store(array_values(array_filter($this->all(), static fn (string $paused): bool => $paused !== $key)));
    }

    public function isPaused(string $connection, string $queue): bool
    {
        return in_array(self::key($connection, $queue), $this->all(), true);
    }

    public function shouldSleep(string $connection, string $queues): bool
    {
        $names = array_values(array_filter(array_map(trim(...), explode(',', $queues))));

        if ($names === []) {
            return false;
        }

        foreach ($names as $name) {
            if (! $this->isPaused($connection, $name)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public function paused(): array
    {
        return $this->all();
    }

    /** @return list<string> */
    private function all(): array
    {
        if ($this->memo !== null && microtime(true) - $this->memoizedAt < self::MEMO_SECONDS) {
            return $this->memo;
        }

        $this->memo = array_values(array_filter((array) $this->cache->get(self::KEY, []), is_string(...)));
        $this->memoizedAt = microtime(true);

        return $this->memo;
    }

    /** @param  list<string>  $keys */
    private function store(array $keys): void
    {
        $this->cache->forever(self::KEY, $keys);
        $this->memo = $keys;
        $this->memoizedAt = microtime(true);
    }

    private static function key(string $connection, string $queue): string
    {
        return $connection.':'.$queue;
    }
}
