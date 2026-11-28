<?php

declare(strict_types=1);

namespace App\Domains\Mail\Infrastructure;

use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class CliMailServerAdapter implements MailServerDriverInterface
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    public function installCore(string $hostname): array
    {
        return $this->execute('install-core', ["--hostname={$hostname}"]);
    }

    public function uninstallCore(bool $purgeData = false): array
    {
        $args = $purgeData ? ['--purge-data'] : [];

        return $this->execute('uninstall-core', $args);
    }

    public function reconcile(string $scope = 'all'): array
    {
        return $this->execute('reconcile', ["--scope={$scope}"]);
    }

    public function evictSession(string $email): array
    {
        return $this->execute('evict-session', ["--email={$email}"]);
    }

    public function syncSni(string $domain, string $certPath, string $keyPath): array
    {
        return $this->execute('reconcile', ['--scope=sni']);
    }

    public function removeSni(string $domain): array
    {
        return $this->execute('reconcile', ['--scope=sni']);
    }

    public function generateDkimKey(string $domain, string $selector, string $algorithm = 'rsa2048'): array
    {
        return $this->execute('dkim-generate', [
            "--domain={$domain}",
            "--selector={$selector}",
            "--algo={$algorithm}",
        ]);
    }

    public function removeDkimKey(string $domain, string $selector): array
    {
        return $this->execute('dkim-remove', [
            "--domain={$domain}",
            "--selector={$selector}",
        ]);
    }

    public function purgeMailbox(string $domain, string $localPart): array
    {
        return $this->execute('purge-mailbox', [
            "--domain={$domain}",
            "--user={$localPart}",
        ]);
    }

    public function purgeDomain(string $domain): array
    {
        return $this->execute('purge-domain', [
            "--domain={$domain}",
        ]);
    }

    public function readQuotaUsage(string $domain): array
    {
        $data = $this->execute('quota-usage', ["--domain={$domain}"]);

        $usage = [];
        foreach ($data as $email => $bytes) {
            if (is_string($email) && is_numeric($bytes)) {
                $usage[$email] = (int) $bytes;
            }
        }

        return $usage;
    }

    public function issueStandaloneMailTls(string $domain, string $contactEmail): array
    {
        $args = ['mail-ssl', 'issue', $domain, $contactEmail];
        $res = $this->lcCli->run('ssl', $args);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException(
                $res['message'] ?? "ssl mail-ssl issue failed for {$domain}.",
                'ssl',
                $args,
                $res
            );
        }

        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }

    public function removeStandaloneMailTls(string $domain): array
    {
        $args = ['mail-ssl', 'delete', $domain];
        $res = $this->lcCli->run('ssl', $args);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException(
                $res['message'] ?? "ssl mail-ssl delete failed for {$domain}.",
                'ssl',
                $args,
                $res
            );
        }

        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }

    /**
     * Execute an lc-mail subcommand and fail fast with LcCliException on error.
     *
     * @throws LcCliException
     */
    protected function execute(string $subcommand, array $args = []): array
    {
        $res = $this->lcCli->runMail($subcommand, $args);

        if (($res['status'] ?? '') === 'error') {
            throw new LcCliException(
                $res['message'] ?? "lc-mail {$subcommand} failed.",
                "lc-mail {$subcommand}",
                $args,
                $res
            );
        }

        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }
}
