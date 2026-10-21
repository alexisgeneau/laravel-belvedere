<?php

declare(strict_types=1);

namespace Belvedere\Support;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use ReflectionClass;
use ReflectionProperty;

final class FrameworkProperties
{
    private const TRAITS = [Queueable::class, InteractsWithQueue::class, Batchable::class];

    /** @var list<string>|null */
    private static ?array $names = null;

    public static function contains(string $property): bool
    {
        return in_array($property, self::names(), true);
    }

    /** @return list<string> */
    private static function names(): array
    {
        if (self::$names !== null) {
            return self::$names;
        }

        $names = [];

        foreach (self::TRAITS as $trait) {
            foreach ((new ReflectionClass($trait))->getProperties() as $property) {
                $names[] = $property->getName();
            }
        }

        return self::$names = array_values(array_unique($names));
    }

    /** @return list<ReflectionProperty> */
    public static function ownPublicProperties(object $object): array
    {
        $properties = [];

        foreach ((new ReflectionClass($object))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || ! $property->isInitialized($object) || self::contains($property->getName())) {
                continue;
            }

            $properties[] = $property;
        }

        return $properties;
    }
}
