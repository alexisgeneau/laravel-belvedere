<?php

declare(strict_types=1);

use Belvedere\Support\Tags;
use Belvedere\Tests\Fixtures\Jobs\SucceedingJob;
use Belvedere\Tests\Fixtures\Jobs\TaggedJob;
use Belvedere\Tests\Fixtures\Product;
use Illuminate\Events\CallQueuedListener;

it('uses the explicit tags method when present', function (): void {
    expect(Tags::of(new TaggedJob))->toBe(['reports', 'tenant:42']);
});

it('derives tags from the eloquent models held in public properties', function (): void {
    $product = new Product(['name' => 'Lamp']);
    $product->id = 3;

    expect(Tags::of(new SucceedingJob('x', $product)))->toBe([Product::class.':3']);
});

it('looks through queued listeners to the event they handle', function (): void {
    $product = new Product(['name' => 'Lamp']);
    $product->id = 9;

    $event = new class($product)
    {
        public function __construct(public Product $product) {}
    };

    $listener = new CallQueuedListener('App\Listeners\Anything', 'handle', [$event]);

    expect(Tags::of($listener))->toBe([Product::class.':9']);
});

it('returns no tags for jobs without models', function (): void {
    expect(Tags::of(new SucceedingJob))->toBe([]);
});
