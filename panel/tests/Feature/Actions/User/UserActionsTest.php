<?php

namespace Tests\Feature\Actions\User;

use App\Actions\User\CreateUserAction;
use App\Actions\User\DeleteUserAction;
use App\Actions\User\SuspendUserAction;
use App\Actions\User\UnsuspendUserAction;
use App\Actions\User\UpdateUserAction;
use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Package;
use App\Models\User;
use App\Services\DomainService;
use App\Services\System\UserCliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class UserActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Package $package;
    protected Package $packagePro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->package = Package::create([
            'name' => 'Starter',
            'disk_quota_mb' => 2000,
            'bandwidth_mb' => 10000,
            'max_domains' => 2,
            'max_subdomains' => 2,
            'max_databases' => 2,
            'max_ftp_accounts' => 2,
        ]);

        $this->packagePro = Package::create([
            'name' => 'Pro',
            'disk_quota_mb' => 10000,
            'bandwidth_mb' => 50000,
            'max_domains' => 10,
            'max_subdomains' => 10,
            'max_databases' => 10,
            'max_ftp_accounts' => 10,
        ]);

        $this->admin = User::factory()->create([
            'username' => 'adminuser',
            'role' => 'admin',
        ]);
    }

    public function test_create_user_action_without_domain(): void
    {
        $cliMock = Mockery::mock(UserCliService::class);
        $cliMock->shouldReceive('createUser')
            ->once()
            ->with('newclient', 'secret12345', 2000)
            ->andReturn(['username' => 'newclient']);

        $cliMock->shouldReceive('updateSshKey')
            ->once()
            ->with('newclient', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5')
            ->andReturn([]);

        $domainServiceMock = Mockery::mock(DomainService::class);
        $domainServiceMock->shouldNotReceive('createDomain');

        $action = new CreateUserAction($cliMock, $domainServiceMock);

        $result = $action->handle([
            'username' => 'newclient',
            'name' => 'New Client',
            'email' => 'client@test.local',
            'password' => 'secret12345',
            'package_id' => $this->package->id,
            'ssh_public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5',
        ], $this->admin, '127.0.0.1');

        $this->assertInstanceOf(User::class, $result['user']);
        $this->assertFalse($result['domainCreated']);
        $this->assertNull($result['warning']);

        $this->assertDatabaseHas('users', [
            'username' => 'newclient',
            'email' => 'client@test.local',
            'package_id' => $this->package->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'user.create',
        ]);
    }

    public function test_create_user_action_with_primary_domain(): void
    {
        $cliMock = Mockery::mock(UserCliService::class);
        $cliMock->shouldReceive('createUser')
            ->once()
            ->with('clientwithdom', 'secret12345', 2000)
            ->andReturn(['username' => 'clientwithdom']);

        $domainServiceMock = Mockery::mock(DomainService::class);
        $domainServiceMock->shouldReceive('createDomain')
            ->once()
            ->with(
                Mockery::type(User::class),
                Mockery::on(function ($data) {
                    return $data['domain'] === 'example.org' && $data['type'] === 'primary';
                }),
                $this->admin
            )
            ->andReturn(['warning' => null]);

        $action = new CreateUserAction($cliMock, $domainServiceMock);

        $result = $action->handle([
            'username' => 'clientwithdom',
            'name' => 'Domain User',
            'email' => 'domainuser@test.local',
            'password' => 'secret12345',
            'package_id' => $this->package->id,
            'domain' => 'example.org',
            'php_version' => '83',
            'ssl_enabled' => true,
        ], $this->admin, '127.0.0.1');

        $this->assertTrue($result['domainCreated']);
        $this->assertNull($result['warning']);
    }

    public function test_create_user_action_throws_on_cli_failure(): void
    {
        $cliMock = Mockery::mock(UserCliService::class);
        $cliMock->shouldReceive('createUser')
            ->once()
            ->with('failuser', 'secret12345', 2000)
            ->andThrow(new LcCliException('System user already exists', 'user', ['add']));

        $domainServiceMock = Mockery::mock(DomainService::class);

        $action = new CreateUserAction($cliMock, $domainServiceMock);

        $this->expectException(LcCliException::class);
        $this->expectExceptionMessage('System user already exists');

        $action->handle([
            'username' => 'failuser',
            'name' => 'Fail User',
            'email' => 'fail@test.local',
            'password' => 'secret12345',
            'package_id' => $this->package->id,
        ], $this->admin);

        $this->assertDatabaseMissing('users', ['username' => 'failuser']);
    }

    public function test_update_user_action_modifies_quota_and_key(): void
    {
        $user = User::factory()->create([
            'username' => 'edituser',
            'name' => 'Old Name',
            'email' => 'old@test.local',
            'package_id' => $this->package->id,
            'ssh_public_key' => 'old-key',
        ]);

        $cliMock = Mockery::mock(UserCliService::class);
        $cliMock->shouldReceive('updateQuota')
            ->once()
            ->with('edituser', 10000)
            ->andReturn([]);

        $cliMock->shouldReceive('updateSshKey')
            ->once()
            ->with('edituser', 'new-key')
            ->andReturn([]);

        $action = new UpdateUserAction($cliMock);

        $action->handle($user, [
            'name' => 'New Name',
            'email' => 'new@test.local',
            'package_id' => $this->packagePro->id,
            'ssh_public_key' => 'new-key',
        ], $this->admin, '127.0.0.1');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'email' => 'new@test.local',
            'package_id' => $this->packagePro->id,
            'ssh_public_key' => 'new-key',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.update',
        ]);
    }

    public function test_suspend_user_action(): void
    {
        $user = User::factory()->create([
            'username' => 'suspendee',
            'status' => 'active',
            'role' => 'user',
        ]);

        $cliMock = Mockery::mock(UserCliService::class);
        $cliMock->shouldReceive('suspendUser')
            ->once()
            ->with('suspendee')
            ->andReturn([]);

        $action = new SuspendUserAction($cliMock);
        $action->handle($user, $this->admin, '127.0.0.1');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'suspended',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.suspend',
        ]);
    }

    public function test_unsuspend_user_action(): void
    {
        $user = User::factory()->create([
            'username' => 'suspendeduser',
            'status' => 'suspended',
            'role' => 'user',
        ]);

        $cliMock = Mockery::mock(UserCliService::class);
        $cliMock->shouldReceive('unsuspendUser')
            ->once()
            ->with('suspendeduser')
            ->andReturn([]);

        $action = new UnsuspendUserAction($cliMock);
        $action->handle($user, $this->admin, '127.0.0.1');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.unsuspend',
        ]);
    }

    public function test_delete_user_action(): void
    {
        $user = User::factory()->create([
            'username' => 'deleteme',
            'role' => 'user',
        ]);

        $domain = Domain::create([
            'user_id' => $user->id,
            'domain' => 'deleteme.org',
            'type' => 'primary',
            'document_root' => '/home/deleteme/deleteme.org/public_html',
            'php_version' => '83',
            'status' => 'active',
        ]);

        $cliMock = Mockery::mock(UserCliService::class);
        $cliMock->shouldReceive('deleteUser')
            ->once()
            ->with('deleteme', true)
            ->andReturn([]);

        $action = new DeleteUserAction($cliMock);
        $action->handle($user, $this->admin, '127.0.0.1');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('domains', ['id' => $domain->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.delete',
        ]);
    }
}

