<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_two_factor_enabled_is_redirected_to_challenge_on_login(): void
    {
        $totp = new TotpService;
        $secret = $totp->generateSecret();

        $user = User::factory()->create([
            'username' => 'totpuser1',
            'password' => bcrypt('secret-password'),
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => now(),
        ]);

        $response = $this->post('/login', [
            'username' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_user_can_complete_two_factor_challenge_with_valid_totp_code(): void
    {
        $totp = new TotpService;
        $secret = $totp->generateSecret();

        $user = User::factory()->create([
            'username' => 'totpuser1',
            'password' => bcrypt('secret-password'),
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->post('/login', [
            'username' => $user->email,
            'password' => 'secret-password',
        ]);

        $code = $totp->getCode($secret);

        $response = $this->post('/two-factor-challenge', [
            'code' => $code,
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_two_factor_challenge_rejects_invalid_code(): void
    {
        $totp = new TotpService;
        $secret = $totp->generateSecret();

        $user = User::factory()->create([
            'username' => 'totpuser1',
            'password' => bcrypt('secret-password'),
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->post('/login', [
            'username' => $user->email,
            'password' => 'secret-password',
        ]);

        $response = $this->post('/two-factor-challenge', [
            'code' => '000000',
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_user_can_complete_two_factor_challenge_with_recovery_code_once(): void
    {
        $totp = new TotpService;
        $secret = $totp->generateSecret();
        $recoveryCodes = ['AAAA-BBBB', 'CCCC-DDDD'];

        $user = User::factory()->create([
            'username' => 'totpuser1',
            'password' => bcrypt('secret-password'),
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode($recoveryCodes)),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->post('/login', [
            'username' => $user->email,
            'password' => 'secret-password',
        ]);

        $response = $this->post('/two-factor-challenge', [
            'recovery_code' => 'AAAA-BBBB',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        // Recovery code should be consumed and rejected on reuse
        $this->post('/logout');
        $this->post('/login', [
            'username' => $user->email,
            'password' => 'secret-password',
        ]);

        $reuseResponse = $this->post('/two-factor-challenge', [
            'recovery_code' => 'AAAA-BBBB',
        ]);

        $reuseResponse->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_authenticated_user_can_confirm_and_disable_two_factor(): void
    {
        $user = User::factory()->create([
            'username' => 'totpuser5',
        ]);
        $this->actingAs($user);

        $this->get('/security/two-factor')->assertOk();

        $pendingSecret = session('two_factor.pending_secret');
        $this->assertNotEmpty($pendingSecret);

        $totp = new TotpService;
        $code = $totp->getCode($pendingSecret);

        $confirmResponse = $this->post('/security/two-factor/confirm', [
            'code' => $code,
        ]);

        $confirmResponse->assertSessionHasNoErrors();
        $confirmResponse->assertSessionHas('recoveryCodes');

        $user->refresh();
        $this->assertTrue($user->hasTwoFactorEnabled());

        // Disable 2FA
        $this->post('/security/two-factor/toggle')->assertRedirect();
        $user->refresh();
        $this->assertFalse($user->hasTwoFactorEnabled());
    }
}
