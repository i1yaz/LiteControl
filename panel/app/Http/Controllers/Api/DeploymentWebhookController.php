<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Deployment\Jobs\RunAtomicDeploymentJob;
use App\Domains\Deployment\Models\DomainDeployment;
use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeploymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $webhook_token): JsonResponse
    {
        $config = DomainDeploymentConfig::where('webhook_token', $webhook_token)->first();

        if (! $config || $config->mode !== 'atomic') {
            return response()->json(['error' => 'Webhook not found or atomic mode disabled.'], 404);
        }

        if (! $this->verifySignature($request, $config)) {
            return response()->json(['error' => 'Invalid webhook signature.'], 403);
        }

        $ref = (string) $request->input('ref', '');
        $pushedBranch = str_starts_with($ref, 'refs/heads/')
            ? substr($ref, strlen('refs/heads/'))
            : $ref;

        if ($pushedBranch !== '' && $pushedBranch !== $config->branch) {
            return response()->json(['skipped' => 'branch_mismatch'], 202);
        }

        $deployment = DomainDeployment::create([
            'domain_id' => $config->domain_id,
            'release_id' => now()->format('Ymd_His'),
            'branch' => $config->branch,
            'trigger_type' => 'webhook',
            'status' => 'queued',
        ]);

        RunAtomicDeploymentJob::dispatch($config, $deployment);

        return response()->json([
            'status' => 'queued',
            'release_id' => $deployment->release_id,
        ], 202);
    }

    private function verifySignature(Request $request, DomainDeploymentConfig $config): bool
    {
        if (! $config->webhook_secret_encrypted) {
            return true;
        }

        $secret = (string) $config->webhook_secret_encrypted;
        $rawBody = $request->getContent();

        if ($signature = $request->header('X-Hub-Signature-256')) {
            $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

            return hash_equals($expected, $signature);
        }

        if ($gitlabToken = $request->header('X-Gitlab-Token')) {
            return hash_equals($secret, $gitlabToken);
        }

        if ($bitbucketSig = $request->header('X-Hub-Signature')) {
            $expected = 'sha1='.hash_hmac('sha1', $rawBody, $secret);

            return hash_equals($expected, $bitbucketSig);
        }

        return false;
    }
}
