<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\SourceControl\Contracts\GitProviderDriverInterface;
use App\Domains\SourceControl\Models\GitProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RepairGitProviderWebhookAction
{
    public function __construct(
        private readonly EvaluatePanelUrlGateAction $evaluatePanelUrlGate,
        private readonly GitProviderDriverInterface $driver,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function handle(
        User $actor,
        GitProvider $provider,
        ?string $requestHost = null,
        ?string $ipAddress = null,
    ): GitProvider {
        $gate = $this->evaluatePanelUrlGate->handle(requestHost: $requestHost);
        if (! $gate->allowed || $gate->configuredUrl === null) {
            throw ValidationException::withMessages([
                'panel_url' => [$gate->message],
            ]);
        }

        $targetWebhookUrl = rtrim($gate->configuredUrl, '/')."/api/webhooks/git-providers/{$provider->uuid}";

        $this->driver->repairWebhook($provider, $targetWebhookUrl);

        $updated = DB::transaction(function () use ($actor, $provider, $targetWebhookUrl, $ipAddress): GitProvider {
            $previousUrl = $provider->configured_webhook_url;

            $provider->forceFill([
                'configured_webhook_url' => $targetWebhookUrl,
            ])->save();

            $this->auditLogger->log([
                'user_id' => $actor->id,
                'action' => 'source_control.webhook_repaired',
                'command' => "github-app repair-webhook {$provider->uuid}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => [
                    'git_provider_id' => $provider->id,
                    'previous_webhook_url' => $previousUrl,
                    'configured_webhook_url' => $targetWebhookUrl,
                ],
            ]);

            return $provider->refresh();
        });

        Log::info('Repaired GitHub App webhook URL.', [
            'user_id' => $actor->id,
            'git_provider_id' => $provider->id,
            'configured_webhook_url' => $targetWebhookUrl,
        ]);

        return $updated;
    }
}
