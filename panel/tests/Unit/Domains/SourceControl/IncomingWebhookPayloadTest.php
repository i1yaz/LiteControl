<?php

declare(strict_types=1);

use App\Domains\SourceControl\ValueObjects\IncomingWebhookPayload;

it('verifies valid sha256 webhook signatures and rejects invalid or missing ones', function () {
    $rawBody = '{"zen":"Keep it logically awesome."}';
    $secret = 'whsec_test_secret_123';
    $validSig = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

    $valid = new IncomingWebhookPayload(
        event: 'ping',
        deliveryId: 'delivery-1',
        signature: $validSig,
        rawBody: $rawBody,
        payload: ['zen' => 'Keep it logically awesome.'],
    );

    expect($valid->verifySignature($secret))->toBeTrue()
        ->and($valid->verifySignature('wrong-secret'))->toBeFalse()
        ->and($valid->verifySignature(''))->toBeFalse();

    $missingPrefix = new IncomingWebhookPayload(
        event: 'ping',
        deliveryId: 'delivery-2',
        signature: hash_hmac('sha256', $rawBody, $secret),
        rawBody: $rawBody,
        payload: [],
    );

    expect($missingPrefix->verifySignature($secret))->toBeFalse();

    $unsigned = new IncomingWebhookPayload(
        event: 'ping',
        deliveryId: 'delivery-3',
        signature: '',
        rawBody: $rawBody,
        payload: [],
    );

    expect($unsigned->verifySignature($secret))->toBeFalse();
});
