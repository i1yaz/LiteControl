<?php

namespace App\Domains\HostingPlans\ValueObjects;

use InvalidArgumentException;

final readonly class ResourceQuotas
{
    public function __construct(
        public int $diskQuotaMb,
        public int $bandwidthMb,
        public int $maxDomains,
        public int $maxSubdomains,
        public int $maxDatabases,
        public int $maxFtpAccounts,
        public int $maxEmailAccounts,
    ) {
        $this->assertRange($diskQuotaMb, 100, 1048576, 'disk_quota_mb');
        $this->assertRange($bandwidthMb, 100, 10485760, 'bandwidth_mb');
        $this->assertRange($maxDomains, 1, 1000, 'max_domains');
        $this->assertRange($maxSubdomains, 0, 1000, 'max_subdomains');
        $this->assertRange($maxDatabases, 0, 1000, 'max_databases');
        $this->assertRange($maxFtpAccounts, 0, 1000, 'max_ftp_accounts');
        $this->assertRange($maxEmailAccounts, 0, 1000, 'max_email_accounts');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            diskQuotaMb: (int) ($data['disk_quota_mb'] ?? 0),
            bandwidthMb: (int) ($data['bandwidth_mb'] ?? 0),
            maxDomains: (int) ($data['max_domains'] ?? 0),
            maxSubdomains: (int) ($data['max_subdomains'] ?? 0),
            maxDatabases: (int) ($data['max_databases'] ?? 0),
            maxFtpAccounts: (int) ($data['max_ftp_accounts'] ?? 0),
            maxEmailAccounts: (int) ($data['max_email_accounts'] ?? 0),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'disk_quota_mb' => $this->diskQuotaMb,
            'bandwidth_mb' => $this->bandwidthMb,
            'max_domains' => $this->maxDomains,
            'max_subdomains' => $this->maxSubdomains,
            'max_databases' => $this->maxDatabases,
            'max_ftp_accounts' => $this->maxFtpAccounts,
            'max_email_accounts' => $this->maxEmailAccounts,
        ];
    }

    private function assertRange(int $value, int $min, int $max, string $field): void
    {
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException("{$field} must be between {$min} and {$max}.");
        }
    }
}
