<?php

declare(strict_types=1);

namespace Belvedere\Collector;

final readonly class Sampler
{
    private int $rate;

    public function __construct(int $rate)
    {
        $this->rate = max(1, $rate);
    }

    public function accepts(string $uuid): bool
    {
        return $this->rate === 1 || crc32($uuid) % $this->rate === 0;
    }
}
