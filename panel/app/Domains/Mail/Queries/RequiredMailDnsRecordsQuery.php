<?php

declare(strict_types=1);

namespace App\Domains\Mail\Queries;

use App\Domains\Mail\Models\MailDkimKey;
use App\Domains\Mail\Models\MailDomain;

final class RequiredMailDnsRecordsQuery
{
    /**
     * Return all required DNS records for a mail domain, including auto-chunked DKIM TXT records.
     *
     * @return array<int, array{
     *     purpose: string,
     *     type: string,
     *     name: string,
     *     value: string,
     *     chunks?: array<int, string>,
     *     priority?: int,
     *     ttl: int
     * }>
     */
    public function execute(
        MailDomain $mailDomain,
        ?string $serverIpv4 = null,
        ?string $serverIpv6 = null,
        ?MailDkimKey $activeDkimKey = null,
    ): array {
        $domain = $mailDomain->domain;
        $mailHost = $mailDomain->mailHost();
        $ipv4 = $serverIpv4 !== null && trim($serverIpv4) !== '' ? trim($serverIpv4) : '<SERVER_IPV4>';

        $records = [];

        if (! $mailDomain->uses_shared_mail_host) {
            $records[] = [
                'purpose' => 'Mail Host (IPv4)',
                'type' => 'A',
                'name' => $mailHost,
                'value' => $ipv4,
                'ttl' => 3600,
            ];

            if ($serverIpv6 !== null && trim($serverIpv6) !== '') {
                $records[] = [
                    'purpose' => 'Mail Host (IPv6)',
                    'type' => 'AAAA',
                    'name' => $mailHost,
                    'value' => trim($serverIpv6),
                    'ttl' => 3600,
                ];
            }
        }

        $records[] = [
            'purpose' => 'Mail Exchanger (MX)',
            'type' => 'MX',
            'name' => $domain,
            'value' => "{$mailHost}.",
            'priority' => 10,
            'ttl' => 3600,
        ];

        $records[] = [
            'purpose' => 'SPF',
            'type' => 'TXT',
            'name' => $domain,
            'value' => "v=spf1 mx a:{$mailHost} ~all",
            'ttl' => 3600,
        ];

        $dkimKey = $activeDkimKey ?? $mailDomain->dkimKeys()
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if ($dkimKey !== null && is_string($dkimKey->public_key_txt) && $dkimKey->public_key_txt !== '') {
            $chunks = self::splitTxtChunks($dkimKey->public_key_txt);
            $records[] = [
                'purpose' => 'DKIM',
                'type' => 'TXT',
                'name' => "{$dkimKey->selector}._domainkey.{$domain}",
                'value' => $dkimKey->public_key_txt,
                'chunks' => $chunks,
                'ttl' => 3600,
            ];
        }

        $records[] = [
            'purpose' => 'DMARC',
            'type' => 'TXT',
            'name' => "_dmarc.{$domain}",
            'value' => "v=DMARC1; p=quarantine; rua=mailto:postmaster@{$domain}; fo=1",
            'ttl' => 3600,
        ];

        $records[] = [
            'purpose' => 'Autoconfig (Thunderbird)',
            'type' => 'CNAME',
            'name' => "autoconfig.{$domain}",
            'value' => "{$mailHost}.",
            'ttl' => 3600,
        ];

        $records[] = [
            'purpose' => 'Autodiscover (Outlook)',
            'type' => 'CNAME',
            'name' => "autodiscover.{$domain}",
            'value' => "{$mailHost}.",
            'ttl' => 3600,
        ];

        $mtaStsId = $mailDomain->updated_at?->format('YmdHis') ?? '20260101000000';
        $records[] = [
            'purpose' => 'MTA-STS',
            'type' => 'TXT',
            'name' => "_mta-sts.{$domain}",
            'value' => "v=STSv1; id={$mtaStsId}",
            'ttl' => 3600,
        ];

        $records[] = [
            'purpose' => 'TLS-RPT',
            'type' => 'TXT',
            'name' => "_smtp._tls.{$domain}",
            'value' => "v=TLSRPTv1; rua=mailto:postmaster@{$domain}",
            'ttl' => 3600,
        ];

        return $records;
    }

    /**
     * Split a TXT record value into DNS-safe chunks of at most $chunkSize bytes (default 255).
     *
     * @return array<int, string>
     */
    public static function splitTxtChunks(string $value, int $chunkSize = 255): array
    {
        if ($value === '') {
            return [];
        }

        return str_split($value, max(1, $chunkSize));
    }
}
