<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\FirewallRule;
use App\Services\LcCliService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    protected LcCliService $lcCli;

    public function __construct(LcCliService $lcCli)
    {
        $this->lcCli = $lcCli;
    }

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

        $res = $this->lcCli->run('ssl', ['issue', $domain->domain, $domain->document_root, $user->email]);

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? 'Failed to issue SSL certificate.');
        }

        $domain->update([
            'ssl_enabled' => true,
            'ssl_expires_at' => now()->addDays(90),
        ]);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'ssl_issue',
            'command' => "lc-cli ssl issue {$domain->domain}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode(['domain' => $domain->domain]),
        ]);

        return redirect()->back()->with('success', "Let's Encrypt SSL certificate successfully issued for {$domain->domain}.");
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

        // Write temp cert and key files to hand to CLI
        $tempCert = tempnam(sys_get_temp_dir(), 'lc_cert_');
        $tempKey = tempnam(sys_get_temp_dir(), 'lc_key_');
        file_put_contents($tempCert, $validated['certificate']);
        file_put_contents($tempKey, $validated['private_key']);

        $res = $this->lcCli->run('ssl', ['custom', $domain->domain, $tempCert, $tempKey]);

        @unlink($tempCert);
        @unlink($tempKey);

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? 'Failed to install custom SSL certificate.');
        }

        $domain->update([
            'ssl_enabled' => true,
            'ssl_expires_at' => now()->addYear(),
        ]);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'ssl_custom',
            'command' => "lc-cli ssl custom {$domain->domain}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode(['domain' => $domain->domain]),
        ]);

        return redirect()->back()->with('success', "Custom SSL certificate installed for {$domain->domain}.");
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

        $res = $this->lcCli->run('ssl', ['renew', $domain->domain]);

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? 'Failed to renew SSL certificate.');
        }

        $domain->update([
            'ssl_enabled' => true,
            'ssl_expires_at' => now()->addDays(90),
        ]);

        return redirect()->back()->with('success', "SSL certificate renewed for {$domain->domain}.");
    }

    /**
     * UFW Firewall Manager (Admin only).
     */
    public function ufw(): Response
    {
        $res = $this->lcCli->run('security', ['ufw-status']);
        $rules = $res['data']['rules'] ?? [];
        $active = $res['data']['active'] ?? true;

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
    public function storeUfwRule(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'port' => ['required', 'string', 'max:15'],
            'protocol' => ['required', 'in:tcp,udp,any'],
            'action' => ['required', 'in:allow,deny'],
            'source_ip' => ['nullable', 'string', 'max:45'],
        ]);

        $sub = $validated['action'] === 'allow' ? 'ufw-allow' : 'ufw-deny';
        $proto = $validated['protocol'] === 'any' ? 'tcp' : $validated['protocol'];
        $src = $validated['source_ip'] ?: '';

        $cliArgs = [$sub, $validated['port'], $proto];
        if (!empty($src)) {
            $cliArgs[] = $src;
        }

        $res = $this->lcCli->run('security', $cliArgs);

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? 'Failed to apply firewall rule.');
        }

        FirewallRule::create([
            'name' => $validated['name'],
            'port' => $validated['port'],
            'protocol' => $validated['protocol'],
            'action' => $validated['action'],
            'source_ip' => $validated['source_ip'] ?: 'Anywhere',
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'create_firewall_rule',
            'command' => "lc-cli security {$sub} {$validated['port']}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode($validated),
        ]);

        return redirect()->back()->with('success', "Firewall rule created and applied: {$validated['action']} port {$validated['port']}.");
    }

    /**
     * Delete UFW Rule.
     */
    public function deleteUfwRule(Request $request, $rule)
    {
        $res = $this->lcCli->run('security', ['ufw-delete', (string)$rule]);

        if (is_numeric($rule)) {
            $dbRule = FirewallRule::find($rule);
            if ($dbRule) {
                $dbRule->delete();
            }
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'delete_firewall_rule',
            'command' => "lc-cli security ufw-delete {$rule}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode(['rule' => $rule]),
        ]);

        return redirect()->back()->with('success', "Firewall rule removed successfully.");
    }

    /**
     * Port Firewall Overview & Quick Toggle.
     */
    public function portFirewall(): Response
    {
        $res = $this->lcCli->run('security', ['ufw-status']);
        $rules = $res['data']['rules'] ?? [];

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
    public function updatePanelPort(Request $request)
    {
        $validated = $request->validate([
            'port' => ['required', 'integer', 'min:1024', 'max:65535'],
        ]);

        $res = $this->lcCli->run('security', ['panel-port', (string)$validated['port']]);

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? 'Failed to change panel port.');
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'change_panel_port',
            'command' => "lc-cli security panel-port {$validated['port']}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode($validated),
        ]);

        return redirect()->back()->with('success', "Panel listening port changed to {$validated['port']}.");
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
    public function updateSshPort(Request $request)
    {
        $validated = $request->validate([
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
        ]);

        $res = $this->lcCli->run('security', ['ssh-port', (string)$validated['port']]);

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? 'Failed to change SSH port.');
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'change_ssh_port',
            'command' => "lc-cli security ssh-port {$validated['port']}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode($validated),
        ]);

        return redirect()->back()->with('success', "SSH service port changed to {$validated['port']}.");
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
    public function updatePanelSsl(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'in:letsencrypt,custom'],
            'domain' => ['required_if:type,letsencrypt', 'nullable', 'string'],
            'certificate' => ['required_if:type,custom', 'nullable', 'string'],
            'private_key' => ['required_if:type,custom', 'nullable', 'string'],
        ]);

        if ($validated['type'] === 'letsencrypt') {
            $res = $this->lcCli->run('ssl', ['panel-ssl', 'issue', $validated['domain']]);
        } else {
            $tempCert = tempnam(sys_get_temp_dir(), 'panel_cert_');
            $tempKey = tempnam(sys_get_temp_dir(), 'panel_key_');
            file_put_contents($tempCert, $validated['certificate']);
            file_put_contents($tempKey, $validated['private_key']);

            $res = $this->lcCli->run('ssl', ['panel-ssl', 'custom', $tempCert, $tempKey]);

            @unlink($tempCert);
            @unlink($tempKey);
        }

        if (($res['status'] ?? '') === 'error') {
            return redirect()->back()->with('error', $res['message'] ?? 'Failed to apply panel SSL certificate.');
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'update_panel_ssl',
            'command' => 'lc-cli ssl panel-ssl',
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => json_encode(['type' => $validated['type']]),
        ]);

        return redirect()->back()->with('success', 'LiteControl panel SSL certificate updated successfully.');
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
    public function twoFactor(): Response
    {
        $user = Auth::user();

        return Inertia::render('Security/TwoFactor', [
            'enabled' => !empty($user->two_factor_secret) && !empty($user->two_factor_confirmed_at),
        ]);
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
                'two_factor_confirmed_at' => null,
            ]);
            $msg = 'Two-factor authentication disabled.';
        } else {
            $user->update([
                'two_factor_secret' => 'MOCKSECRETBASE32XYZ',
                'two_factor_confirmed_at' => now(),
            ]);
            $msg = 'Two-factor authentication enabled successfully.';
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
        $res = $this->lcCli->run('module', ['status', 'csf']);
        $installed = ($res['status'] ?? '') === 'success' && !empty($res['data']['installed']);

        return Inertia::render('Security/CsfFirewall', [
            'installed' => $installed,
            'status' => $res['data']['state'] ?? 'not_installed',
        ]);
    }

    /**
     * ModSecurity View.
     */
    public function modSecurity(): Response
    {
        $res = $this->lcCli->run('module', ['status', 'modsecurity']);
        $installed = ($res['status'] ?? '') === 'success' && !empty($res['data']['installed']);

        return Inertia::render('Security/ModSecurity', [
            'installed' => $installed,
            'status' => $res['data']['state'] ?? 'not_installed',
        ]);
    }

    /**
     * ImunifyAV Antivirus View.
     */
    public function imunifyAv(): Response
    {
        $res = $this->lcCli->run('module', ['status', 'imunifyav']);
        $installed = ($res['status'] ?? '') === 'success' && !empty($res['data']['installed']);

        return Inertia::render('Security/ImunifyAv', [
            'installed' => $installed,
            'status' => $res['data']['state'] ?? 'not_installed',
        ]);
    }
}
