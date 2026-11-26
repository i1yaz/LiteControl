<?php

declare(strict_types=1);

namespace App\Http\Controllers\SourceControl;

use App\Domains\SourceControl\Actions\ProcessGitHubWebhookAction;
use App\Domains\SourceControl\ValueObjects\IncomingWebhookPayload;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitProviderWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $uuid,
        ProcessGitHubWebhookAction $action,
    ): JsonResponse {
        $incoming = IncomingWebhookPayload::fromRequest($request);

        $result = $action->handle($uuid, $incoming, $request->ip());

        return response()->json($result->toArray(), $result->httpStatus);
    }
}
