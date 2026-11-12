<?php

namespace Tests\Feature\Actions\Domain;

use App\Actions\Domain\DeleteDomainAction;
use App\Actions\Domain\CreateSubdomainAction;
use App\Actions\Domain\FixDomainPermissionsAction;
use App\Actions\Domain\UpdateVhostConfAction;
use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Package;
use App\Models\User;
use App\Services\System\VhostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DomainActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $package = Package::create([
            'name' => 'Basic',
            'disk_quota_mb' => 1000,
            'bandwidth_mb' => 5000,
            'max_domains' => 5,
            'max_subdomains' => 5,
            'max_databases' => 2,
            'max_ftp_accounts' => 2,
        ]);

        $this->user = User::factory()->create([
            'package_id' => $package->id,
            'username' => 'testuser',
        ]);

        $this->domain = Domain::create([
            'user_id' => $this->user->id,
            'domain' => 'example.com',
            'type' => 'primary',
            'php_version' => '83',
            'document_root' => '/home/testuser/example.com/public_html',
            'status' => 'active',
        ]);
    }

    public function test_delete_domain_action_removes_vhost_and_deletes_record(): void
    {
        $vhostMock = Mockery::mock(VhostService::class);
        $vhostMock->shouldReceive('remove')
            ->once()
            ->with('example.com', true)
            ->andReturn([]);

        $action = new DeleteDomainAction($vhostMock);
        $action->handle($this->domain, $this->user, true);

        $this->assertDatabaseMissing('domains', ['id' => $this->domain->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'domain.delete',
        ]);
    }

    public function test_update_vhost_conf_action(): void
    {
        $vhostMock = Mockery::mock(VhostService::class);
        $vhostMock->shouldReceive('setConf')
            ->once()
            ->with('example.com', 'server { listen 80; }')
            ->andReturn(['status' => 'success']);

        $action = new UpdateVhostConfAction($vhostMock);
        $res = $action->handle($this->domain, 'server { listen 80; }', $this->user);

        $this->assertEquals(['status' => 'success'], $res);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'domain.vhost_conf_update',
        ]);
    }

    public function test_fix_domain_permissions_action(): void
    {
        $vhostMock = Mockery::mock(VhostService::class);
        $vhostMock->shouldReceive('fixPermissions')
            ->once()
            ->with('example.com')
            ->andReturn(['status' => 'success']);

        $action = new FixDomainPermissionsAction($vhostMock);
        $res = $action->handle($this->domain, $this->user);

        $this->assertEquals(['status' => 'success'], $res);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'domain.fix_permissions',
        ]);
    }

    public function test_create_subdomain_action_success(): void
    {
        $vhostMock = Mockery::mock(VhostService::class);
        $vhostMock->shouldReceive('create')
            ->once()
            ->with(
                'blog.example.com',
                'testuser',
                '83',
                '/home/testuser/blog.example.com/public_html'
            )
            ->andReturn(['status' => 'success']);

        $action = new CreateSubdomainAction($vhostMock);
        $sub = $action->handle($this->domain, 'blog', $this->user);

        $this->assertDatabaseHas('domains', [
            'id' => $sub->id,
            'domain' => 'blog.example.com',
            'type' => 'subdomain',
            'parent_domain_id' => $this->domain->id,
        ]);
    }

    public function test_create_subdomain_action_throws_on_vhost_failure(): void
    {
        $vhostMock = Mockery::mock(VhostService::class);
        $vhostMock->shouldReceive('create')
            ->once()
            ->andThrow(new LcCliException('Failed creation', 'vhost', []));

        $this->expectException(\RuntimeException::class);

        $action = new CreateSubdomainAction($vhostMock);
        $action->handle($this->domain, 'blog', $this->user);
    }
}
