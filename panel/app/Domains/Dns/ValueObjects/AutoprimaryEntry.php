<?php

declare(strict_types=1);

namespace App\Domains\Dns\ValueObjects;

use InvalidArgumentException;

final readonly class AutoprimaryEntry
{
    public string $ip;

    public string $nameserver;

    public string $account;

    public function __construct(
        string $ip,
        string $nameserver,
        string $account = '',
        ZoneNameNormalizer $normalizer = new ZoneNameNormalizer,
    ) {
        $trimmedIp = trim($ip);
        if ($trimmedIp === '' || filter_var($trimmedIp, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException("Autoprimary IP address \"{$trimmedIp}\" is not a valid IPv4 or IPv6 address.");
        }

        $trimmedNs = trim($nameserver);
        if ($trimmedNs === '') {
            throw new InvalidArgumentException('Autoprimary nameserver FQDN is required.');
        }

        $normalizedNs = $normalizer->normalizeForwardZone($trimmedNs);

        $trimmedAccount = trim($account);
        if (strlen($trimmedAccount) > 40) {
            throw new InvalidArgumentException('Autoprimary account label cannot exceed 40 characters.');
        }
        if ($trimmedAccount !== '' && ! preg_match('/^[A-Za-z0-9._-]+$/', $trimmedAccount)) {
            throw new InvalidArgumentException('Autoprimary account label may only contain letters, digits, dots, underscores, and hyphens.');
        }

        $this->ip = $trimmedIp;
        $this->nameserver = $normalizedNs;
        $this->account = $trimmedAccount;
    }

    /**
     * @return array{ip: string, nameserver: string, account: string}
     */
    public function toPowerDnsPayload(): array
    {
        return [
            'ip' => $this->ip,
            'nameserver' => $this->nameserver,
            'account' => $this->account,
        ];
    }
}
