<?php

namespace App\Domains\Security\Actions;

use App\Domains\Security\Contracts\FirewallDriverInterface;
use App\Domains\Security\Models\FirewallRule;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AddFirewallRuleAction
{
    public function __construct(
        protected FirewallDriverInterface $firewallDriver
    ) {}

    /**
     * Add firewall rule via CLI, insert database record, and write audit log.
     *
     * @param  array  $data  ['name', 'port', 'protocol', 'action', 'source_ip']
     */
    public function handle(array $data, ?User $actor = null, ?string $ipAddress = null): FirewallRule
    {
        $this->firewallDriver->addRule(
            action: $data['action'],
            port: (string) $data['port'],
            protocol: $data['protocol'] ?? 'tcp',
            sourceIp: ! empty($data['source_ip']) ? $data['source_ip'] : null
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

