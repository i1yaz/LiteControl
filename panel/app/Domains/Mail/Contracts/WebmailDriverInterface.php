<?php

declare(strict_types=1);

namespace App\Domains\Mail\Contracts;

use App\Domains\Shared\ValueObjects\DomainName;
use App\Exceptions\System\LcCliException;

interface WebmailDriverInterface
{
    /**
     * Unique driver key (e.g. 'snappymail').
     */
    public function key(): string;

    /**
     * Provision the webmail client on the given server-level hostname.
     *
     * @throws LcCliException
     */
    public function install(string $hostname): array;

    /**
     * Remove the webmail client's OpenLiteSpeed vhost and isolated app directory.
     *
     * @throws LcCliException
     */
    public function uninstall(): array;

    /**
     * Return the webmail login URL for the given tenant mail domain.
     */
    public function loginUrl(DomainName $domain): string;

    /**
     * Return true when the webmail vhost is installed and OpenLiteSpeed is running.
     */
    public function isRunning(): bool;
}
