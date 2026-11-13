<?php

namespace App\Domains\Security\Actions;

use App\Domains\Security\Contracts\FirewallDriverInterface;
use App\Domains\Security\Models\FirewallRule;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteFirewallRuleAction
{
    public function __construct(
        protected FirewallDriverInterface $firewallDriver
    ) {}

    /**
     * Delete firewall rule by ID or spec via CLI, delete DB record, and write audit log.
     */
    public function handle(string|int $rule, ?User $actor = null, ?string $ipAddress = null): void
    {
        $this->firewallDriver->deleteRule((string) $rule);

        DB::transaction(function () use ($rule, $actor, $ipAddress) {
            if (is_numeric($rule)) {
                $dbRule = FirewallRule::find($rule);
                if ($dbRule) {
                    $dbRule->delete();
                }
            }

            AuditLog::create([
                'user_id' => $actor?->id,
                'action' => 'delete_firewall_rule',
                'command' => "lc-cli security ufw-delete {$rule}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => json_encode(['rule' => $rule]),
            ]);
        });
    }
}

