<?php

declare(strict_types=1);

use App\Domains\Identity\ValueObjects\PhpCliVersion;

test('valid php cli versions are accepted and converted to lsphp format', function (string $input, string $expectedLsphp) {
    $vo = new PhpCliVersion($input);

    expect($vo->value)->toBe($input)
        ->and((string) $vo)->toBe($input)
        ->and($vo->toLsphpVersion())->toBe($expectedLsphp);
})->with([
    ['8.3', '83'],
    ['7.4', '74'],
    ['5.6', '56'],
    ['9.0', '90'],
]);

test('invalid php cli versions throw InvalidArgumentException', function (string $invalid) {
    expect(fn () => new PhpCliVersion($invalid))->toThrow(InvalidArgumentException::class);
})->with([
    '',
    '83',
    '8.3.1',
    '../',
    '8.3;id',
    '4.9',
    '8.a',
]);
