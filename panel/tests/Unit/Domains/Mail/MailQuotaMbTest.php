<?php

declare(strict_types=1);

use App\Domains\Mail\ValueObjects\MailQuotaMb;

it('accepts zero and positive quotas', function (): void {
    $unlimited = new MailQuotaMb(0);
    $fixed = new MailQuotaMb(1024);

    expect($unlimited->value)->toBe(0)
        ->and($unlimited->isUnlimited())->toBeTrue()
        ->and($fixed->value)->toBe(1024)
        ->and($fixed->isUnlimited())->toBeFalse();
});

it('rejects negative quotas', function (): void {
    expect(fn () => new MailQuotaMb(-1))->toThrow(InvalidArgumentException::class);
});
