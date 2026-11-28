<?php

declare(strict_types=1);

namespace App\Domains\Mail\Infrastructure;

use App\Domains\Mail\Contracts\WebmailDriverInterface;
use App\Domains\Shared\ValueObjects\DomainName;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;
use Illuminate\Support\Facades\Cache;

class CliSnappyMailAdapter implements WebmailDriverInterface
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    public function key(): string
    {
        return 'snappymail';
    }

    public function install(string $hostname): array
    {
        return $this->execute('webmail-install', [
            '--driver=snappymail',
            "--hostname={$hostname}",
        ]);
    }

    public function uninstall(): array
    {
        return $this->execute('webmail-uninstall', [
            '--driver=snappymail',
        ]);
    }

    public function loginUrl(DomainName $domain): string
    {
        $webmailHost = Cache::get('mail_webmail_hostname');
        if (is_string($webmailHost) && trim($webmailHost) !== '') {
            $host = trim($webmailHost);
        } else {
            $configuredHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            $host = is_string($configuredHost) && $configuredHost !== '' && $configuredHost !== 'localhost'
                ? $configuredHost
                : "mail.{$domain->value}";
        }

        return sprintf('https://%s/?domain=%s', $host, rawurlencode($domain->value));
    }

    public function isRunning(): bool
    {
        try {
            $data = $this->execute('webmail-status', ['--driver=snappymail']);

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
