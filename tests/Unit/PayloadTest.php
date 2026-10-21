<?php

declare(strict_types=1);

use Belvedere\Support\Payload;

it('exposes the job identity from the payload', function (): void {
    $payload = Payload::fromJson(json_encode([
        'uuid' => 'abc-123',
        'displayName' => 'App\Jobs\Ship',
        'attempts' => 2,
        'maxTries' => 5,
        'timeout' => 30,
        'retryUntil' => 1_900_000_000,
        'data' => ['commandName' => 'App\Jobs\Ship', 'command' => 'O:1:"x":0:{}'],
    ]));

    expect($payload->uuid())->toBe('abc-123')
        ->and($payload->displayName())->toBe('App\Jobs\Ship')
        ->and($payload->attempts())->toBe(2)
        ->and($payload->maxTries())->toBe(5)
        ->and($payload->timeout())->toBe(30)
        ->and($payload->commandName())->toBe('App\Jobs\Ship');
});

it('resets attempts, drops the retry deadline and stamps the dispatch time when prepared for a retry', function (): void {
    $retry = Payload::fromJson('{"uuid":"u","attempts":3,"retryUntil":42,"belvedere":{"tags":["a"],"queued_at":1}}')->forRetry();

    expect($retry->attempts())->toBe(0)
        ->and($retry->data)->not->toHaveKey('retryUntil')
        ->and($retry->tags())->toBe(['a'])
        ->and($retry->queuedAt()?->diffInSeconds(now(), true))->toBeLessThan(5)
        ->and(json_decode($retry->toJson(), true)['belvedere']['queued_at'])->toBeFloat();
});

it('reads the dispatch time stored in the payload metadata', function (): void {
    expect(Payload::fromJson('{"uuid":"u","belvedere":{"queued_at":1700000000.25}}')->queuedAt()?->format('U.u'))->toBe('1700000000.250000')
        ->and(Payload::fromJson('{"uuid":"u"}')->queuedAt())->toBeNull();
});

it('returns null for malformed json', function (): void {
    expect(Payload::tryFromJson('{not json'))->toBeNull();
});
