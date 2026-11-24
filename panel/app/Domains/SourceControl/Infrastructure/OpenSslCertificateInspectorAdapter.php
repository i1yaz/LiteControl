<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Infrastructure;

use App\Domains\SourceControl\Contracts\SslCertificateInspectorInterface;
use App\Domains\SourceControl\ValueObjects\SslCertificateInspectionResult;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;

class OpenSslCertificateInspectorAdapter implements SslCertificateInspectorInterface
{
    public function inspect(string $expectedHost, ?string $certPath = null): SslCertificateInspectionResult
    {
        $path = $certPath ?? (string) config('litecontrol.panel_ssl_cert_path', '/etc/litecontrol/ssl/fullchain.pem');

        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return SslCertificateInspectionResult::missing("Panel SSL certificate file not found or unreadable at [{$path}].");
        }

        $pemContent = @file_get_contents($path);
        if (! is_string($pemContent) || trim($pemContent) === '') {
            return SslCertificateInspectionResult::missing("Panel SSL certificate file at [{$path}] is empty.");
        }

        $pemBlocks = $this->extractPemCertificates($pemContent);
        if ($pemBlocks === []) {
            return SslCertificateInspectionResult::missing("No valid PEM certificate blocks found at [{$path}].");
        }

        $leafPem = $pemBlocks[0];
        $x509 = @openssl_x509_read($leafPem);
        if ($x509 === false) {
            return SslCertificateInspectionResult::missing("Unable to parse X.509 certificate at [{$path}].");
        }

        $parsed = @openssl_x509_parse($x509);
        if (! is_array($parsed)) {
            return SslCertificateInspectionResult::missing("Unable to decode X.509 certificate metadata at [{$path}].");
        }

        $validFrom = isset($parsed['validFrom_time_t']) && is_int($parsed['validFrom_time_t'])
            ? CarbonImmutable::createFromTimestampUTC($parsed['validFrom_time_t'])
            : null;
        $expiresAt = isset($parsed['validTo_time_t']) && is_int($parsed['validTo_time_t'])
            ? CarbonImmutable::createFromTimestampUTC($parsed['validTo_time_t'])
            : null;

        $subjectDn = $this->formatDn($parsed['subject'] ?? null);
        $issuerDn = $this->formatDn($parsed['issuer'] ?? null);
        $dnsNames = $this->extractDnsNames($parsed);

        $selfSigned = $this->isSelfSigned($x509, $parsed['subject'] ?? null, $parsed['issuer'] ?? null);
        $trusted = ! $selfSigned && $this->verifyAgainstSystemCa($leafPem, array_slice($pemBlocks, 1));

        return SslCertificateInspectionResult::found(
            selfSigned: $selfSigned,
            trusted: $trusted,
            validFrom: $validFrom,
            expiresAt: $expiresAt,
            dnsNames: $dnsNames,
            subject: $subjectDn,
            issuer: $issuerDn,
        );
    }

    /**
     * @return list<string>
     */
    private function extractPemCertificates(string $pemContent): array
    {
        if (! preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pemContent, $matches)) {
            return [];
        }

        return array_values($matches[0]);
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return list<string>
     */
    private function extractDnsNames(array $parsed): array
    {
        $names = [];

        $san = $parsed['extensions']['subjectAltName'] ?? null;
        if (is_string($san) && $san !== '') {
            foreach (explode(',', $san) as $entry) {
                $entry = trim($entry);
                if (stripos($entry, 'DNS:') === 0) {
                    $dns = trim(substr($entry, 4));
                    if ($dns !== '') {
                        $names[] = $dns;
                    }
                }
            }
        }

        // Fall back to Subject CN only when no SAN DNS entries are present (RFC 2818 / 6125).
        if ($names === []) {
            $cn = $parsed['subject']['CN'] ?? null;
            if (is_string($cn) && trim($cn) !== '') {
                $names[] = trim($cn);
            } elseif (is_array($cn)) {
                foreach ($cn as $value) {
                    if (is_string($value) && trim($value) !== '') {
                        $names[] = trim($value);
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  \OpenSSLCertificate  $x509
     */
    private function isSelfSigned($x509, mixed $subject, mixed $issuer): bool
    {
        if (is_array($subject) && is_array($issuer) && $this->normalizeDnArray($subject) === $this->normalizeDnArray($issuer)) {
            return true;
        }

        $publicKey = @openssl_pkey_get_public($x509);
        if ($publicKey !== false) {
            return @openssl_x509_verify($x509, $publicKey) === 1;
        }

        return false;
    }

    /**
     * @param  list<string>  $intermediates
     */
    private function verifyAgainstSystemCa(string $leafPem, array $intermediates): bool
    {
        $leafFile = tempnam(sys_get_temp_dir(), 'lc_ssl_leaf_');
        if ($leafFile === false) {
            return false;
        }

        $chainFile = null;

        try {
            file_put_contents($leafFile, $leafPem);

            $command = ['openssl', 'verify'];

            $caBundle = $this->resolveCaBundlePath();
            if ($caBundle !== null) {
                $command[] = '-CAfile';
                $command[] = $caBundle;
            }

            if ($intermediates !== []) {
                $chainFile = tempnam(sys_get_temp_dir(), 'lc_ssl_chain_');
                if ($chainFile !== false) {
                    file_put_contents($chainFile, implode("\n", $intermediates));
                    $command[] = '-untrusted';
                    $command[] = $chainFile;
                }
            }

            $command[] = $leafFile;

            $process = new Process($command);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        } finally {
            @unlink($leafFile);
            if (is_string($chainFile)) {
                @unlink($chainFile);
            }
        }
    }

    private function resolveCaBundlePath(): ?string
    {
        $configured = config('litecontrol.panel_ssl_ca_bundle_path');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        $candidates = [
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/cert.pem',
        ];

        $locations = openssl_get_cert_locations();
        if (is_string($locations['default_cert_file'] ?? null) && $locations['default_cert_file'] !== '') {
            $candidates[] = $locations['default_cert_file'];
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $dn
     * @return array<string, mixed>
     */
    private function normalizeDnArray(array $dn): array
    {
        ksort($dn);

        return array_map(
            static fn (mixed $value): mixed => is_string($value) ? strtolower(trim($value)) : $value,
            $dn,
        );
    }

    private function formatDn(mixed $dn): ?string
    {
        if (! is_array($dn) || $dn === []) {
            return null;
        }

        $parts = [];
        foreach ($dn as $key => $value) {
            if (is_array($value)) {
                $value = implode(',', array_map('strval', $value));
            }
            $parts[] = "{$key}=".(string) $value;
        }

        return implode(', ', $parts);
    }
}
