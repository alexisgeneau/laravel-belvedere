<?php

declare(strict_types=1);

namespace Belvedere\Support;

final readonly class Redactor
{
    public const PLACEHOLDER = '[redacted]';

    /** @var list<string> */
    private array $keys;

    /** @param list<string> $keys */
    public function __construct(array $keys)
    {
        $this->keys = array_map(strtolower(...), $keys);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isHidden($key)) {
                $values[$key] = self::PLACEHOLDER;

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }

    private function isHidden(string $key): bool
    {
        $key = strtolower($key);

        foreach ($this->keys as $hidden) {
            if (str_contains($key, $hidden)) {
                return true;
            }
        }

        return false;
    }
}
