<?php

namespace Tests\Feature\Domains\Security;

use App\Domains\Security\Actions\AddFirewallRuleAction;
use App\Domains\Security\Actions\DeleteFirewallRuleAction;
use App\Domains\Security\Actions\UpdatePanelPortAction;
use App\Domains\Security\Actions\UpdatePanelSslAction;
use App\Domains\Security\Actions\UpdateSshPortAction;
use App\Domains\Security\Contracts\FirewallDriverInterface;
use App\Domains\Security\Models\FirewallRule;
use App\Domains\Security\ValueObjects\Port;
use App\Exceptions\System\LcCliException;
use App\Models\User;
use App\Services\System\PanelSslCliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SecurityActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'username' => 'securityadmin',
            'role' => 'admin',
        ]);
    }

    public function test_add_firewall_rule_action_success(): void
    {
        $firewallMock = Mockery::mock(FirewallDriverInterface::class);
        $firewallMock->shouldReceive('addRule')
            ->once()
            ->with('allow', '3000', 'tcp', '192.168.1.50')
            ->andReturn([]);

        $action = new AddFirewallRuleAction($firewallMock);
        $rule = $action->handle([
            'name' => 'Custom NodePort',
            'port' => '3000',
            'protocol' => 'tcp',
            'action' => 'allow',
            'source_ip' => '192.168.1.50',
        ], $this->admin, '10.0.0.1');

        $this->assertInstanceOf(FirewallRule::class, $rule);
        $this->assertDatabaseHas('firewall_rules', [
            'name' => 'Custom NodePort',
            'port' => '3000',
            'protocol' => 'tcp',
            'action' => 'allow',
            'source_ip' => '192.168.1.50',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'create_firewall_rule',
            'command' => 'lc-cli security ufw-allow 3000',
            'status' => 'success',
            'ip_address' => '10.0.0.1',
        ]);
    }

    public function test_add_firewall_rule_action_throws_on_failure(): void
    {
        $firewallMock = Mockery::mock(FirewallDriverInterface::class);
        $firewallMock->shouldReceive('addRule')
            ->once()
            ->andThrow(new LcCliException('Failed to apply rule', 'security', ['ufw-allow', '9999']));

        $action = new AddFirewallRuleAction($firewallMock);

        $this->expectException(LcCliException::class);
        $this->expectExceptionMessage('Failed to apply rule');

        $action->handle([
            'name' => 'FailPort',
            'port' => '9999',
            'protocol' => 'tcp',
            'action' => 'allow',
            'source_ip' => null,
        ], $this->admin);

        $this->assertDatabaseMissing('firewall_rules', ['name' => 'FailPort']);
    }

    public function test_delete_firewall_rule_action_success(): void
    {
        $rule = FirewallRule::create([
            'name' => 'To Delete',
            'port' => '4000',
            'protocol' => 'tcp',
            'action' => 'allow',
            'source_ip' => 'Anywhere',
        ]);

        $firewallMock = Mockery::mock(FirewallDriverInterface::class);
        $firewallMock->shouldReceive('deleteRule')
            ->once()
            ->with((string) $rule->id)
            ->andReturn([]);

        $action = new DeleteFirewallRuleAction($firewallMock);
        $action->handle($rule->id, $this->admin, '10.0.0.2');

        $this->assertDatabaseMissing('firewall_rules', ['id' => $rule->id]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'delete_firewall_rule',
            'command' => "lc-cli security ufw-delete {$rule->id}",
            'status' => 'success',
            'ip_address' => '10.0.0.2',
        ]);
    }

    public function test_update_panel_port_action_success(): void
    {
        $firewallMock = Mockery::mock(FirewallDriverInterface::class);
        $firewallMock->shouldReceive('updatePanelPort')
            ->once()
            ->with(Mockery::on(fn (Port $p) => $p->value === 8443))
            ->andReturn(['old_port' => 8090, 'new_port' => 8443]);

        $action = new UpdatePanelPortAction($firewallMock);
        $result = $action->handle(Port::fromInt(8443), $this->admin, '10.0.0.3');

        $this->assertEquals(8443, $result['new_port']);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'change_panel_port',
            'command' => 'lc-cli security panel-port 8443',
            'status' => 'success',
            'ip_address' => '10.0.0.3',
        ]);
    }

    public function test_update_ssh_port_action_success(): void
    {
        $firewallMock = Mockery::mock(FirewallDriverInterface::class);
        $firewallMock->shouldReceive('updateSshPort')
            ->once()
            ->with(Mockery::on(fn (Port $p) => $p->value === 2222))
            ->andReturn(['old_port' => 22, 'new_port' => 2222]);

        $action = new UpdateSshPortAction($firewallMock);
        $result = $action->handle(Port::fromInt(2222), $this->admin, '10.0.0.4');

        $this->assertEquals(2222, $result['new_port']);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'change_ssh_port',
            'command' => 'lc-cli security ssh-port 2222',
            'status' => 'success',
            'ip_address' => '10.0.0.4',
        ]);
    }

    public function test_update_panel_ssl_action_letsencrypt(): void
    {
        $sslMock = Mockery::mock(PanelSslCliService::class);
        $sslMock->shouldReceive('issuePanelSsl')
            ->once()
            ->with('panel.mydomain.com')
            ->andReturn(['panel_ssl' => true]);

        $action = new UpdatePanelSslAction($sslMock);
        $result = $action->handle([
            'type' => 'letsencrypt',
            'domain' => 'panel.mydomain.com',
        ], $this->admin, '10.0.0.5');

        $this->assertEquals(['panel_ssl' => true], $result);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'update_panel_ssl',
            'command' => 'lc-cli ssl panel-ssl',
            'status' => 'success',
            'ip_address' => '10.0.0.5',
        ]);
    }

    public function test_update_panel_ssl_action_custom(): void
    {
        $sslMock = Mockery::mock(PanelSslCliService::class);
        $sslMock->shouldReceive('installCustomPanelSsl')
            ->once()
            ->with('MOCKCERT', 'MOCKKEY')
            ->andReturn(['panel_ssl' => true]);

        $action = new UpdatePanelSslAction($sslMock);
        $result = $action->handle([
            'type' => 'custom',
            'certificate' => 'MOCKCERT',
            'private_key' => 'MOCKKEY',
        ], $this->admin, '10.0.0.6');

        $this->assertEquals(['panel_ssl' => true], $result);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'update_panel_ssl',
            'command' => 'lc-cli ssl panel-ssl',
            'status' => 'success',
            'ip_address' => '10.0.0.6',
        ]);
    }
}

