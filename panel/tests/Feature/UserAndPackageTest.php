<?php

namespace Tests\Feature;

use App\Domains\HostingPlans\Models\Package;
use App\Domains\Identity\Models\User;
use App\Domains\WebServer\Models\Domain;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserAndPackageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->seed(DemoDataSeeder::class);
    }

    public function test_admin_can_view_users_list(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->get('/users');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Users/Index')
            ->has('users.data')
            ->has('packages')
        );
    }

    public function test_regular_user_cannot_access_users_management(): void
    {
        $client = User::where('role', 'user')->first();

        $response = $this->actingAs($client)->get('/users');
        $response->assertStatus(403);

        $createResponse = $this->actingAs($client)->get('/users/create');
        $createResponse->assertStatus(403);
    }

    public function test_admin_can_create_new_hosting_user(): void
    {
        $admin = User::where('role', 'admin')->first();
        $package = Package::first();

        $response = $this->actingAs($admin)->post('/users', [
            'username' => 'newuser',
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'secret123456',
            'package_id' => $package->id,
            'domain' => 'newuserdomain.com',
            'php_version' => '83',
        ]);

        $response->assertRedirect('/users');
        $this->assertDatabaseHas('users', [
            'username' => 'newuser',
            'email' => 'newuser@example.com',
            'role' => 'user',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('domains', [
            'domain' => 'newuserdomain.com',
            'type' => 'primary',
            'php_version' => '83',
        ]);
    }

    public function test_admin_can_create_hosting_user_without_domain_and_with_ssh_key(): void
    {
        $admin = User::where('role', 'admin')->first();
        $package = Package::first();

        $response = $this->actingAs($admin)->post('/users', [
            'username' => 'nodomainuser',
            'name' => 'No Domain User',
            'email' => 'nodomain@example.com',
            'password' => 'secret123456',
            'package_id' => $package->id,
            'ssh_public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIG1234567890 testing@host',
        ]);

        $response->assertRedirect('/users');
        $this->assertDatabaseHas('users', [
            'username' => 'nodomainuser',
            'email' => 'nodomain@example.com',
            'ssh_public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIG1234567890 testing@host',
        ]);

        $createdUser = User::where('username', 'nodomainuser')->first();
        $this->assertEquals(0, Domain::where('user_id', $createdUser->id)->count());
    }

    public function test_admin_can_update_user_profile_and_ssh_key(): void
    {
        $admin = User::where('role', 'admin')->first();
        $targetUser = User::where('username', 'client1')->first();
        $package = Package::first();

        $response = $this->actingAs($admin)->put("/users/{$targetUser->id}", [
            'name' => 'Demo User Updated',
            'email' => 'demouser_updated@example.com',
            'package_id' => $package->id,
            'ssh_public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGupdatedkey demo@host',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', [
            'id' => $targetUser->id,
            'name' => 'Demo User Updated',
            'email' => 'demouser_updated@example.com',
            'ssh_public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGupdatedkey demo@host',
        ]);
    }

    public function test_user_creation_rejects_reserved_usernames(): void
    {
        $admin = User::where('role', 'admin')->first();
        $package = Package::first();

        $response = $this->actingAs($admin)->post('/users', [
            'username' => 'root',
            'name' => 'Root Attempter',
            'email' => 'root@example.com',
            'password' => 'secret123456',
            'package_id' => $package->id,
            'domain' => 'rootdomain.com',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertDatabaseMissing('users', ['username' => 'root']);
    }

    public function test_admin_can_suspend_and_unsuspend_user(): void
    {
        $admin = User::where('role', 'admin')->first();
        $client = User::where('username', 'client1')->first();

        $this->assertEquals('active', $client->status);

        // Suspend
        $suspendRes = $this->actingAs($admin)->post("/users/{$client->id}/suspend");
        $suspendRes->assertStatus(302);
        $this->assertEquals('suspended', $client->fresh()->status);

        // Unsuspend
        $unsuspendRes = $this->actingAs($admin)->post("/users/{$client->id}/unsuspend");
        $unsuspendRes->assertStatus(302);
        $this->assertEquals('active', $client->fresh()->status);
    }

    public function test_admin_cannot_suspend_admin_account(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->post("/users/{$admin->id}/suspend");
        $response->assertSessionHas('error');
        $this->assertEquals('active', $admin->fresh()->status);
    }

    public function test_admin_can_delete_user(): void
    {
        $admin = User::where('role', 'admin')->first();
        $client = User::where('username', 'client1')->first();

        $response = $this->actingAs($admin)->delete("/users/{$client->id}");

        $response->assertRedirect('/users');
        $this->assertDatabaseMissing('users', ['id' => $client->id]);
    }

    public function test_admin_can_impersonate_user_and_revert(): void
    {
        $admin = User::where('role', 'admin')->first();
        $client = User::where('username', 'client1')->first();

        // 1. Impersonate
        $impRes = $this->actingAs($admin)->post("/users/{$client->id}/impersonate");
        $impRes->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($client);
        $this->assertEquals($admin->id, session('impersonator_id'));

        // 2. Revert
        $revertRes = $this->post('/impersonation/revert');
        $revertRes->assertRedirect('/users');
        $this->assertAuthenticatedAs($admin);
        $this->assertFalse(session()->has('impersonator_id'));
    }

    public function test_package_crud_and_assigned_users_protection(): void
    {
        $admin = User::where('role', 'admin')->first();

        // Create package
        $createRes = $this->actingAs($admin)->post('/packages', [
            'name' => 'Pro Package',
            'disk_quota_mb' => 5120,
            'bandwidth_mb' => 51200,
            'max_domains' => 10,
            'max_subdomains' => 20,
            'max_databases' => 10,
            'max_ftp_accounts' => 5,
            'max_email_accounts' => 15,
        ]);
        $createRes->assertRedirect('/packages');
        $this->assertDatabaseHas('packages', ['name' => 'Pro Package']);

        $pkg = Package::where('name', 'Pro Package')->first();

        // Update package
        $updateRes = $this->actingAs($admin)->put("/packages/{$pkg->id}", [
            'name' => 'Pro Plus Package',
            'disk_quota_mb' => 10240,
            'bandwidth_mb' => 102400,
            'max_domains' => 15,
            'max_subdomains' => 30,
            'max_databases' => 20,
            'max_ftp_accounts' => 10,
            'max_email_accounts' => 30,
        ]);
        $updateRes->assertRedirect('/packages');
        $this->assertEquals('Pro Plus Package', $pkg->fresh()->name);

        // Delete package without assigned users -> succeeds
        $deleteRes = $this->actingAs($admin)->delete("/packages/{$pkg->id}");
        $deleteRes->assertRedirect('/packages');
        $this->assertDatabaseMissing('packages', ['id' => $pkg->id]);

        // Attempting to delete package with assigned users -> fails
        $defaultPkg = Package::where('name', 'Default')->first();
        $failDeleteRes = $this->actingAs($admin)->delete("/packages/{$defaultPkg->id}");
        $failDeleteRes->assertSessionHas('error');
        $this->assertDatabaseHas('packages', ['id' => $defaultPkg->id]);
    }
}
