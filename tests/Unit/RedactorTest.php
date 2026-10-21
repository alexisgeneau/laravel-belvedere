<?php

declare(strict_types=1);

use Belvedere\Support\Redactor;

it('replaces hidden keys at every depth without touching other values', function (): void {
    $redactor = new Redactor(['password', 'token']);

    $redacted = $redactor->redact([
        'email' => 'jane@example.com',
        'password' => 'hunter2',
        'nested' => ['apiToken' => 'abc', 'kept' => 1],
        0 => 'password',
    ]);

    expect($redacted)->toBe([
        'email' => 'jane@example.com',
        'password' => Redactor::PLACEHOLDER,
        'nested' => ['apiToken' => Redactor::PLACEHOLDER, 'kept' => 1],
        0 => 'password',
    ]);
});

it('matches keys regardless of case', function (): void {
    $redacted = (new Redactor(['SECRET']))->redact(['client_secret' => 'x', 'Secret' => 'y']);

    expect($redacted)->toBe(['client_secret' => Redactor::PLACEHOLDER, 'Secret' => Redactor::PLACEHOLDER]);
});
