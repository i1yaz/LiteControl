<?php

declare(strict_types=1);

use App\Domains\Mail\ValueObjects\EmailAddress;

it('accepts valid email addresses including postmaster and abuse', function (string $input, string $expectedLocal, string $expectedDomain): void {
    $email = new EmailAddress($input);

    expect($email->localPart)->toBe($expectedLocal)
        ->and($email->domain)->toBe($expectedDomain)
        ->and($email->value)->toBe("{$expectedLocal}@{$expectedDomain}")
        ->and((string) $email)->toBe("{$expectedLocal}@{$expectedDomain}");
})->with([
    ['alice@acme-hosting.test', 'alice', 'acme-hosting.test'],
    ['  POSTMASTER@Acme-Hosting.COM  ', 'postmaster', 'acme-hosting.com'],
    ['abuse@tenant.org', 'abuse', 'tenant.org'],
    ['user.name+tag_1-2@sub.domain.co', 'user.name+tag_1-2', 'sub.domain.co'],
]);

it('rejects invalid local parts, path traversal, double dots, trailing dots, and mailer-daemon', function (string $invalid): void {
    expect(fn () => new EmailAddress($invalid))->toThrow(InvalidArgumentException::class);
})->with([
    'mailer-daemon@tenant.org',
    'MAILER-DAEMON@tenant.org',
    'foo..bar@tenant.org',
    'foo.@tenant.org',
    '.foo@tenant.org',
    '../etc@tenant.org',
    'foo/bar@tenant.org',
    'foo\\bar@tenant.org',
    '@tenant.org',
    'user@',
    'not-an-email',
    str_repeat('a', 65).'@tenant.org',
]);
