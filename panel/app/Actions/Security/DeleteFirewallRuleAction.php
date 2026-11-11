<?php

namespace App\Actions\Security;

use App\Models\AuditLog;
use App\Models\FirewallRule;
use App\Models\User;
use App\Services\System\FirewallCliService;
use Illuminate\Support\Facades\DB;

class DeleteFirewallRuleAction
{
    public function __construct(
        protected FirewallCliService $firewallCli
    ) {}

    /**
     * Delete firewall rule by ID or spec via CLI, delete DB record, and write audit log.
     *
     * @param string|int $rule
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return void
     */
    public function handle(string|int $rule, ?User $actor = null, ?string $ipAddress = null): void
    {
        $this->firewallCli->deleteRule((string) $rule);

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

