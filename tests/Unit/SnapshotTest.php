<?php

declare(strict_types=1);

use Belvedere\Enums\JobStatus;
use Belvedere\Support\Redactor;
use Belvedere\Support\Snapshot;
use Belvedere\Tests\Fixtures\Jobs\SecretJob;
use Belvedere\Tests\Fixtures\Jobs\SucceedingJob;
use Belvedere\Tests\Fixtures\Product;
use Illuminate\Support\Carbon;

it('captures public properties with models, enums, dates and collections normalized', function (): void {
    $product = new Product(['name' => 'Lamp']);
    $product->id = 7;

    $job = new class($product)
    {
        public JobStatus $status = JobStatus::Failed;

        public Carbon $when;

        public function __construct(public Product $product, public array $items = ['a', ['b' => new stdClass]])
        {
            $this->when = Carbon::create(2026, 10, 21, 9, 30, 0, 'UTC');
        }
    };

    $snapshot = (new Snapshot(new Redactor([]), 16384))->of($job);

    expect($snapshot)->toBe([
        'status' => 'failed',
        'when' => '2026-10-21T09:30:00+00:00',
        'product' => Product::class.':7',
        'items' => ['a', ['b' => stdClass::class]],
    ]);
});

it('redacts hidden keys', function (): void {
    $snapshot = (new Snapshot(new Redactor(['password', 'token']), 16384))->of(new SecretJob);

    expect($snapshot)->toBe([
        'password' => Redactor::PLACEHOLDER,
        'apiToken' => Redactor::PLACEHOLDER,
        'email' => 'jane@example.com',
    ]);
});

it('keeps only the keys when the snapshot exceeds the size limit', function (): void {
    $snapshot = (new Snapshot(new Redactor([]), 10))->of(new SucceedingJob('a very long label'));

    expect($snapshot)->toBe(['_truncated' => true, 'keys' => ['label', 'product']]);
});
