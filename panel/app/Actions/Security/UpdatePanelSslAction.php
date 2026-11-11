<?php

namespace App\Actions\Security;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\PanelSslCliService;

class UpdatePanelSslAction
{
    public function __construct(
        protected PanelSslCliService $panelSslCli
    ) {}

    /**
     * Configure LiteControl panel SSL (Let's Encrypt or custom).
     *
     * @param array $data ['type' => 'letsencrypt|custom', 'domain' => ?, 'certificate' => ?, 'private_key' => ?]
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return array
     */
    public function handle(array $data, ?User $actor = null, ?string $ipAddress = null): array
    {
        if ($data['type'] === 'letsencrypt') {
            $result = $this->panelSslCli->issuePanelSsl($data['domain']);
        } else {
            $result = $this->panelSslCli->installCustomPanelSsl($data['certificate'], $data['private_key']);
        }

        AuditLog::create([
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

