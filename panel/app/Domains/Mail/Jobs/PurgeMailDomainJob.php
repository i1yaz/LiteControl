<?php

declare(strict_types=1);

namespace App\Domains\Mail\Jobs;

use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Models\MailPendingPurge;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PurgeMailDomainJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(
        public readonly int $purgeId,
        public readonly string $domain,
        public readonly bool $deleteSsl = false,
        public readonly ?string $sslHostname = null,
    ) {
        $this->afterCommit();
    }

    public function handle(MailServerDriverInterface $mailDriver): void
    {
        $purge = MailPendingPurge::find($this->purgeId);
        if (! $purge) {
            return;
        }

        $purge->update([
            'status' => 'purging',
            'attempts' => $purge->attempts + 1,
        ]);

        try {
            $mailDriver->purgeDomain($this->domain);
            if ($this->deleteSsl) {
                $targetSslHost = is_string($this->sslHostname) && trim($this->sslHostname) !== ''
                    ? trim($this->sslHostname)
                    : "mail.{$this->domain}";
                $mailDriver->removeStandaloneMailTls($targetSslHost);
            }
            $purge->delete();
        } catch (\Throwable $e) {
            $purge->update([
                'status' => 'failed',
                'last_error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
