<?php

declare(strict_types=1);

namespace App\Domains\Mail\Infrastructure;

use App\Domains\Mail\Contracts\MailFilterDriverInterface;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class CliRspamdFilterAdapter implements MailFilterDriverInterface
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    public function key(): string
    {
        return 'rspamd';
    }

    public function minimumRamMb(bool $withAntivirus = false): int
    {
        return $withAntivirus ? 4096 : 1024;
    }

    public function install(bool $withAntivirus = false): array
    {
        $args = ['--driver=rspamd'];
        if ($withAntivirus) {
            $args[] = '--with-clamav';
        }

        return $this->execute('filter-enable', $args);
    }

    public function demoteToLight(): array
    {
        return $this->execute('filter-demote', ['--driver=rspamd']);
    }

    public function syncDomainPolicy(
        string $domain,
        bool $enabled,
        float $headerScore,
        float $rejectScore,
    ): array {
        return $this->execute('reconcile', ['--scope=filter']);
    }

    public function removeDomainPolicy(string $domain): array
    {
        return $this->execute('reconcile', ['--scope=filter']);
    }

    public function isRunning(): bool
    {
        try {
            $data = $this->execute('filter-status', ['--driver=rspamd']);

            return (bool) ($data['running'] ?? false);
        } catch (LcCliException) {
            return false;
        }
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
