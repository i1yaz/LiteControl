<?php

use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('defaults to english locale', function () {
    $response = $this->get('/login');

    $response->assertOk();
    expect(app()->getLocale())->toBe('en');
});

it('switches locale in session and persists on authenticated user', function () {
    config()->set('app.available_locales', [
        'en' => ['name' => 'English', 'native' => 'English', 'dir' => 'ltr'],
        'fr' => ['name' => 'French', 'native' => 'Français', 'dir' => 'ltr'],
    ]);

    $user = User::factory()->create(['username' => 'testuser']);

    $response = $this->actingAs($user)->post('/locale', [
        'locale' => 'fr',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('locale', 'fr');
    expect($user->fresh()->locale)->toBe('fr');
});

it('rejects unsupported locales', function () {
    $response = $this->post('/locale', [
        'locale' => 'xx',
    ]);

    $response->assertSessionHasErrors('locale');
});
