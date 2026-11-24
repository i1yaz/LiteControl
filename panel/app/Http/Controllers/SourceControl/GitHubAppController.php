<?php

declare(strict_types=1);

namespace App\Http\Controllers\SourceControl;

use App\Domains\SourceControl\Actions\CheckGitProviderHealthAction;
use App\Domains\SourceControl\Actions\CompleteGitHubAppInstallationAction;
use App\Domains\SourceControl\Actions\CompleteGitHubAppManifestAction;
use App\Domains\SourceControl\Actions\InitiateGitHubAppManifestAction;
use App\Domains\SourceControl\Actions\RepairGitProviderWebhookAction;
use App\Domains\SourceControl\Exceptions\GitProviderApiException;
use App\Domains\SourceControl\Exceptions\OrphanedGitHubAppException;
use App\Domains\SourceControl\Models\GitProvider;
use App\Domains\SourceControl\ValueObjects\GitHubOrgName;
use App\Domains\SourceControl\ValueObjects\SafeReturnPath;
use App\Http\Controllers\Controller;
use App\Http\Requests\SourceControl\GitHubManifestCallbackRequest;
use App\Http\Requests\SourceControl\GitHubSetupCallbackRequest;
use App\Http\Requests\SourceControl\InitiateGitHubManifestRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class GitHubAppController extends Controller
{
    public function manifest(
        InitiateGitHubManifestRequest $request,
        InitiateGitHubAppManifestAction $action,
    ): JsonResponse {
        $validated = $request->validated();

        $result = $action->handle(
            user: $request->user(),
            requestHost: $request->getHost(),
            label: $validated['label'] ?? null,
            orgName: GitHubOrgName::fromNullable($validated['org_name'] ?? null),
            returnTo: SafeReturnPath::fromNullable($validated['return_to'] ?? null),
        );

        return response()->json($result);
    }

    public function callback(
        GitHubManifestCallbackRequest $request,
        CompleteGitHubAppManifestAction $action,
    ): RedirectResponse {
        $user = $request->user();
        if ($user === null) {
            return redirect()
                ->route('login')
                ->with(
                    'error',
                    'Your LiteControl session expired while registering the GitHub App. Sign in again and check your GitHub App settings if an app was already created.'
                );
        }

        $validated = $request->validated();

        try {
            $result = $action->handle(
                user: $user,
                code: (string) $validated['code'],
                state: (string) $validated['state'],
                ipAddress: $request->ip(),
            );
        } catch (OrphanedGitHubAppException $e) {
            return redirect(SafeReturnPath::DEFAULT_PATH)
                ->with('error', $e->getMessage())
                ->with('orphaned_github_app_deletion_url', $e->deletionUrl);
        } catch (GitProviderApiException $e) {
            return redirect(SafeReturnPath::DEFAULT_PATH)
                ->with('error', $e->getMessage());
        }

        return redirect()->away($result['redirect_url']);
    }

    public function setup(
        GitHubSetupCallbackRequest $request,
        CompleteGitHubAppInstallationAction $action,
    ): RedirectResponse {
        $validated = $request->validated();

        $result = $action->handle(
            user: $request->user(),
            installationId: (int) $validated['installation_id'],
            state: isset($validated['state']) ? (string) $validated['state'] : null,
            setupAction: isset($validated['setup_action']) ? (string) $validated['setup_action'] : null,
            ipAddress: $request->ip(),
        );

        return redirect($result['return_to'])
            ->with('success', 'GitHub App connected successfully.');
    }

    public function repairWebhook(
        Request $request,
        GitProvider $gitProvider,
        RepairGitProviderWebhookAction $action,
    ): JsonResponse {
        Gate::authorize('update', $gitProvider);

        $updated = $action->handle(
            actor: $request->user(),
            provider: $gitProvider,
            requestHost: $request->getHost(),
            ipAddress: $request->ip(),
        );

        return response()->json([
            'configured_webhook_url' => $updated->configured_webhook_url,
            'message' => 'GitHub App webhook URL updated successfully.',
        ]);
    }

    public function health(
        GitProvider $gitProvider,
        CheckGitProviderHealthAction $action,
    ): JsonResponse {
        Gate::authorize('view', $gitProvider);

        $health = $action->handle($gitProvider);

        return response()->json($health->toArray());
    }
}
