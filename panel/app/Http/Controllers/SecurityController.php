<?php

namespace App\Http\Controllers;

use App\Domains\Security\Actions\AddFirewallRuleAction;
use App\Domains\Security\Actions\DeleteFirewallRuleAction;
use App\Domains\Security\Actions\UpdatePanelPortAction;
use App\Domains\Security\Actions\UpdatePanelSslAction;
use App\Domains\Security\Actions\UpdateSshPortAction;
use App\Domains\Security\Contracts\FirewallDriverInterface;
use App\Domains\Security\Models\FirewallRule;
use App\Domains\Security\ValueObjects\Port;
use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Services\SslService;
use App\Services\System\ModuleCliService;
use Illuminate\Http\Request;
use App\Services\TotpService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function __construct(
        protected SslService $sslService,
        protected FirewallDriverInterface $firewallDriver,
        protected ModuleCliService $moduleCli
    ) {}

    /**
     * Domain SSL Certificates Manager.
     */
    public function ssl(Request $request): Response
    {
        $user = Auth::user();
        $query = Domain::query()->with('user:id,username');

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        $domains = $query->latest()->get();

        return Inertia::render('Security/Ssl', [
            'domains' => $domains,
        ]);
    }

    /**
     * Issue Let's Encrypt SSL certificate for a domain.
     */
    public function issueSsl(Request $request)
    {
        $user = Auth::user();
        $validated = $request->validate([
            'domain_id' => ['required', 'exists:domains,id'],
        ]);

        $domain = Domain::findOrFail($validated['domain_id']);

        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403);
        }

        $res = $this->sslService->issueOrRenew($domain, $user);

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? 'Failed to issue SSL certificate.');
        }

        return redirect()->back()->with('success', $res['message'] ?? "Let's Encrypt SSL certificate successfully issued.");
    }

    /**
     * Install Custom SSL certificate.
     */
    public function customSsl(Request $request)
    {
        $user = Auth::user();
        $validated = $request->validate([
            'domain_id' => ['required', 'exists:domains,id'],
            'certificate' => ['required', 'string'],
            'private_key' => ['required', 'string'],
        ]);

        $domain = Domain::findOrFail($validated['domain_id']);

        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403);
        }

        $res = $this->sslService->installCustomSsl($domain, $validated['certificate'], $validated['private_key'], $user);

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? 'Failed to install custom SSL certificate.');
        }

        return redirect()->back()->with('success', $res['message'] ?? "Custom SSL certificate installed.");
    }

    /**
     * Renew SSL certificate.
     */
    public function renewSsl(Request $request, Domain $domain)
    {
        $user = Auth::user();
        if (! $user->isAdmin() && $domain->user_id !== $user->id) {
            abort(403);
        }

        $res = $this->sslService->issueOrRenew($domain, $user, true);

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? 'Failed to renew SSL certificate.');
        }

        return redirect()->back()->with('success', $res['message'] ?? "SSL certificate renewed.");
    }

    /**
     * UFW Firewall Manager (Admin only).
     */
    public function ufw(): Response
    {
        $ufwData = $this->firewallDriver->getUfwStatus();
        $rules = $ufwData['rules'] ?? [];
        $active = $ufwData['active'] ?? true;

        $dbRules = FirewallRule::latest()->get();

        return Inertia::render('Security/UfwFirewall', [
            'active' => $active,
            'systemRules' => $rules,
            'rules' => $dbRules,
        ]);
    }

    /**
     * Add UFW Rule.
     */
    public function storeUfwRule(Request $request, AddFirewallRuleAction $action)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'port' => ['required', 'string', 'max:15'],
            'protocol' => ['required', 'in:tcp,udp,any'],
            'action' => ['required', 'in:allow,deny'],
            'source_ip' => ['nullable', 'string', 'max:45'],
        ]);

        try {
            $action->handle($validated, Auth::user(), $request->ip());
            return redirect()->back()->with('success', "Firewall rule created and applied: {$validated['action']} port {$validated['port']}.");
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage() ?: 'Failed to apply firewall rule.');
        }
    }

    /**
     * Delete UFW Rule.
     */
    public function deleteUfwRule(Request $request, $rule, DeleteFirewallRuleAction $action)
    {
        try {
            $action->handle($rule, Auth::user(), $request->ip());
            return redirect()->back()->with('success', "Firewall rule removed successfully.");
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage() ?: 'Failed to remove firewall rule.');
        }
    }

    /**
     * Port Firewall Overview & Quick Toggle.
     */
    public function portFirewall(): Response
    {
        $ufwData = $this->firewallDriver->getUfwStatus();
        $rules = $ufwData['rules'] ?? [];

        $standardPorts = [
            ['name' => 'HTTP (Web)', 'port' => '80', 'protocol' => 'tcp', 'status' => 'open'],
            ['name' => 'HTTPS (SSL)', 'port' => '443', 'protocol' => 'tcp', 'status' => 'open'],
            ['name' => 'LiteControl Panel', 'port' => '8090', 'protocol' => 'tcp', 'status' => 'open'],
            ['name' => 'SSH Remote Access', 'port' => '22', 'protocol' => 'tcp', 'status' => 'open'],
            ['name' => 'MySQL Database', 'port' => '3306', 'protocol' => 'tcp', 'status' => 'restricted'],
            ['name' => 'FTP Transfer', 'port' => '21', 'protocol' => 'tcp', 'status' => 'closed'],
            ['name' => 'DNS Nameserver', 'port' => '53', 'protocol' => 'udp', 'status' => 'closed'],
            ['name' => 'SMTP Mail', 'port' => '25', 'protocol' => 'tcp', 'status' => 'closed'],
            ['name' => 'Secure SMTP (Submission)', 'port' => '587', 'protocol' => 'tcp', 'status' => 'closed'],
            ['name' => 'IMAPS Mail', 'port' => '993', 'protocol' => 'tcp', 'status' => 'closed'],
        ];

        return Inertia::render('Security/PortFirewall', [
            'standardPorts' => $standardPorts,
            'activeRules' => $rules,
        ]);
    }

    /**
     * Panel Port Settings.
     */
    public function panelPort(): Response
    {
        return Inertia::render('Security/PanelPort', [
            'currentPort' => 8090,
        ]);
    }

    /**
     * Update Panel Port.
     */
    public function updatePanelPort(Request $request, UpdatePanelPortAction $action)
    {
        $validated = $request->validate([
            'port' => ['required', 'integer', 'min:1024', 'max:65535'],
        ]);

        try {
            $action->handle(Port::fromInt((int) $validated['port']), Auth::user(), $request->ip());
            return redirect()->back()->with('success', "Panel listening port changed to {$validated['port']}.");
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage() ?: 'Failed to change panel port.');
        }
    }

    /**
     * SSH Port Settings.
     */
    public function sshPort(): Response
    {
        return Inertia::render('Security/SshPort', [
            'currentPort' => 22,
        ]);
    }

    /**
     * Update SSH Port.
     */
    public function updateSshPort(Request $request, UpdateSshPortAction $action)
    {
        $validated = $request->validate([
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
        ]);

        try {
            $action->handle(Port::fromInt((int) $validated['port']), Auth::user(), $request->ip());
            return redirect()->back()->with('success', "SSH service port changed to {$validated['port']}.");
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage() ?: 'Failed to change SSH port.');
        }
    }

    /**
     * Panel SSL Certificate Manager.
     */
    public function panelSsl(): Response
    {
        return Inertia::render('Security/PanelSsl', [
            'panelHost' => request()->getHost(),
            'hasSsl' => true,
        ]);
    }

    /**
     * Update Panel SSL.
     */
    public function updatePanelSsl(Request $request, UpdatePanelSslAction $action)
    {
        $validated = $request->validate([
            'type' => ['required', 'in:letsencrypt,custom'],
            'domain' => ['required_if:type,letsencrypt', 'nullable', 'string'],
            'certificate' => ['required_if:type,custom', 'nullable', 'string'],
            'private_key' => ['required_if:type,custom', 'nullable', 'string'],
        ]);

        try {
            $action->handle($validated, Auth::user(), $request->ip());
            return redirect()->back()->with('success', 'LiteControl panel SSL certificate updated successfully.');
        } catch (LcCliException $e) {
            return redirect()->back()->with('error', $e->getMessage() ?: 'Failed to apply panel SSL certificate.');
        }
    }

    /**
     * Change Password View.
     */
    public function password(): Response
    {
        return Inertia::render('Security/Password');
    }

    /**
     * Update Password.
     */
    public function updatePassword(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'update_password',
            'command' => 'user.password',
            'status' => 'success',
            'ip_address' => $request->ip(),
        ]);

        return redirect()->back()->with('success', 'Your account password has been updated.');
    }

    /**
     * Two-Factor Authentication View.
     */
    public function twoFactor(Request $request, TotpService $totp): Response
    {
        $user = Auth::user();
        $enabled = $user->hasTwoFactorEnabled();

        $props = [
            'enabled' => $enabled,
            'recoveryCodes' => $request->session()->get('recoveryCodes'),
        ];

        if (! $enabled) {
            $secret = $request->session()->get('two_factor.pending_secret');
            if (! $secret) {
                $secret = $totp->generateSecret();
                $request->session()->put('two_factor.pending_secret', $secret);
            }

            $props['secret'] = $secret;
            $props['otpauthUri'] = $totp->getOtpAuthUri(
                config('app.name', 'LiteControl'),
                $user->email ?: $user->username,
                $secret
            );
        }

        return Inertia::render('Security/TwoFactor', $props);
    }

    /**
     * Confirm and enable Two-Factor Authentication.
     */
    public function confirmTwoFactor(Request $request, TotpService $totp)
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $secret = $request->session()->get('two_factor.pending_secret');
        if (! $secret || ! $totp->verify($secret, $request->string('code')->toString())) {
            throw ValidationException::withMessages([
                'code' => __('The provided verification code is invalid. Please try again.'),
            ]);
        }

        $user = Auth::user();
        $recoveryCodes = $totp->generateRecoveryCodes();

        $user->update([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode($recoveryCodes)),
            'two_factor_confirmed_at' => now(),
        ]);

        $request->session()->forget('two_factor.pending_secret');

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'enable_2fa',
            'command' => 'auth.2fa.enable',
            'status' => 'success',
            'ip_address' => $request->ip(),
        ]);

        return back()
            ->with('success', 'Two-factor authentication enabled successfully.')
            ->with('recoveryCodes', $recoveryCodes);
    }

    /**
     * Toggle Two-Factor Authentication.
     */
    public function toggleTwoFactor(Request $request)
    {
        $user = Auth::user();

        if ($user->two_factor_confirmed_at) {
            $user->update([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ]);
            $msg = 'Two-factor authentication disabled.';
        } else {
            return redirect()->route('security.two-factor');
        }

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'toggle_2fa',
            'command' => 'auth.2fa',
            'status' => 'success',
            'ip_address' => $request->ip(),
        ]);

        return redirect()->back()->with('success', $msg);
    }

    /**
     * CSF Firewall View.
     */
    public function csf(): Response
    {
        $data = $this->moduleCli->getModuleStatus('csf');

        return Inertia::render('Security/CsfFirewall', [
            'installed' => !empty($data['installed']),
            'status' => $data['state'] ?? 'not_installed',
        ]);
    }

    /**
     * ModSecurity View.
     */
    public function modSecurity(): Response
    {
        $data = $this->moduleCli->getModuleStatus('modsecurity');

        return Inertia::render('Security/ModSecurity', [
            'installed' => !empty($data['installed']),
            'status' => $data['state'] ?? 'not_installed',
        ]);
    }

    /**
     * ImunifyAV Antivirus View.
     */
    public function imunifyAv(): Response
    {
        $data = $this->moduleCli->getModuleStatus('imunifyav');

        return Inertia::render('Security/ImunifyAv', [
            'installed' => !empty($data['installed']),
            'status' => $data['state'] ?? 'not_installed',
        ]);
    }
}
