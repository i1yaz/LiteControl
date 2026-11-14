<?php

namespace Tests\Feature;

use App\Domains\HostingPlans\Models\Package;
use App\Domains\Identity\Models\User;
use App\Domains\WebServer\Models\Domain;
use App\Domains\WebServer\Models\FtpAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainManageAndOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $client;

    protected Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $package = Package::create([
            'name' => 'Starter',
            'disk_quota_mb' => 1000,
            'bandwidth_mb' => 10000,
            'max_domains' => 2,
            'max_subdomains' => 5,
            'max_databases' => 3,
            'max_ftp_accounts' => 3,
            'max_email_accounts' => 5,
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'username' => 'admin',
            'email' => 'admin@test.local',
            'password' => 'secret123',
            'role' => 'admin',
            'package_id' => $package->id,
            'status' => 'active',
        ]);

        $this->client = User::create([
            'name' => 'Client One',
            'username' => 'client1',
            'email' => 'client1@test.local',
            'password' => 'secret123',
            'role' => 'user',
            'package_id' => $package->id,
            'status' => 'active',
        ]);

        $this->domain = Domain::create([
            'user_id' => $this->client->id,
            'domain' => 'mycoolsite.com',
            'type' => 'primary',
            'document_root' => '/home/client1/mycoolsite.com/public_html',
            'php_version' => '83',
            'ssl_enabled' => false,
            'status' => 'active',
        ]);
    }

    public function test_user_can_access_manage_page(): void
    {
        $response = $this->actingAs($this->client)->get("/domains/{$this->domain->id}/manage");
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Domains/Manage')
            ->has('domain')
            ->where('domain.domain', 'mycoolsite.com')
        );
    }

    public function test_suspend_and_unsuspend_domain(): void
    {
        $response = $this->actingAs($this->client)->post("/domains/{$this->domain->id}/suspend");
        $response->assertRedirect();
        $this->domain->refresh();
        $this->assertEquals('suspended', $this->domain->status);

        $response = $this->actingAs($this->client)->post("/domains/{$this->domain->id}/unsuspend");
        $response->assertRedirect();
        $this->domain->refresh();
        $this->assertEquals('active', $this->domain->status);
    }

    public function test_add_and_remove_domain_alias(): void
    {
        $response = $this->actingAs($this->client)->post("/domains/{$this->domain->id}/aliases", [
            'alias' => 'alias.mycoolsite.com',
        ]);
        $response->assertRedirect();

        $alias = Domain::where('domain', 'alias.mycoolsite.com')->first();
        $this->assertNotNull($alias);
        $this->assertEquals('alias', $alias->type);
        $this->assertEquals($this->domain->id, $alias->parent_domain_id);

        $delResponse = $this->actingAs($this->client)->delete("/domains/{$this->domain->id}/aliases/{$alias->id}");
        $delResponse->assertRedirect();
        $this->assertDatabaseMissing('domains', ['domain' => 'alias.mycoolsite.com']);
    }

    public function test_vhost_conf_endpoint(): void
    {
        $response = $this->actingAs($this->client)->get("/domains/{$this->domain->id}/vhost-conf");
        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'domain', 'conf']);
    }

    public function test_htaccess_get_and_save(): void
    {
        $getResponse = $this->actingAs($this->client)->get("/domains/{$this->domain->id}/htaccess");
        $getResponse->assertStatus(200);
        $getResponse->assertJsonStructure(['status', 'content', 'exists']);

        $saveResponse = $this->actingAs($this->client)->post("/domains/{$this->domain->id}/htaccess", [
            'content' => "RewriteEngine On\nRewriteRule ^test$ index.php [L]",
        ]);
        $saveResponse->assertRedirect();
    }

    public function test_create_and_delete_ftp_account(): void
    {
        $response = $this->actingAs($this->client)->post("/domains/{$this->domain->id}/ftp", [
            'username' => 'testftp',
            'password' => 'secret1234',
            'dir' => 'public_html',
            'quota_mb' => 100,
        ]);
        $response->assertRedirect();

        $ftp = FtpAccount::where('username', 'client1_testftp')->first();
        $this->assertNotNull($ftp);
        $this->assertEquals($this->domain->id, $ftp->domain_id);

        $delResponse = $this->actingAs($this->client)->delete("/domains/{$this->domain->id}/ftp/{$ftp->id}");
        $delResponse->assertRedirect();
        $this->assertDatabaseMissing('ftp_accounts', ['username' => 'client1_testftp']);
    }

    public function test_save_vhost_conf_endpoint(): void
    {
        $response = $this->actingAs($this->client)->postJson("/domains/{$this->domain->id}/vhost-conf", [
            'conf' => "docRoot /home/client1/example.com/public_html\nvhDomain example.com\n",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'VirtualHost configuration validated and saved successfully.',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->client->id,
            'action' => 'domain.vhost_conf_update',
        ]);
    }

    public function test_read_logs_endpoint(): void
    {
        $response = $this->actingAs($this->client)->get("/domains/{$this->domain->id}/logs?type=access&page=1");
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'log_type',
            'lines',
            'has_next',
            'has_prev',
            'page',
            'per_page',
        ]);
    }
}
