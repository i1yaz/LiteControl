<?php

use App\Domains\Identity\Models\User;
use App\Domains\Security\Actions\AddFirewallRuleAction;
use App\Domains\Security\Actions\DeleteFirewallRuleAction;
use App\Domains\Security\Contracts\FirewallDriverInterface;
use App\Domains\Security\Models\FirewallRule;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin', 'username' => 'adminuser']);
});

it('passes rule name as comment when adding a firewall rule', function () {
    $driver = Mockery::mock(FirewallDriverInterface::class);
    $driver->shouldReceive('addRule')
        ->once()
        ->with('allow', '8080', 'tcp', null, 'Temporary port for testing')
        ->andReturn(['status' => 'success']);

    $auditLogger = Mockery::mock(AuditLoggerInterface::class);
    $auditLogger->shouldReceive('log')->once();

    $action = new AddFirewallRuleAction($driver, $auditLogger);
    $rule = $action->handle([
        'name' => 'Temporary port for testing',
        'port' => '8080',
        'protocol' => 'tcp',
        'action' => 'allow',
        'source_ip' => null,
    ], $this->admin, '127.0.0.1');

    expect($rule->name)->toBe('Temporary port for testing');
    expect($rule->port)->toBe('8080');
});

it('deletes firewall rule in ufw using port/protocol spec instead of db id', function () {
    $dbRule = FirewallRule::create([
        'name' => 'Temporary port for testing',
        'port' => '8080',
        'protocol' => 'tcp',
        'action' => 'allow',
        'source_ip' => 'Anywhere',
    ]);

    $driver = Mockery::mock(FirewallDriverInterface::class);
    $driver->shouldReceive('deleteRule')
        ->once()
        ->with('8080/tcp')
        ->andReturn(['status' => 'success']);

    $auditLogger = Mockery::mock(AuditLoggerInterface::class);
    $auditLogger->shouldReceive('log')->once();

    $action = new DeleteFirewallRuleAction($driver, $auditLogger);
    $action->handle($dbRule->id, $this->admin, '127.0.0.1');

    expect(FirewallRule::find($dbRule->id))->toBeNull();
});

it('syncs and removes stale db firewall rules on ufw page refresh', function () {
    $activeRule = FirewallRule::create([
        'name' => 'SSH',
        'port' => '22',
        'protocol' => 'tcp',
        'action' => 'allow',
        'source_ip' => 'Anywhere',
    ]);

    $staleRule = FirewallRule::create([
        'name' => 'Temporary port for testing',
        'port' => '8080',
        'protocol' => 'tcp',
        'action' => 'allow',
        'source_ip' => 'Anywhere',
    ]);

    $driver = Mockery::mock(FirewallDriverInterface::class);
    $driver->shouldReceive('getUfwStatus')
        ->once()
        ->andReturn([
            'active' => true,
            'rules' => [
                ['to' => '22/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere'],
            ],
        ]);
    $this->app->instance(FirewallDriverInterface::class, $driver);

    $this->actingAs($this->admin)
        ->get('/security/ufw')
        ->assertOk();

    expect(FirewallRule::find($activeRule->id))->not->toBeNull();
    expect(FirewallRule::find($staleRule->id))->toBeNull();
});
