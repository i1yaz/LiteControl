<?php

namespace App\Domains\PanelConfig\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Exceptions\System\LcCliException;

class UpdateSettingsAction
{
    public function __construct(
        protected ServerDriverInterface $serverCli
    ) {}

    /**
     * Update general panel/server settings.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws LcCliException
     */
    public function handle(array $validated, ?User $actor = null, ?string $ipAddress = null): void
    {
        if (! empty($validated['default_theme'])) {
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
