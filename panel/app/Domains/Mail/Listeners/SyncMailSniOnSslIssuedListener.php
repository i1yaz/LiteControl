<?php

declare(strict_types=1);

namespace App\Domains\Mail\Listeners;

use App\Domains\Mail\Jobs\ReconcileMailServerJob;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use App\Domains\Shared\Events\SslCertificateIssued;
use Illuminate\Contracts\Queue\ShouldQueue;

class SyncMailSniOnSslIssuedListener implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly ModuleRegistryQueryInterface $moduleRegistry,
    ) {}

    public function handle(SslCertificateIssued $event): void
    {
        if (! $this->moduleRegistry->isActive('mail_core')) {
            return;
        }

        $normalized = strtolower(trim($event->domain));
        $candidates = [$normalized];

        if (str_starts_with($normalized, 'mail.') && strlen($normalized) > 5) {
            $candidates[] = substr($normalized, 5);
        }

        $updated = MailDomain::query()
            ->where('active', true)
            ->whereIn('domain', array_values(array_unique($candidates)))
            ->update(['tls_sni_synced_at' => now()]);

        if ($updated === 0) {
            return;
        }

        ReconcileMailServerJob::dispatch('sni');
    }
}
