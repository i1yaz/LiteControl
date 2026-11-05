<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Package;
use App\Models\Domain;
use App\Models\Database as DbModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

class AuthAndDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/');
        $response->assertRedirect('/login');

        $dashResponse = $this->get('/dashboard');
        $dashResponse->assertRedirect('/login');
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $response = $this->post('/login', [
            'username' => 'admin',
            'password' => 'admin123456',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/dashboard');
    }

    public function test_users_cannot_authenticate_with_invalid_password(): void
    {
        $response = $this->from('/login')->post('/login', [
            'username' => 'admin',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('username');
    }

    public function test_suspended_user_cannot_login(): void
    {
        $user = User::where('username', 'client1')->first();
        $user->update(['status' => 'suspended']);

        $response = $this->post('/login', [
            'username' => 'client1',
            'password' => 'client123456',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('username');
    }

    public function test_admin_dashboard_renders_with_server_statistics_and_counters(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('isAdmin', true)
            ->has('stats.cpu_percent')
            ->has('stats.ram_percent')
            ->has('stats.disk_percent')
            ->has('counters.total_users')
            ->has('counters.total_domains')
            ->has('counters.total_databases')
        );
    }

    public function test_regular_user_dashboard_renders_with_quotas(): void
    {
        $client = User::where('username', 'client1')->first();

        $response = $this->actingAs($client)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('isAdmin', false)
            ->has('userQuotas.disk_limit_mb')
            ->has('userQuotas.bandwidth_limit_mb')
            ->has('domains')
        );
    }

    public function test_user_can_logout(): void
    {
        $admin = User::where('role', 'admin')->first();

        $response = $this->actingAs($admin)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/login');
    }
}

