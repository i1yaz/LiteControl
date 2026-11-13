<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class AuthController extends Controller
{
    /**
     * Show the login screen.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');

        // Allow logging in via username OR email
        $field = filter_var($credentials['username'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        if (! Auth::attempt([$field => $credentials['username'], 'password' => $credentials['password']], $remember)) {
            throw ValidationException::withMessages([
                'username' => __('The provided credentials do not match our records.'),
            ]);
        }

        $request->session()->regenerate();

        if (Auth::user()->status === 'suspended') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'username' => __('This account has been suspended. Please contact server administration.'),
            ]);
        }

        $user = Auth::user();

        if ($user->hasTwoFactorEnabled()) {
            Auth::logout();
            $request->session()->put([
                'two_factor.login.id' => $user->id,
                'two_factor.login.remember' => $remember,
                'two_factor.login.expires_at' => now()->addMinutes(10)->timestamp,
            ]);

            return redirect()->route('two-factor.login');
        }

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Show the two-factor authentication challenge screen.
     */
    public function twoFactorChallenge(Request $request): Response|RedirectResponse
    {
        if (! $this->hasValidTwoFactorSession($request)) {
            $this->clearTwoFactorSession($request);

            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    /**
     * Verify the two-factor authentication code or recovery code.
     */
    public function verifyTwoFactorChallenge(Request $request, TotpService $totp): RedirectResponse
    {
        if (! $this->hasValidTwoFactorSession($request)) {
            $this->clearTwoFactorSession($request);

            return redirect()->route('login')->withErrors([
                'username' => __('Your two-factor authentication session expired. Please log in again.'),
            ]);
        }

        $data = $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $user = User::find($request->session()->get('two_factor.login.id'));

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            $this->clearTwoFactorSession($request);

            return redirect()->route('login');
        }

        $verified = false;

        if (! empty($data['code'])) {
            $secret = $this->decryptValue($user->two_factor_secret);
            $verified = $totp->verify($secret, $data['code']);
        } elseif (! empty($data['recovery_code'])) {
            $verified = $this->consumeRecoveryCode($user, $data['recovery_code']);
        }

        if (! $verified) {
            throw ValidationException::withMessages([
                'code' => __('The provided two-factor authentication code was invalid.'),
            ]);
        }

        $remember = (bool) $request->session()->get('two_factor.login.remember', false);
        $this->clearTwoFactorSession($request);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    private function hasValidTwoFactorSession(Request $request): bool
    {
        $userId = $request->session()->get('two_factor.login.id');
        $expiresAt = $request->session()->get('two_factor.login.expires_at');

        return ! empty($userId) && ! empty($expiresAt) && now()->timestamp <= (int) $expiresAt;
    }

    private function clearTwoFactorSession(Request $request): void
    {
        $request->session()->forget([
            'two_factor.login.id',
            'two_factor.login.remember',
            'two_factor.login.expires_at',
        ]);
    }

    private function decryptValue(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }

    private function consumeRecoveryCode(User $user, string $inputCode): bool
    {
        if (empty($user->two_factor_recovery_codes)) {
            return false;
        }

        $codes = json_decode($this->decryptValue($user->two_factor_recovery_codes), true);
        if (! is_array($codes)) {
            return false;
        }

        $normalized = strtoupper(trim($inputCode));
        $matchedIndex = null;

        foreach ($codes as $index => $code) {
            if (hash_equals(strtoupper((string) $code), $normalized)) {
                $matchedIndex = $index;
                break;
            }
        }

        if ($matchedIndex === null) {
            return false;
        }

        unset($codes[$matchedIndex]);
        $user->update([
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($codes))),
        ]);

        return true;
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

