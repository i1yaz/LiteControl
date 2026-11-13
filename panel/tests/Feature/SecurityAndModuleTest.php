<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Domains\WebServer\Models\Domain;
use App\Domains\Security\Models\FirewallRule;
use App\Domains\PanelConfig\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

class SecurityAndModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->seed(\Database\Seeders\DemoDataSeeder::class);
    }

    public function test_user_and_admin_can_view_ssl_page(): void
    {
        $client = User::where('username', 'client1')->first();
        $admin = User::where('role', 'admin')->first();

        $clientRes = $this->actingAs($client)->get('/security/ssl');
        $clientRes->assertStatus(200);
        $clientRes->assertInertia(fn (Assert $page) => $page->component('Security/Ssl'));

        $adminRes = $this->actingAs($admin)->get('/security/ssl');
        $adminRes->assertStatus(200);
    }

    public function test_user_can_issue_letsencrypt_ssl_for_own_domain(): void
    {
        $client = User::where('username', 'client1')->first();
        $domain = Domain::where('user_id', $client->id)->first();

        $response = $this->actingAs($client)->post('/security/ssl/issue', [
            'domain_id' => $domain->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $domain->refresh();
        $this->assertTrue((bool) $domain->ssl_enabled);
        $this->assertNotNull($domain->ssl_expires_at);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $client->id,
            'action' => 'ssl_issue',
        ]);
    }

    public function test_user_cannot_issue_ssl_for_other_users_domain(): void
    {
        $client = User::where('username', 'client1')->first();
        $otherDomain = Domain::where('user_id', '!=', $client->id)->first();

        if ($otherDomain) {
            $response = $this->actingAs($client)->post('/security/ssl/issue', [
                'domain_id' => $otherDomain->id,
            ]);

            $response->assertStatus(403);
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_user_can_install_custom_ssl(): void
    {
        $client = User::where('username', 'client1')->first();
        $domain = Domain::where('user_id', $client->id)->first();

        $response = $this->actingAs($client)->post('/security/ssl/custom', [
            'domain_id' => $domain->id,
            'certificate' => "-----BEGIN CERTIFICATE-----\nMOCKCERT\n-----END CERTIFICATE-----",
            'private_key' => "-----BEGIN PRIVATE KEY-----\nMOCKKEY\n-----END PRIVATE KEY-----",
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $domain->refresh();
        $this->assertTrue((bool) $domain->ssl_enabled);
    }

    public function test_user_can_renew_ssl(): void
    {
        $client = User::where('username', 'client1')->first();
        $domain = Domain::where('user_id', $client->id)->first();

        $response = $this->actingAs($client)->post("/security/ssl/{$domain->id}/renew");

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_admin_can_view_and_manage_ufw_firewall(): void
    {
        $admin = User::where('role', 'admin')->first();

        $getRes = $this->actingAs($admin)->get('/security/ufw');
        $getRes->assertStatus(200);
        $getRes->assertInertia(fn (Assert $page) => $page
            ->component('Security/UfwFirewall')
            ->has('systemRules')
        );

        $addRes = $this->actingAs($admin)->post('/security/ufw/rules', [
            'name' => 'Custom NodePort',
            'port' => '3000',
            'protocol' => 'tcp',
            'action' => 'allow',
            'source_ip' => null,
        ]);

        $addRes->assertRedirect();
        $addRes->assertSessionHas('success');

        $this->assertDatabaseHas('firewall_rules', [
            'name' => 'Custom NodePort',
            'port' => '3000',
            'protocol' => 'tcp',
            'action' => 'allow',
        ]);

        $rule = FirewallRule::where('port', '3000')->first();
        $delRes = $this->actingAs($admin)->delete("/security/ufw/rules/{$rule->id}");
        $delRes->assertRedirect();
        $this->assertDatabaseMissing('firewall_rules', ['id' => $rule->id]);
    }

    public function test_non_admin_cannot_access_ufw_firewall(): void
    {
        $client = User::where('username', 'client1')->first();

        $response = $this->actingAs($client)->get('/security/ufw');
        $response->assertStatus(403);
    }

    public function test_admin_can_view_port_firewall(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->get('/security/port');
        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Security/PortFirewall')
            ->has('standardPorts')
        );
    }

    public function test_admin_can_change_panel_and_ssh_ports(): void
    {
        $admin = User::where('role', 'admin')->first();

        $panelRes = $this->actingAs($admin)->post('/security/panel-port', [
            'port' => 8095,
        ]);
        $panelRes->assertRedirect();
        $panelRes->assertSessionHas('success');

        $sshRes = $this->actingAs($admin)->post('/security/ssh-port', [
            'port' => 2222,
        ]);
        $sshRes->assertRedirect();
        $sshRes->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'change_panel_port',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'change_ssh_port',
        ]);
    }

    public function test_admin_can_configure_panel_ssl(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->post('/security/panel-ssl', [
            'type' => 'letsencrypt',
            'domain' => 'panel.example.com',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_user_can_update_own_password(): void
    {
        $client = User::where('username', 'client1')->first();

        $response = $this->actingAs($client)->post('/security/password', [
            'current_password' => 'client123456',
            'password' => 'BrandNewStrongPass123!',
            'password_confirmation' => 'BrandNewStrongPass123!',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $client->refresh();
        $this->assertTrue(Hash::check('BrandNewStrongPass123!', $client->password));
    }

    public function test_user_can_toggle_two_factor_auth(): void
    {
        $client = User::where('username', 'client1')->first();

        // Enable 2FA via TOTP confirmation
        $this->actingAs($client)->get('/security/two-factor')->assertOk();
        $secret = session('two_factor.pending_secret');
        $code = app(\App\Services\TotpService::class)->getCode($secret);

        $res1 = $this->actingAs($client)->post('/security/two-factor/confirm', [
            'code' => $code,
        ]);
        $res1->assertRedirect();

        $client->refresh();
        $this->assertNotNull($client->two_factor_confirmed_at);

        // Disable 2FA
        $res2 = $this->actingAs($client)->post('/security/two-factor/toggle');
        $res2->assertRedirect();

        $client->refresh();
        $this->assertNull($client->two_factor_confirmed_at);
    }

    public function test_admin_can_view_security_module_pages(): void
    {
        $admin = User::where('role', 'admin')->first();

        $csfRes = $this->actingAs($admin)->get('/security/csf');
        $csfRes->assertStatus(200);
        $csfRes->assertInertia(fn (Assert $page) => $page->component('Security/CsfFirewall'));

        $modSecRes = $this->actingAs($admin)->get('/security/modsecurity');
        $modSecRes->assertStatus(200);
        $modSecRes->assertInertia(fn (Assert $page) => $page->component('Security/ModSecurity'));

        $imunifyRes = $this->actingAs($admin)->get('/security/imunifyav');
        $imunifyRes->assertStatus(200);
        $imunifyRes->assertInertia(fn (Assert $page) => $page->component('Security/ImunifyAv'));
    }

    public function test_admin_can_view_and_manage_modules(): void
    {
        $admin = User::where('role', 'admin')->first();

        $getRes = $this->actingAs($admin)->get('/modules');
        $getRes->assertStatus(200);
        $getRes->assertInertia(fn (Assert $page) => $page
            ->component('Modules/Index')
            ->has('modules')
        );

        $installRes = $this->actingAs($admin)->post('/modules/csf/install');
        $installRes->assertRedirect();
        $installRes->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'install_module',
        ]);

        $removeRes = $this->actingAs($admin)->post('/modules/csf/remove');
        $removeRes->assertRedirect();
        $removeRes->assertSessionHas('success');
    }

    public function test_module_installer_rejects_unsupported_modules(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->post('/modules/unknown_module_xyz/install');

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }
}
