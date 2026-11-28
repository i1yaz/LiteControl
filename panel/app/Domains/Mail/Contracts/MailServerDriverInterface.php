<?php

declare(strict_types=1);

namespace App\Domains\Mail\Contracts;

use App\Exceptions\System\LcCliException;

interface MailServerDriverInterface
{
    /**
     * Install and configure the core mail stack (Postfix, Dovecot, Rspamd-Light, postsrsd, lc-rspamd-redis).
     *
     * @throws LcCliException
     */
    public function installCore(string $hostname): array;

    /**
     * Stop and disable the core mail stack, preserving /var/vmail unless $purgeData is true.
     *
     * @throws LcCliException
     */
    public function uninstallCore(bool $purgeData = false): array;

    /**
     * Idempotently rebuild mail server configuration for the given scope and reload changed daemons.
     *
     * @throws LcCliException
     */
    public function reconcile(string $scope = 'all'): array;

    /**
     * Flush Dovecot auth cache and kick active sessions for an email address.
     *
     * @throws LcCliException
     */
    public function evictSession(string $email): array;

    /**
     * Synchronize per-domain TLS SNI certificate mapping for Postfix and Dovecot.
     *
     * @throws LcCliException
     */
    public function syncSni(string $domain, string $certPath, string $keyPath): array;

    /**
     * Remove per-domain TLS SNI certificate mapping for Postfix and Dovecot.
     *
     * @throws LcCliException
     */
    public function removeSni(string $domain): array;

    /**
     * Generate a DKIM keypair on disk and return its public TXT record, private key path, and SHA-256 fingerprint.
     *
     * @throws LcCliException
     */
    public function generateDkimKey(string $domain, string $selector, string $algorithm = 'rsa2048'): array;

    /**
     * Remove a DKIM keypair from disk and reconcile DKIM selector maps.
     *
     * @throws LcCliException
     */
    public function removeDkimKey(string $domain, string $selector): array;

    /**
     * Safely remove a mailbox's Maildir under /var/vmail/<domain>/<localPart>.
     *
     * @throws LcCliException
     */
    public function purgeMailbox(string $domain, string $localPart): array;

    /**
     * Safely remove all Maildirs for a domain under /var/vmail/<domain>.
     *
     * @throws LcCliException
     */
    public function purgeDomain(string $domain): array;

    /**
     * Query Dovecot quota usage for all mailboxes in a domain, returning [email => used_bytes].
     *
     * @return array<string, int>
     *
     * @throws LcCliException
     */
    public function readQuotaUsage(string $domain): array;

    /**
     * Issue an HTTP-01 TLS certificate for a standalone mail.{domain} host via lc-cli ssl mail-ssl.
     *
     * @throws LcCliException
     */
    public function issueStandaloneMailTls(string $domain, string $contactEmail): array;

    /**
     * Remove the standalone mail.{domain} TLS certificate and OpenLiteSpeed ACME map via lc-cli ssl mail-ssl delete.
     *
     * @throws LcCliException
     */
    public function removeStandaloneMailTls(string $domain): array;
}
