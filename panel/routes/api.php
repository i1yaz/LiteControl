<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DeploymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/deploy/{webhook_token}', DeploymentWebhookController::class)
    ->name('api.webhooks.deploy');
