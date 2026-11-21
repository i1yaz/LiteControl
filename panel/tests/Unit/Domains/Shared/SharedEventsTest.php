<?php

declare(strict_types=1);

use App\Domains\Shared\Events\MailDnsRecordsChanged;
use App\Domains\Shared\Events\SslCertificateIssued;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class);

it('constructs SslCertificateIssued with primitive payloads only', function () {
    $event = new SslCertificateIssued(
        domain: 'example.com',
        certPath: '/etc/letsencrypt/live/example.com/fullchain.pem',
        keyPath: '/etc/letsencrypt/live/example.com/privkey.pem',
        expiresAt: 1800000000,
    );

    expect($event->domain)->toBe('example.com')
        ->and($event->certPath)->toBe('/etc/letsencrypt/live/example.com/fullchain.pem')
        ->and($event->keyPath)->toBe('/etc/letsencrypt/live/example.com/privkey.pem')
        ->and($event->expiresAt)->toBe(1800000000);
});

it('constructs MailDnsRecordsChanged with primitive payloads only', function () {
    $records = [
        ['type' => 'MX', 'name' => 'example.com', 'value' => 'mail.example.com', 'priority' => 10],
        ['type' => 'TXT', 'name' => 'example.com', 'value' => 'v=spf1 mx ~all'],
    ];

    $event = new MailDnsRecordsChanged(
        domain: 'example.com',
        records: $records,
    );

    expect($event->domain)->toBe('example.com')
        ->and($event->records)->toBe($records);
});

it('dispatches shared cross-domain events', function () {
    Event::fake([SslCertificateIssued::class, MailDnsRecordsChanged::class]);

    SslCertificateIssued::dispatch('example.com', '/tmp/cert.pem', '/tmp/key.pem', 1800000000);
    MailDnsRecordsChanged::dispatch('example.com', [['type' => 'MX', 'value' => 'mail.example.com']]);

    Event::assertDispatched(SslCertificateIssued::class, fn (SslCertificateIssued $e): bool => $e->domain === 'example.com');
    Event::assertDispatched(MailDnsRecordsChanged::class, fn (MailDnsRecordsChanged $e): bool => $e->domain === 'example.com');
});
