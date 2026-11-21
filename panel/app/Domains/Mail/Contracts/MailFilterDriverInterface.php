<?php

declare(strict_types=1);

namespace App\Domains\Mail\Contracts;

use App\Exceptions\System\LcCliException;

interface MailFilterDriverInterface
{
    /**
     * Unique driver key (e.g. 'rspamd').
     */
    public function key(): string;

    /**
     * Minimum host RAM in megabytes required to run this filter driver.
     */
    public function minimumRamMb(bool $withAntivirus = false): int;

    /**
     * Upgrade mail filter to full inbound scanning (and optional ClamAV antivirus).
     *
     * @throws LcCliException
     */
    public function install(bool $withAntivirus = false): array;

    /**
     * Demote the mail filter back to DKIM-Light + outbound ratelimit without
     * removing the Rspamd package or touching DKIM keys.
     *
     * @throws LcCliException
     */
    public function demoteToLight(): array;

    /**
     * Sync per-domain spam thresholds to the filter daemon.
     *
     * @throws LcCliException
     */
    public function syncDomainPolicy(
        string $domain,
        bool $enabled,
        float $headerScore,
        float $rejectScore,
    ): array;

    /**
     * Remove per-domain spam policy overrides from the filter daemon.
     *
     * @throws LcCliException
     */
    public function removeDomainPolicy(string $domain): array;

    /**
     * Return true when the full inbound anti-spam filter is active and running.
     */
    public function isRunning(): bool;
}
