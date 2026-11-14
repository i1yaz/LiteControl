<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use App\Domains\PanelConfig\Services\ThemeManager;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->seed(DemoDataSeeder::class);
    }

    public function test_theme_manager_discovers_available_themes(): void
    {
        $manager = app(ThemeManager::class);
        $available = $manager->available();

        $this->assertArrayHasKey('avionics', $available);
        $this->assertArrayHasKey('horizon', $available);
        $this->assertEquals('Avionics Telemetry', $available['avionics']['name']);
        $this->assertEquals('Horizon Modern', $available['horizon']['name']);
    }

    public function test_theme_manager_resolves_fallback_for_invalid_theme(): void
    {
        $manager = app(ThemeManager::class);
        $resolved = $manager->resolve();

        $this->assertEquals('avionics', $resolved['id']);
    }

    public function test_authenticated_user_can_switch_theme(): void
    {
        $user = User::where('username', 'admin')->first();

        $response = $this->actingAs($user)
            ->post(route('theme.switch'), [
                'theme' => 'horizon',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('theme', 'horizon');
        $this->assertEquals('horizon', $user->fresh()->theme);
    }

    public function test_guest_can_switch_theme_in_session(): void
    {
        $response = $this->post(route('theme.switch'), [
            'theme' => 'horizon',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('theme', 'horizon');
    }

    public function test_switch_rejects_invalid_theme_name(): void
    {
        $response = $this->post(route('theme.switch'), [
            'theme' => 'non-existent-theme-xyz',
        ]);

        $response->assertSessionHasErrors(['theme']);
    }

    public function test_inertia_shares_panel_theme_metadata(): void
    {
        $user = User::where('username', 'admin')->first();
        $user->update(['theme' => 'horizon']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->has('panel.theme')
            ->where('panel.theme.id', 'horizon')
            ->has('panel.available_themes')
            ->where('auth.user.theme', 'horizon')
        );
    }
}
