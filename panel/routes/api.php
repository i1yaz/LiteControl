<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DeploymentWebhookController;
use App\Http\Controllers\SourceControl\GitProviderWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/deploy/{webhook_token}', DeploymentWebhookController::class)
    ->name('api.webhooks.deploy');

Route::post('/webhooks/git-providers/{uuid}', GitProviderWebhookController::class)
    ->name('api.webhooks.git-providers');
