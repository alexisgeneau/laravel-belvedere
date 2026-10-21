<?php

declare(strict_types=1);

namespace Belvedere\Support;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;

final class Tags
{
    /** @return list<string> */
    public static function of(object $job): array
    {
        $target = self::target($job);

        if (method_exists($target, 'tags')) {
            return array_values(array_map(strval(...), (array) $target->tags()));
        }

        $tags = [];

        foreach (self::modelsIn($target) as $model) {
            $tags[] = $model::class.':'.$model->getKey();
        }

        return array_values(array_unique($tags));
    }

    private static function target(object $job): object
    {
        return match (true) {
            $job instanceof BroadcastEvent => $job->event,
            $job instanceof SendQueuedMailable => $job->mailable,
            $job instanceof SendQueuedNotifications => $job->notification,
            $job instanceof CallQueuedListener => is_object($job->data[0] ?? null) ? $job->data[0] : $job,
            default => $job,
        };
    }

    /** @return list<Model> */
    private static function modelsIn(object $target): array
    {
        $models = [];

        foreach (FrameworkProperties::ownPublicProperties($target) as $property) {
            array_push($models, ...self::models($property->getValue($target)));
        }

        return $models;
    }

    /** @return list<Model> */
    private static function models(mixed $value): array
    {
        if ($value instanceof Model) {
            return [$value];
        }

        if ($value instanceof EloquentCollection) {
            return array_values($value->all());
        }

        if (is_array($value)) {
            return array_values(array_filter($value, static fn (mixed $item): bool => $item instanceof Model));
        }

        return [];
    }
}
