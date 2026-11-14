<?php

namespace Tests\Feature\Domains\HostingPlans;

use App\Domains\HostingPlans\Actions\CreatePackageAction;
use App\Domains\HostingPlans\Actions\DeletePackageAction;
use App\Domains\HostingPlans\Actions\UpdatePackageAction;
use App\Domains\HostingPlans\ValueObjects\PackageName;
use App\Domains\HostingPlans\ValueObjects\ResourceQuotas;
use App\Domains\Identity\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_name_validates_non_empty_and_max_length(): void
    {
        $name = new PackageName('  Starter Plan  ');
        $this->assertSame('Starter Plan', $name->value);

        $this->expectException(InvalidArgumentException::class);
        new PackageName('   ');
    }

    public function test_resource_quotas_validates_ranges(): void
    {
        $quotas = ResourceQuotas::fromArray([
            'disk_quota_mb' => 1024,
            'bandwidth_mb' => 10240,
            'max_domains' => 5,
            'max_subdomains' => 10,
            'max_databases' => 5,
            'max_ftp_accounts' => 5,
            'max_email_accounts' => 10,
        ]);

        $this->assertSame(1024, $quotas->diskQuotaMb);

        $this->expectException(InvalidArgumentException::class);
        ResourceQuotas::fromArray([
            'disk_quota_mb' => 50, // below min 100
            'bandwidth_mb' => 10240,
            'max_domains' => 5,
            'max_subdomains' => 10,
            'max_databases' => 5,
            'max_ftp_accounts' => 5,
            'max_email_accounts' => 10,
        ]);
    }

    public function test_create_update_and_delete_package_actions(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'username' => 'adminuser']);

        $createAction = new CreatePackageAction;
        $updateAction = new UpdatePackageAction;
        $deleteAction = new DeletePackageAction;

        $quotas = ResourceQuotas::fromArray([
            'disk_quota_mb' => 2048,
            'bandwidth_mb' => 20480,
            'max_domains' => 3,
            'max_subdomains' => 5,
            'max_databases' => 3,
            'max_ftp_accounts' => 3,
            'max_email_accounts' => 5,
        ]);

        $package = $createAction->handle(new PackageName('Basic'), $quotas, $admin->id, '127.0.0.1');
        $this->assertDatabaseHas('packages', ['name' => 'Basic', 'disk_quota_mb' => 2048]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'package.create', 'user_id' => $admin->id]);

        $updatedQuotas = ResourceQuotas::fromArray([
            'disk_quota_mb' => 4096,
            'bandwidth_mb' => 20480,
            'max_domains' => 5,
            'max_subdomains' => 5,
            'max_databases' => 3,
            'max_ftp_accounts' => 3,
            'max_email_accounts' => 5,
        ]);

        $updateAction->handle($package, new PackageName('Basic Plus'), $updatedQuotas, $admin->id, '127.0.0.1');
        $this->assertDatabaseHas('packages', ['id' => $package->id, 'name' => 'Basic Plus', 'disk_quota_mb' => 4096]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'package.update', 'user_id' => $admin->id]);

        $deletedName = $deleteAction->handle($package, $admin->id, '127.0.0.1');
        $this->assertSame('Basic Plus', $deletedName);
        $this->assertDatabaseMissing('packages', ['id' => $package->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'package.delete', 'user_id' => $admin->id]);
    }

    public function test_delete_package_action_throws_when_package_has_assigned_users(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'username' => 'adminuser2']);
        $createAction = new CreatePackageAction;
        $deleteAction = new DeletePackageAction;

        $quotas = ResourceQuotas::fromArray([
            'disk_quota_mb' => 1024,
            'bandwidth_mb' => 10240,
            'max_domains' => 1,
            'max_subdomains' => 0,
            'max_databases' => 0,
            'max_ftp_accounts' => 0,
            'max_email_accounts' => 0,
        ]);

        $package = $createAction->handle(new PackageName('Locked'), $quotas, $admin->id, '127.0.0.1');
        User::factory()->create(['package_id' => $package->id, 'username' => 'pkguser']);

        $this->expectException(DomainException::class);
        $deleteAction->handle($package, $admin->id, '127.0.0.1');
    }
}
