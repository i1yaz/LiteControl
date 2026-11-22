<?php

declare(strict_types=1);

use App\Domains\Mail\ValueObjects\SpamPolicy;

it('constructs a valid spam policy', function (): void {
    $policy = new SpamPolicy(true, 6.0, 15.0);

    expect($policy->enabled)->toBeTrue()
        ->and($policy->headerScore)->toBe(6.0)
        ->and($policy->rejectScore)->toBe(15.0);
});

it('rejects non-positive header score', function (): void {
    new SpamPolicy(true, 0.0, 15.0);
})->throws(InvalidArgumentException::class);

it('rejects reject score less than or equal to header score', function (): void {
    new SpamPolicy(true, 10.0, 10.0);
})->throws(InvalidArgumentException::class);

it('rejects scores above 100', function (): void {
    new SpamPolicy(true, 6.0, 101.0);
})->throws(InvalidArgumentException::class);
