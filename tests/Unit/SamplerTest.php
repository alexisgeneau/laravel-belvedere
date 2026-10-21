<?php

declare(strict_types=1);

use Belvedere\Collector\Sampler;

it('accepts every job at rate one', function (): void {
    $sampler = new Sampler(1);

    expect($sampler->accepts('a'))->toBeTrue()
        ->and($sampler->accepts('b'))->toBeTrue();
});

it('is deterministic for a given uuid', function (): void {
    $sampler = new Sampler(4);
    $uuids = array_map(static fn (int $i): string => "uuid-{$i}", range(1, 200));
    $accepted = array_filter($uuids, $sampler->accepts(...));

    expect(count($accepted))->toBeGreaterThan(20)->toBeLessThan(80);

    foreach ($accepted as $uuid) {
        expect($sampler->accepts($uuid))->toBeTrue();
    }
});
