<?php

namespace App\Domains\WebServer\Actions;

use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\Log;

class BatchSwitchPhpVersionAction
{
    public function __construct(
        protected SwitchDomainPhpVersionAction $switchDomainPhpVersion,
    ) {}

    /**
     * Batch switch PHP version for multiple domains.
     */
    public function handle(iterable $domains, string $phpVersion, ?User $actor = null): int
    {
        $count = 0;

        foreach ($domains as $domain) {
            try {
                $this->switchDomainPhpVersion->handle($domain, $phpVersion, $actor);
                $count++;
            } catch (\Throwable $e) {
                Log::warning("Batch PHP switch skipped for {$domain->domain}: {$e->getMessage()}");
            }
        }

        return $count;
    }
}
