<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\SourceControl\Actions\EvaluatePanelUrlGateAction;
use App\Domains\SourceControl\ValueObjects\PanelUrlGateResult;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckPanelSslExpiryCommand extends Command
{
    public const WARNING_CACHE_KEY = 'source_control:panel_ssl_expiry_warning';

    protected $signature = 'source-control:check-panel-ssl-expiry';

    protected $description = 'Inspect the panel TLS certificate and flag a renewal warning when expiry is within 14 days';

    public function handle(EvaluatePanelUrlGateAction $evaluateGate): int
    {
        $warningDays = (int) config('litecontrol.panel_ssl_expiry_warning_days', 14);
        $now = CarbonImmutable::now();
        $result = $evaluateGate->handle();

        if (! $result->allowed) {
            if ($result->code === PanelUrlGateResult::CODE_CERT_EXPIRED) {
                $payload = [
                    'warning' => true,
                    'code' => PanelUrlGateResult::CODE_CERT_EXPIRED,
                    'message' => $result->message,
                    'configured_url' => $result->configuredUrl,
                    'expires_at' => $result->certExpiresAt?->toIso8601String(),
                    'days_remaining' => 0,
                    'checked_at' => $now->toIso8601String(),
                ];

                Cache::put(self::WARNING_CACHE_KEY, $payload, $now->addHours(48));

                Log::warning('Panel SSL certificate is expired.', $payload);
                $this->warn($result->message);

                return self::SUCCESS;
            }

            Cache::forget(self::WARNING_CACHE_KEY);

            Log::info('Panel SSL expiry check skipped warning flag because panel URL gate did not pass.', [
                'code' => $result->code,
                'configured_url' => $result->configuredUrl,
            ]);
            $this->line("Panel URL gate status: {$result->code}");

            return self::SUCCESS;
        }

        if ($result->expiresWithinDays($warningDays, $now)) {
            $daysRemaining = max(0, $result->daysUntilExpiry($now) ?? 0);
            $message = "Panel SSL certificate expires in {$daysRemaining} day(s) ({$result->certExpiresAt?->toDateString()}). Renew it to avoid webhook delivery failures.";

            $payload = [
                'warning' => true,
                'code' => 'cert_expiring_soon',
                'message' => $message,
                'configured_url' => $result->configuredUrl,
                'expires_at' => $result->certExpiresAt?->toIso8601String(),
                'days_remaining' => $daysRemaining,
                'checked_at' => $now->toIso8601String(),
            ];

            Cache::put(self::WARNING_CACHE_KEY, $payload, $now->addHours(48));

            Log::warning('Panel SSL certificate expires within renewal threshold.', $payload);
            $this->warn($message);

            return self::SUCCESS;
        }

        Cache::forget(self::WARNING_CACHE_KEY);
        $this->info('Panel SSL certificate is healthy.');

        return self::SUCCESS;
    }
}
