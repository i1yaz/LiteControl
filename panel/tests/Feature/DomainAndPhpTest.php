<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Package;
use App\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

class DomainAndPhpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_user_can_view_domains_list(): void
    {
        $client = User::where('username', 'client1')->first();

        $response = $this->actingAs($client)->get('/domains');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Domains/Index')
            ->has('domains.data')
            ->has('phpVersions')
        );
    }

    public function test_user_can_add_domain_within_package_limits(): void
    {
        $client = User::where('username', 'client1')->first();

        $response = $this->actingAs($client)->post('/domains', [
            'domain' => 'mybrandnewsite.com',
            'php_version' => '83',
            'type' => 'addon',
        ]);

        $response->assertRedirect('/domains');
        $this->assertDatabaseHas('domains', [
            'domain' => 'mybrandnewsite.com',
            'user_id' => $client->id,
            'php_version' => '83',
        ]);
    }

    public function test_domain_creation_enforces_package_max_domains_limit(): void
    {
        $client = User::where('username', 'client1')->first();
        // Set package limit to 1 domain
        $client->package->update(['max_domains' => 1]);

        // Client already has client1.com
        $response = $this->actingAs($client)->post('/domains', [
            'domain' => 'overflowdomain.com',
            'php_version' => '83',
        ]);

        $response->assertSessionHasErrors('domain');
        $this->assertDatabaseMissing('domains', ['domain' => 'overflowdomain.com']);
    }

    public function test_user_can_switch_domain_php_version(): void
    {
        $client = User::where('username', 'client1')->first();
        $domain = Domain::where('user_id', $client->id)->first();

        $response = $this->actingAs($client)->post("/domains/{$domain->id}/php", [
            'php_version' => '82',
        ]);

        $response->assertStatus(302);
        $this->assertEquals('82', $domain->fresh()->php_version);
    }

    public function test_user_cannot_delete_another_users_domain(): void
    {
        $client = User::where('username', 'client1')->first();
        $admin = User::where('role', 'admin')->first();

        $otherDomain = Domain::create([
            'user_id' => $admin->id,
            'domain' => 'admindomain.com',
            'type' => 'primary',
            'document_root' => '/home/admin/admindomain.com/public_html',
            'php_version' => '83',
            'ssl_enabled' => false,
            'status' => 'active',
        ]);

        $response = $this->actingAs($client)->delete("/domains/{$otherDomain->id}");
        $response->assertStatus(403);
    }

    public function test_user_can_create_subdomain(): void
    {
        $client = User::where('username', 'client1')->first();
        $parent = Domain::where('user_id', $client->id)->where('type', 'primary')->first();

        $response = $this->actingAs($client)->post('/subdomains', [
            'subdomain_prefix' => 'api',
            'parent_domain_id' => $parent->id,
        ]);

        $response->assertRedirect('/subdomains');
        $expectedFqdn = 'api.' . $parent->domain;
        $this->assertDatabaseHas('domains', [
            'domain' => $expectedFqdn,
            'type' => 'subdomain',
            'user_id' => $client->id,
        ]);
    }

    public function test_admin_can_view_and_update_php_ini_directives(): void
    {
        $admin = User::where('role', 'admin')->first();

        $viewRes = $this->actingAs($admin)->get('/php/ini?version=83');
        $viewRes->assertStatus(200);
        $viewRes->assertInertia(fn (Assert $page) => $page
            ->component('Php/IniEditor')
            ->where('currentVersion', '83')
            ->has('iniData.directives')
        );

        $updateRes = $this->actingAs($admin)->post('/php/ini', [
            'version' => '83',
            'directives' => [
                'memory_limit' => '512M',
                'upload_max_filesize' => '128M',
            ],
        ]);

        $updateRes->assertStatus(302);
    }

    public function test_non_admin_cannot_update_php_ini(): void
    {
        $client = User::where('role', 'user')->first();

        $response = $this->actingAs($client)->post('/php/ini', [
            'version' => '83',
            'directives' => ['memory_limit' => '1024M'],
        ]);

        $response->assertStatus(403);
    }

    public function test_multiphp_manager_batch_switching(): void
    {
        $admin = User::where('role', 'admin')->first();
        $domains = Domain::take(2)->get();

        $response = $this->actingAs($admin)->post('/php/manager', [
            'domain_ids' => $domains->pluck('id')->toArray(),
            'php_version' => '81',
        ]);

        $response->assertStatus(302);
        foreach ($domains as $d) {
            $this->assertEquals('81', $d->fresh()->php_version);
        }
    }

    public function test_admin_can_toggle_php_extension(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->post('/php/extensions/toggle', [
            'version' => '83',
            'extension' => 'imagick',
            'action' => 'enable',
        ]);

        $response->assertStatus(302);
    }
}
