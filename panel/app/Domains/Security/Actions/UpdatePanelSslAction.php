<?php

namespace App\Domains\Security\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Security\Contracts\PanelSslDriverInterface;
use App\Domains\Shared\Contracts\AuditLoggerInterface;

class UpdatePanelSslAction
{
    public function __construct(
        protected PanelSslDriverInterface $panelSslDriver,
        protected ?AuditLoggerInterface $auditLogger = null
    ) {}

    /**
     * Configure LiteControl panel SSL (Let's Encrypt or custom).
     *
     * @param  array  $data  ['type' => 'letsencrypt|custom', 'domain' => ?, 'certificate' => ?, 'private_key' => ?]
     */
    public function handle(array $data, ?User $actor = null, ?string $ipAddress = null): array
    {
        if ($data['type'] === 'letsencrypt') {
            $result = $this->panelSslDriver->issuePanelSsl($data['domain']);
        } else {
            $result = $this->panelSslDriver->installCustomPanelSsl($data['certificate'], $data['private_key']);
        }

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor?->id,
            'action' => 'update_panel_ssl',
            'command' => 'lc-cli ssl panel-ssl',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode(['type' => $data['type']]),
        ]);

        return $result;
    }
}
