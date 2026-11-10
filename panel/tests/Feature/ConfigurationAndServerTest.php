<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

class ConfigurationAndServerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->seed(\Database\Seeders\DemoDataSeeder::class);
    }

    public function test_admin_can_view_settings_page(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->get('/configuration/settings');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Configuration/Settings')
            ->has('settings.panel_name')
            ->has('settings.server_ip')
        );
    }

    public function test_non_admin_cannot_view_settings_page(): void
    {
        $client = User::where('username', 'client1')->first();

        $response = $this->actingAs($client)->get('/configuration/settings');

        $response->assertStatus(403);
    }

    public function test_admin_can_update_settings(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->post('/configuration/settings', [
            'panel_name' => 'CustomLiteControl',
            'admin_email' => 'admin@customlite.net',
            'timezone' => 'America/New_York',
            'default_php' => '83',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'update_settings',
        ]);
    }

    public function test_admin_can_view_litespeed_tuning_page(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->get('/configuration/tuning');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Configuration/LiteSpeedTuning')
            ->has('tuning.maxConnections')
        );
    }

    public function test_admin_can_update_tuning_parameters(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->post('/configuration/tuning', [
            'maxConnections' => 12000,
            'maxSSLConnections' => 9000,
            'connTimeout' => 300,
            'maxKeepAliveReq' => 1500,
            'keepAliveTimeout' => 5,
            'maxReqBodySize' => '1024M',
            'enableGzipCompress' => 1,
            'gzipCompressLevel' => 6,
            'quicEnable' => 1,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'update_tuning',
        ]);
    }

    public function test_tuning_validation_enforces_bounds(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->post('/configuration/tuning', [
            'maxConnections' => -5,
            'maxSSLConnections' => 9000,
            'connTimeout' => 300,
            'maxKeepAliveReq' => 1500,
            'keepAliveTimeout' => 5,
            'maxReqBodySize' => 'invalid',
            'enableGzipCompress' => 5,
            'gzipCompressLevel' => 20,
            'quicEnable' => 1,
        ]);

        $response->assertSessionHasErrors(['maxConnections', 'maxReqBodySize', 'enableGzipCompress', 'gzipCompressLevel']);
    }

    public function test_admin_can_view_and_update_php_limit(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->get('/configuration/php-limit?version=83');
        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Configuration/PhpLimit')
            ->has('limits.memory_limit')
        );

        $updateRes = $this->actingAs($admin)->post('/configuration/php-limit', [
            'version' => '83',
            'memory_limit' => '512M',
            'max_execution_time' => 600,
            'upload_max_filesize' => '128M',
            'post_max_size' => '128M',
            'max_input_vars' => 5000,
        ]);

        $updateRes->assertRedirect();
        $updateRes->assertSessionHas('success');
    }

    public function test_admin_can_view_error_logs(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->get('/configuration/error-logs?type=ols-error&lines=50');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Configuration/ErrorLogs')
            ->where('logType', 'ols-error')
            ->has('content')
        );
    }

    public function test_admin_can_view_services_list(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->get('/server/services');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Server/Services')
            ->has('services')
        );
    }

    public function test_admin_can_execute_service_action(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->post('/server/services/openlitespeed/restart');

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'service_restart',
        ]);
    }

    public function test_admin_can_view_processes_and_kill(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->get('/server/processes');
        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Server/ProcessManager')
            ->has('processes')
        );

        $killRes = $this->actingAs($admin)->post('/server/processes/1102/kill');
        $killRes->assertRedirect();
        $killRes->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'kill_process',
        ]);
    }

    public function test_admin_cannot_kill_init_process(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->post('/server/processes/1/kill');

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_admin_can_update_timezone_and_hostname(): void
    {
        $admin = User::where('role', 'admin')->first();

        $tzRes = $this->actingAs($admin)->post('/server/timezone', [
            'timezone' => 'Asia/Tokyo',
        ]);
        $tzRes->assertRedirect();
        $tzRes->assertSessionHas('success');

        $hostRes = $this->actingAs($admin)->post('/server/hostname', [
            'hostname' => 'node1.litecontrol.internal',
        ]);
        $hostRes->assertRedirect();
        $hostRes->assertSessionHas('success');
    }

    public function test_admin_can_execute_restart(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->post('/server/restart', [
            'action' => 'ols_reload',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_admin_can_view_system_statistics(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->get('/server/statistics');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Server/SystemStatistics')
            ->has('stats.cpu_percent')
            ->has('stats.ram_percent')
        );
    }

    public function test_admin_can_check_ols_daemon_status(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->get('/server/ols-status');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'service',
        ]);
        $this->assertContains($response->json('status'), ['online', 'offline']);
    }

    public function test_non_admin_cannot_check_ols_daemon_status(): void
    {
        $client = User::where('username', 'client1')->first();

        $response = $this->actingAs($client)->get('/server/ols-status');

        $response->assertStatus(403);
    }
}

