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

it('resets attempts and drops the retry deadline when prepared for a retry', function (): void {
    $retry = Payload::fromJson('{"uuid":"u","attempts":3,"retryUntil":42}')->forRetry();

    expect($retry->data)->toBe(['uuid' => 'u', 'attempts' => 0])
        ->and($retry->toJson())->toBe('{"uuid":"u","attempts":0}');
});

it('returns null for malformed json', function (): void {
    expect(Payload::tryFromJson('{not json'))->toBeNull();
});
