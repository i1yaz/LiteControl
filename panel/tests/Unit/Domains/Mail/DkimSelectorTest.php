<?php

declare(strict_types=1);

use App\Domains\Mail\ValueObjects\DkimSelector;

it('accepts valid DKIM selectors and normalizes to lowercase', function () {
    $selector = new DkimSelector('  LC-2026-Key1  ');

    expect($selector->value)->toBe('lc-2026-key1');
    expect((string) $selector)->toBe('lc-2026-key1');
    expect($selector->equals(new DkimSelector('lc-2026-key1')))->toBeTrue();
});

it('rejects invalid DKIM selectors', function (string $invalid) {
    expect(fn () => new DkimSelector($invalid))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'leading hyphen' => ['-lc2026'],
    'underscore' => ['lc_2026'],
    'dot' => ['lc.2026'],
    'slash' => ['../etc'],
    'too long (64 chars)' => [str_repeat('a', 64)],
]);
