<?php

declare(strict_types=1);

namespace App\Domains\Mail\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\Shared\Events\SslCertificateIssued;
use App\Exceptions\System\LcCliException;
use Illuminate\Validation\ValidationException;

final class IssueStandaloneMailTlsAction
{
    public function __construct(
        private readonly MailServerDriverInterface $driver,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * Issue an HTTP-01 TLS certificate for standalone mail.{domain} via the catch-all
     * port-80 OpenLiteSpeed ACME challenge vhost and emit SslCertificateIssued so Phase 7
     * automatically syncs Postfix/Dovecot SNI.
     *
     * @return array<string, mixed>
     *
     * @throws LcCliException
     */
    public function execute(User $actor, MailDomain $mailDomain): array
    {
        if (! $mailDomain->active) {
            throw ValidationException::withMessages([
                'domain' => "Mail domain '{$mailDomain->domain}' is inactive and cannot issue a standalone TLS certificate.",
            ]);
        }

        if ($mailDomain->uses_shared_mail_host) {
            throw ValidationException::withMessages([
                'domain' => "Mail domain '{$mailDomain->domain}' uses the server's shared mail hostname ('{$mailDomain->mailHost()}') and does not require a standalone TLS certificate.",
            ]);
        }

        $targetHost = $mailDomain->mailHost();
        $contactEmail = (string) ($actor->email ?: "postmaster@{$mailDomain->domain}");

        // OS-first mutation: fail fast via LcCliException before emitting events or audit logs.
        $result = $this->driver->issueStandaloneMailTls($targetHost, $contactEmail);

        $mailHost = (string) ($result['domain'] ?? $targetHost);
        $certPath = (string) ($result['cert_path'] ?? "/etc/letsencrypt/live/{$mailHost}/fullchain.pem");
        $keyPath = (string) ($result['key_path'] ?? "/etc/letsencrypt/live/{$mailHost}/privkey.pem");
        $expiresAt = (int) ($result['expires_at'] ?? now()->addDays(90)->timestamp);

        $this->auditLogger->log([
            'user_id' => $actor->id,
            'action' => 'mail.domain.tls_issued',
            'target_type' => MailDomain::class,
            'target_id' => $mailDomain->id,
            'metadata' => [
                'domain' => $mailDomain->domain,
                'mail_host' => $mailHost,
                'ownership_source' => $mailDomain->ownership_source,
            ],
        ]);

        SslCertificateIssued::dispatch($mailHost, $certPath, $keyPath, $expiresAt);

        return [
            'domain' => $mailHost,
            'cert_path' => $certPath,
            'key_path' => $keyPath,
            'expires_at' => $expiresAt,
        ];
    }
}
