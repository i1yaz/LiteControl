<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\SourceControl\Enums\GitProviderStatus;
use App\Domains\SourceControl\Models\GitProvider;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneAbandonedGitProvidersCommand extends Command
{
    protected $signature = 'source-control:prune-abandoned-providers {--days=7 : Age threshold in days for abandoned pending installations}';

    protected $description = 'Delete pending_installation GitProvider records older than 7 days with no installation_id';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = CarbonImmutable::now()->subDays($days);

        $deleted = GitProvider::query()
            ->where('status', GitProviderStatus::PendingInstallation->value)
            ->whereNull('installation_id')
            ->where('created_at', '<', $cutoff)
            ->delete();

        if ($deleted > 0) {
            Log::info('Pruned abandoned pending GitProvider registrations.', [
                'deleted_count' => $deleted,
                'cutoff' => $cutoff->toIso8601String(),
            ]);
        }

        $this->info("Pruned {$deleted} abandoned git provider(s).");

        return self::SUCCESS;
    }
}
