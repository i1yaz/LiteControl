<?php

declare(strict_types=1);

use App\Domains\Mail\ValueObjects\DnsTxtVerificationToken;

it('generates deterministic user-and-domain scoped DNS TXT verification tokens', function () {
    $tokenA = DnsTxtVerificationToken::forUserAndDomain(42, 'Example.COM', 'test-secret');
    $tokenB = DnsTxtVerificationToken::forUserAndDomain(42, 'example.com', 'test-secret');
    $tokenOtherUser = DnsTxtVerificationToken::forUserAndDomain(43, 'example.com', 'test-secret');
    $tokenOtherDomain = DnsTxtVerificationToken::forUserAndDomain(42, 'other.com', 'test-secret');

    expect($tokenA->value)->toStartWith('lc-mail-verify=')
        ->and($tokenA->value)->toBe($tokenB->value)
        ->and($tokenA->value)->not->toBe($tokenOtherUser->value)
        ->and($tokenA->value)->not->toBe($tokenOtherDomain->value)
        ->and($tokenA->matches($tokenB->value))->toBeTrue()
        ->and($tokenA->matches('  '.$tokenB->value.'  '))->toBeTrue()
        ->and($tokenA->matches($tokenOtherUser->value))->toBeFalse();
});

it('builds the expected verification record name', function () {
    expect(DnsTxtVerificationToken::recordName('MailOnly.Example.com'))
        ->toBe('_lc-mail-verify.mailonly.example.com');
});

it('rejects malformed verification tokens', function () {
    expect(fn () => new DnsTxtVerificationToken('invalid-token'))
        ->toThrow(InvalidArgumentException::class);
});
