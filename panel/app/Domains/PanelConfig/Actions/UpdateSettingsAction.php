<?php

namespace App\Domains\PanelConfig\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\ServerOps\Contracts\ServerDriverInterface;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Exceptions\System\LcCliException;

class UpdateSettingsAction
{
    public function __construct(
        protected ServerDriverInterface $serverCli,
        protected ?AuditLoggerInterface $auditLogger = null
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
        // Sync timezone via ServerCliService
        $this->serverCli->setTimezone($validated['timezone']);

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'update_settings',
            'command' => 'configuration.settings',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode($validated),
        ]);
    }
}
