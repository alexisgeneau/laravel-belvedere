<?php

declare(strict_types=1);

namespace Belvedere\Support;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Stringable;
use Traversable;
use UnitEnum;

final readonly class Snapshot
{
    private const MAX_DEPTH = 3;

    private const MAX_ITEMS = 50;

    public function __construct(
        private Redactor $redactor,
        private int $maxBytes,
    ) {}

    /** @return array<string, mixed> */
    public function of(object $job): array
    {
        $values = [];

        foreach (FrameworkProperties::ownPublicProperties($job) as $property) {
            $values[$property->getName()] = $this->normalize($property->getValue($job), 0);
        }

        $values = $this->redactor->redact($values);

        $json = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($json === false || strlen($json) > $this->maxBytes) {
            return ['_truncated' => true, 'keys' => array_keys($values)];
        }

        return $values;
    }

    private function normalize(mixed $value, int $depth): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            return is_scalar($value) || $value === null ? $value : '…';
        }

        return match (true) {
            $value instanceof Model => $value::class.':'.$value->getKey(),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            is_array($value), $value instanceof Traversable => $this->normalizeIterable($value, $depth),
            $value instanceof Stringable => (string) $value,
            is_object($value) => $value::class,
            is_resource($value) => 'resource',
            default => $value,
        };
    }

    /**
     * @param  iterable<mixed>  $values
     * @return array<array-key, mixed>
     */
    private function normalizeIterable(iterable $values, int $depth): array
    {
        $normalized = [];
        $count = 0;

        foreach ($values as $key => $value) {
            if (++$count > self::MAX_ITEMS) {
                $normalized['…'] = '…';

                break;
            }

            $normalized[$key] = $this->normalize($value, $depth + 1);
        }

        return $normalized;
    }
}
