<?php

namespace App\Actions\Configuration;

use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\ServerCliService;

class UpdateSettingsAction
{
    public function __construct(
        protected ServerCliService $serverCli
    ) {}

    /**
     * Update general panel/server settings.
     *
     * @param array<string, mixed> $validated
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return void
     * @throws LcCliException
     */
    public function handle(array $validated, ?User $actor = null, ?string $ipAddress = null): void
    {
        if (!empty($validated['default_theme'])) {
            session(['theme' => $validated['default_theme']]);
            if ($actor) {
                $actor->update(['theme' => $validated['default_theme']]);
            }
        }

        // Sync timezone via ServerCliService
        $this->serverCli->setTimezone($validated['timezone']);

        AuditLog::create([
            'user_id' => $actor?->id,
            'action' => 'update_settings',
            'command' => 'configuration.settings',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode($validated),
        ]);
    }
}

