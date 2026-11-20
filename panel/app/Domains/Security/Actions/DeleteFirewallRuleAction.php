<?php

namespace App\Domains\Security\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Security\Contracts\FirewallDriverInterface;
use App\Domains\Security\Models\FirewallRule;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;

class DeleteFirewallRuleAction
{
    public function __construct(
        protected FirewallDriverInterface $firewallDriver,
        protected ?AuditLoggerInterface $auditLogger = null
    ) {}

    /**
     * Delete firewall rule by ID or spec via CLI, delete DB record, and write audit log.
     */
    public function handle(string|int $rule, ?User $actor = null, ?string $ipAddress = null): void
    {
        $dbRule = is_numeric($rule) ? FirewallRule::find($rule) : null;

        $targetSpec = (string) $rule;
        if ($dbRule) {
            $proto = ($dbRule->protocol && $dbRule->protocol !== 'any') ? $dbRule->protocol : 'tcp';
            $targetSpec = "{$dbRule->port}/{$proto}";
        }

        $this->firewallDriver->deleteRule($targetSpec);

        DB::transaction(function () use ($rule, $dbRule, $targetSpec, $actor, $ipAddress) {
            if ($dbRule) {
                $dbRule->delete();
            }

            ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
                'user_id' => $actor?->id,
                'action' => 'delete_firewall_rule',
                'command' => "lc-cli security ufw-delete {$targetSpec}",
                'status' => 'success',
                'ip_address' => $ipAddress,
                'details' => json_encode(['rule' => $rule, 'spec' => $targetSpec]),
            ]);
        });
    }
}
