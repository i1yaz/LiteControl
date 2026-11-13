<?php

namespace Tests\Feature;

use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\User;
use App\Support\Cli\LcCliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebAdminTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'username' => 'admin',
            'email' => 'admin@example.com',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->regularUser = User::factory()->create([
            'username' => 'client',
            'email' => 'client@example.com',
            'role' => 'user',
            'status' => 'active',
        ]);
    }

    public function test_non_admin_cannot_access_services_page(): void
    {
        $response = $this->actingAs($this->regularUser)->get(route('server.services'));
        $response->assertStatus(403);
    }

    public function test_admin_can_view_services_page_with_webadmin_data(): void
    {
        $response = $this->actingAs($this->adminUser)->get(route('server.services'));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Server/Services')
            ->has('services')
            ->has('webAdmin')
            ->where('webAdmin.port', 7080)
        );
    }

    public function test_admin_can_enable_webadmin_without_ips(): void
    {
        $response = $this->actingAs($this->adminUser)->post(route('server.webadmin.enable'), [
            'allowed_ips' => '',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->adminUser->id,
            'action' => 'webadmin_enabled',
            'status' => 'success',
        ]);
    }

    public function test_admin_can_enable_webadmin_with_whitelisted_ips(): void
    {
        $response = $this->actingAs($this->adminUser)->post(route('server.webadmin.enable'), [
            'allowed_ips' => '198.51.100.1, 203.0.113.0/24',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->adminUser->id,
            'action' => 'webadmin_enabled',
            'status' => 'success',
        ]);
    }

    public function test_admin_can_disable_webadmin(): void
    {
        $response = $this->actingAs($this->adminUser)->post(route('server.webadmin.disable'));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->adminUser->id,
            'action' => 'webadmin_disabled',
            'status' => 'success',
        ]);
    }

    public function test_regular_user_cannot_toggle_webadmin(): void
    {
        $responseEnable = $this->actingAs($this->regularUser)->post(route('server.webadmin.enable'), [
            'allowed_ips' => '198.51.100.1',
        ]);
        $responseEnable->assertStatus(403);

        $responseDisable = $this->actingAs($this->regularUser)->post(route('server.webadmin.disable'));
        $responseDisable->assertStatus(403);
    }

    public function test_lc_cli_service_handles_webadmin_commands_directly(): void
    {
        /** @var LcCliService $cli */
        $cli = app(LcCliService::class);

        // Status
        $resStatus = $cli->run('server', ['webadmin-status']);
        $this->assertEquals('success', $resStatus['status']);
        $this->assertArrayHasKey('enabled', $resStatus['data']);
        $this->assertEquals(7080, $resStatus['data']['port']);

        // Enable
        $resEnable = $cli->run('server', ['webadmin-enable', '192.168.1.100']);
        $this->assertEquals('success', $resEnable['status']);
        $this->assertTrue($resEnable['data']['enabled']);
        $this->assertEquals('192.168.1.100', $resEnable['data']['allowed_ips']);

        // Disable
        $resDisable = $cli->run('server', ['webadmin-disable']);
        $this->assertEquals('success', $resDisable['status']);
        $this->assertFalse($resDisable['data']['enabled']);
    }
}
