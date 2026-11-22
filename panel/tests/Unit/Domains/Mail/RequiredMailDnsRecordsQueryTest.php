<?php

declare(strict_types=1);

use App\Domains\Mail\Models\MailDkimKey;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\Queries\RequiredMailDnsRecordsQuery;

it('builds all required mail DNS records and auto-splits RSA-2048 DKIM TXT into 255-char chunks', function () {
    $mailDomain = new MailDomain([
        'domain' => 'example.com',
    ]);

    $longPublicKey = 'v=DKIM1; k=rsa; p='.str_repeat('A', 392);
    $activeKey = new MailDkimKey([
        'selector' => 'lc2026',
        'algorithm' => 'rsa2048',
        'public_key_txt' => $longPublicKey,
        'status' => 'active',
    ]);

    $query = new RequiredMailDnsRecordsQuery;
    $records = $query->execute(
        mailDomain: $mailDomain,
        serverIpv4: '203.0.113.10',
        serverIpv6: '2001:db8::10',
        activeDkimKey: $activeKey,
    );

    $byPurpose = collect($records)->keyBy('purpose');

    expect($byPurpose->has('Mail Host (IPv4)'))->toBeTrue();
    expect($byPurpose['Mail Host (IPv4)']['name'])->toBe('mail.example.com');
    expect($byPurpose['Mail Host (IPv4)']['value'])->toBe('203.0.113.10');

    expect($byPurpose->has('Mail Host (IPv6)'))->toBeTrue();
    expect($byPurpose['Mail Host (IPv6)']['type'])->toBe('AAAA');

    expect($byPurpose['Mail Exchanger (MX)']['type'])->toBe('MX');
    expect($byPurpose['Mail Exchanger (MX)']['priority'])->toBe(10);

    expect($byPurpose['SPF']['value'])->toContain('v=spf1');
    expect($byPurpose['DMARC']['name'])->toBe('_dmarc.example.com');
    expect($byPurpose['Autoconfig (Thunderbird)']['name'])->toBe('autoconfig.example.com');
    expect($byPurpose['Autodiscover (Outlook)']['name'])->toBe('autodiscover.example.com');
    expect($byPurpose['MTA-STS']['name'])->toBe('_mta-sts.example.com');
    expect($byPurpose['TLS-RPT']['name'])->toBe('_smtp._tls.example.com');

    expect($byPurpose->has('DKIM'))->toBeTrue();
    expect($byPurpose['DKIM']['name'])->toBe('lc2026._domainkey.example.com');
    expect($byPurpose['DKIM']['chunks'])->toHaveCount(2);
    expect(strlen($byPurpose['DKIM']['chunks'][0]))->toBe(255);
    expect(implode('', $byPurpose['DKIM']['chunks']))->toBe($longPublicKey);
});
