<?php

declare(strict_types=1);

namespace App\Http\Controllers\SourceControl;

use App\Console\Commands\CheckPanelSslExpiryCommand;
use App\Domains\SourceControl\Actions\DisconnectGitProviderAction;
use App\Domains\SourceControl\Actions\EvaluatePanelUrlGateAction;
use App\Domains\SourceControl\Contracts\GitProviderDriverInterface;
use App\Domains\SourceControl\Contracts\ProviderDeploymentBindingBridgeInterface;
use App\Domains\SourceControl\Enums\GitProviderStatus;
use App\Domains\SourceControl\Exceptions\GitProviderApiException;
use App\Domains\SourceControl\Models\GitProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\SourceControl\DisconnectGitProviderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SourceControlController extends Controller
{
    public function index(
        Request $request,
        EvaluatePanelUrlGateAction $evaluateGate,
        ProviderDeploymentBindingBridgeInterface $bindingBridge,
    ): Response {
        $user = $request->user();
        $gate = $evaluateGate->handle(requestHost: $request->getHost());

        $sslExpiryWarning = Cache::get(CheckPanelSslExpiryCommand::WARNING_CACHE_KEY);
        if (! is_array($sslExpiryWarning)) {
            $warningDays = (int) config('litecontrol.panel_ssl_expiry_warning_days', 14);
            if ($gate->allowed && $gate->expiresWithinDays($warningDays)) {
                $daysRemaining = max(0, $gate->daysUntilExpiry() ?? 0);
                $sslExpiryWarning = [
                    'warning' => true,
                    'code' => 'cert_expiring_soon',
                    'message' => "Panel SSL certificate expires in {$daysRemaining} day(s) ({$gate->certExpiresAt?->toDateString()}). Renew it to avoid webhook delivery failures.",
                    'configured_url' => $gate->configuredUrl,
                    'expires_at' => $gate->certExpiresAt?->toIso8601String(),
                    'days_remaining' => $daysRemaining,
                ];
            } else {
                $sslExpiryWarning = null;
            }
        }

        $configuredBaseUrl = $gate->configuredUrl !== null
            ? rtrim($gate->configuredUrl, '/')
            : rtrim((string) config('app.url', ''), '/');

        $providers = GitProvider::query()
            ->forUser($user->id)
            ->latest('id')
            ->get();

        $linkedByProvider = $bindingBridge->listLinkedDomainsByProviderIds(
            $providers->pluck('id')->map(static fn ($id): int => (int) $id)->all()
        );

        $serializedProviders = $providers->map(function (GitProvider $provider) use ($configuredBaseUrl, $linkedByProvider): array {
            $expectedWebhookUrl = $configuredBaseUrl !== ''
                ? "{$configuredBaseUrl}/api/webhooks/git-providers/{$provider->uuid}"
                : null;

            $configuredWebhookUrl = $provider->configured_webhook_url;
            $webhookUrlMismatch = $expectedWebhookUrl !== null
                && $configuredWebhookUrl !== null
                && rtrim($configuredWebhookUrl, '/') !== rtrim($expectedWebhookUrl, '/');

            $linkedDomains = $linkedByProvider[$provider->id] ?? [];
            $slug = $provider->external_app_slug;
            $accountLogin = $provider->external_account_login;
            $accountType = $provider->external_account_type;
            $isOrg = is_string($accountType) && strtolower($accountType) === 'organization' && is_string($accountLogin) && $accountLogin !== '';

            $githubAppSettingsUrl = null;
            if (is_string($slug) && $slug !== '') {
                $githubAppSettingsUrl = $isOrg
                    ? "https://github.com/organizations/{$accountLogin}/settings/apps/{$slug}/advanced"
                    : "https://github.com/settings/apps/{$slug}/advanced";
            }

            $manageReposUrl = null;
            if ($provider->installation_id !== null) {
                $manageReposUrl = $isOrg
                    ? "https://github.com/organizations/{$accountLogin}/settings/installations/{$provider->installation_id}"
                    : "https://github.com/settings/installations/{$provider->installation_id}";
            }

            $resumeInstallationUrl = is_string($slug) && $slug !== ''
                ? "https://github.com/apps/{$slug}/installations/new"
                : null;

            return [
                'id' => $provider->id,
                'uuid' => $provider->uuid,
                'provider' => $provider->provider,
                'label' => $provider->label,
                'external_app_id' => $provider->external_app_id,
                'external_app_slug' => $slug,
                'external_account_login' => $accountLogin,
                'external_account_type' => $accountType,
                'installation_id' => $provider->installation_id,
                'repository_selection' => $provider->repository_selection,
                'status' => $provider->status instanceof GitProviderStatus
                    ? $provider->status->value
                    : (string) $provider->status,
                'configured_webhook_url' => $configuredWebhookUrl,
                'expected_webhook_url' => $expectedWebhookUrl,
                'webhook_url_mismatch' => $webhookUrlMismatch,
                'webhook_verified_at' => $provider->webhook_verified_at?->toIso8601String(),
                'last_webhook_at' => $provider->last_webhook_at?->toIso8601String(),
                'suspended_at' => $provider->suspended_at?->toIso8601String(),
                'last_verified_at' => $provider->last_verified_at?->toIso8601String(),
                'linked_domains_count' => count($linkedDomains),
                'linked_domains' => $linkedDomains,
                'resume_installation_url' => $resumeInstallationUrl,
                'manage_repos_url' => $manageReposUrl,
                'github_app_settings_url' => $githubAppSettingsUrl,
            ];
        })->values()->all();

        $maxProviders = (int) config('litecontrol.max_git_providers_per_user', 10);

        return Inertia::render('SourceControl/Index', [
            'gate' => $gate->toArray(),
            'sslExpiryWarning' => $sslExpiryWarning,
            'providers' => $serializedProviders,
            'panelSslUrl' => '/security/panel-ssl?return_to=/settings/source-control',
            'maxProviders' => $maxProviders,
            'canConnect' => $gate->allowed && count($serializedProviders) < $maxProviders,
            'orphanedDeletionUrl' => $request->session()->get('orphaned_github_app_deletion_url'),
        ]);
    }

    public function destroy(
        DisconnectGitProviderRequest $request,
        GitProvider $gitProvider,
        DisconnectGitProviderAction $action,
    ): RedirectResponse {
        Gate::authorize('delete', $gitProvider);

        $validated = $request->validated();
        $result = $action->handle(
            actor: $request->user(),
            provider: $gitProvider,
            switchLinkedToManual: (bool) ($validated['switch_linked_to_manual'] ?? false),
            ipAddress: $request->ip(),
        );

        $message = $result['detached_domains_count'] > 0
            ? "Provider disconnected and {$result['detached_domains_count']} domain(s) switched to Manual Git Mode."
            : 'Source Control provider disconnected.';

        return back()->with('success', $message);
    }

    public function repositories(
        Request $request,
        GitProvider $gitProvider,
        GitProviderDriverInterface $driver,
    ): JsonResponse {
        Gate::authorize('view', $gitProvider);

        $search = is_string($request->query('search')) ? trim($request->query('search')) : null;
        $forceRefresh = $request->boolean('refresh');

        try {
            $repositories = $driver->listRepositories($gitProvider, $search, $forceRefresh);
        } catch (GitProviderApiException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'repositories' => [],
            ], $e->statusCode >= 400 && $e->statusCode < 600 ? $e->statusCode : 502);
        }

        return response()->json([
            'repositories' => $repositories,
        ]);
    }

    public function branches(
        Request $request,
        GitProvider $gitProvider,
        GitProviderDriverInterface $driver,
    ): JsonResponse {
        Gate::authorize('view', $gitProvider);

        $fullName = trim((string) $request->query('repo', ''));
        if (! str_contains($fullName, '/')) {
            return response()->json([
                'message' => 'A valid repository full name (owner/repo) is required.',
                'branches' => [],
            ], 422);
        }

        [$owner, $repo] = explode('/', $fullName, 2);
        $repositoryId = $request->query('repository_id');

        try {
            $branches = $driver->listBranches(
                provider: $gitProvider,
                owner: $owner,
                repo: $repo,
                repositoryId: is_string($repositoryId) || is_int($repositoryId) ? $repositoryId : null,
            );
        } catch (GitProviderApiException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'branches' => [],
            ], $e->statusCode >= 400 && $e->statusCode < 600 ? $e->statusCode : 502);
        }

        return response()->json([
            'branches' => $branches,
        ]);
    }
}
