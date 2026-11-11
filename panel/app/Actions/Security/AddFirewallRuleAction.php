<?php

namespace App\Actions\Security;

use App\Models\AuditLog;
use App\Models\FirewallRule;
use App\Models\User;
use App\Services\System\FirewallCliService;
use Illuminate\Support\Facades\DB;

class AddFirewallRuleAction
{
    public function __construct(
        protected FirewallCliService $firewallCli
    ) {}

    /**
     * Add firewall rule via CLI, insert database record, and write audit log.
     *
     * @param array $data ['name', 'port', 'protocol', 'action', 'source_ip']
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return FirewallRule
     */
    public function handle(array $data, ?User $actor = null, ?string $ipAddress = null): FirewallRule
    {
        $this->firewallCli->addRule(
            action: $data['action'],
            port: (string) $data['port'],
            protocol: $data['protocol'] ?? 'tcp',
            sourceIp: !empty($data['source_ip']) ? $data['source_ip'] : null
        );

        return DB::transaction(function () use ($data, $actor, $ipAddress) {
            $rule = FirewallRule::create([
                'name' => $data['name'],
                'port' => (string) $data['port'],
                'protocol' => $data['protocol'],
                'action' => $data['action'],
                'source_ip' => $data['source_ip'] ?: 'Anywhere',
            ]);

            $sub = $data['action'] === 'allow' ? 'ufw-allow' : 'ufw-deny';

            AuditLog::create([
                'user_id' => $actor?->id,
                'action' => 'create_firewall_rule',
                'command' => "lc-cli security {$sub} {$data['port']}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => json_encode($data),
            ]);

            return $rule;
        });
    }
}

