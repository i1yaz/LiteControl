<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Package;
use App\Models\Domain;
use App\Models\AuditLog;
use App\Services\LcCliService;
use App\Services\DomainService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    protected LcCliService $lcCli;
    protected DomainService $domainService;

    public function __construct(LcCliService $lcCli, DomainService $domainService)
    {
        $this->lcCli = $lcCli;
        $this->domainService = $domainService;
    }

    /**
     * Display a listing of hosting users.
     */
    public function index(Request $request): Response
    {
        $search = $request->input('search');

        $usersQuery = User::with(['package'])
            ->withCount(['domains', 'databases'])
            ->where('role', 'user');

        if ($search) {
            $usersQuery->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $usersQuery->latest()->paginate(15)->withQueryString();

        // Optionally fetch real-time disk usage from lc-cli
        $cliUsers = $this->lcCli->run('user', ['list']);
        $diskUsageMap = [];
        if (($cliUsers['status'] ?? '') === 'success' && is_array($cliUsers['data'] ?? null)) {
            foreach ($cliUsers['data'] as $item) {
                $diskUsageMap[$item['username'] ?? ''] = [
                    'disk_usage_bytes' => $item['disk_usage_bytes'] ?? 0,
                    'disk_usage_human' => $item['disk_usage_human'] ?? '0 B',
                    'is_locked' => $item['is_locked'] ?? false,
                ];
            }
        }

        return Inertia::render('Users/Index', [
            'users' => $users,
            'diskUsageMap' => $diskUsageMap,
            'filters' => [
                'search' => $search,
            ],
            'packages' => Package::all(['id', 'name', 'disk_quota_mb']),
        ]);
    }

    /**
     * Show form to create a new user.
     */
    public function create(): Response
    {
        return Inertia::render('Users/Create', [
            'packages' => Package::all(),
        ]);
    }

    /**
     * Store a newly created hosting user.
     */
    public function store(Request $request)
    {
        $reservedUsers = ['root', 'admin', 'litecontrol', 'www-data', 'nobody', 'vmail', 'mysql', 'redis'];

        $validated = $request->validate([
            'username' => [
                'required',
                'string',
                'min:3',
                'max:16',
                'regex:/^[a-z][a-z0-9_]{2,15}$/',
                'unique:users,username',
                Rule::notIn($reservedUsers),
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'package_id' => ['required', 'exists:packages,id'],
            'ssh_public_key' => ['nullable', 'string'],
            'domain' => [
                'nullable',
                'string',
                'regex:/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',
                'unique:domains,domain',
            ],
            'public_path' => ['nullable', 'string', 'max:255'],
            'php_version' => ['nullable', 'string', Rule::in(['74', '80', '81', '82', '83', '84', '85'])],
            'ssl_enabled' => ['nullable', 'boolean'],
        ]);

        $package = Package::findOrFail($validated['package_id']);

        // 1. Create Linux user via lc-cli
        $cliRes = $this->lcCli->run('user', [
            'add',
            $validated['username'],
            $validated['password'],
            (string) $package->disk_quota_mb,
        ]);

        if (($cliRes['status'] ?? '') !== 'success') {
            return back()->withErrors([
                'username' => 'System user creation failed: ' . ($cliRes['message'] ?? 'Unknown error'),
            ]);
        }

        // 2. Set SSH public key if provided
        if (!empty($validated['ssh_public_key'])) {
            $this->lcCli->run('user', [
                'ssh-key',
                $validated['username'],
                $validated['ssh_public_key'],
            ]);
        }

        // 3. Create database user record
        $user = User::create([
            'username' => $validated['username'],
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => 'user',
            'package_id' => $package->id,
            'home_directory' => "/home/{$validated['username']}",
            'status' => 'active',
            'ssh_public_key' => $validated['ssh_public_key'] ?? null,
        ]);

        $warning = null;
        $domainCreated = false;

        // 4. Optionally create primary domain if provided
        if (!empty($validated['domain'])) {
            try {
                $domainRes = $this->domainService->createDomain($user, [
                    'domain' => $validated['domain'],
                    'php_version' => $validated['php_version'] ?? '83',
                    'public_path' => $validated['public_path'] ?? null,
                    'ssl_enabled' => $validated['ssl_enabled'] ?? true,
                    'type' => 'primary',
                ], Auth::user());

                $domainCreated = true;
                $warning = $domainRes['warning'] ?? null;
            } catch (\Throwable $e) {
                $warning = "User created, but domain provisioning encountered an error: " . $e->getMessage();
            }
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'user.create',
            'command' => "lc-cli user add {$user->username}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => [
                'username' => $user->username,
                'package' => $package->name,
                'domain' => $validated['domain'] ?? null,
            ],
        ]);

        $msg = $domainCreated
            ? "User '{$user->username}' and primary domain '{$validated['domain']}' created successfully."
            : "User '{$user->username}' created successfully.";

        $redirect = redirect()->route('users.index')->with('success', $msg);
        if ($warning) {
            $redirect->with('warning', $warning);
        }

        return $redirect;
    }

    /**
     * Update an existing hosting user.
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'package_id' => ['required', 'exists:packages,id'],
            'ssh_public_key' => ['nullable', 'string'],
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];

        if (!empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        if ($user->package_id !== (int)$validated['package_id']) {
            $newPackage = Package::findOrFail($validated['package_id']);
            $user->package_id = $newPackage->id;
            $this->lcCli->run('user', ['quota', $user->username, (string)$newPackage->disk_quota_mb]);
        }

        $newKey = $validated['ssh_public_key'] ?? null;
        if ($newKey !== $user->ssh_public_key) {
            $user->ssh_public_key = $newKey;
            $this->lcCli->run('user', ['ssh-key', $user->username, !empty($newKey) ? $newKey : 'clear']);
        }

        $user->save();

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'user.update',
            'command' => "lc-cli user update {$user->username}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => ['username' => $user->username, 'package_id' => $user->package_id],
        ]);

        return back()->with('success', "User '{$user->username}' updated successfully.");
    }

    /**
     * Suspend a hosting user.
     */
    public function suspend(Request $request, User $user)
    {
        if ($user->isAdmin()) {
            return back()->with('error', 'Administrator accounts cannot be suspended.');
        }

        $res = $this->lcCli->run('user', ['suspend', $user->username]);

        if (($res['status'] ?? '') === 'success') {
            $user->update(['status' => 'suspended']);

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'user.suspend',
                'command' => "lc-cli user suspend {$user->username}",
                'status' => 'success',
                'ip_address' => $request->ip(),
                'details' => ['username' => $user->username],
            ]);

            return back()->with('success', "User '{$user->username}' suspended.");
        }

        return back()->with('error', 'Failed to suspend user: ' . ($res['message'] ?? 'Error'));
    }

    /**
     * Unsuspend a hosting user.
     */
    public function unsuspend(Request $request, User $user)
    {
        $res = $this->lcCli->run('user', ['unsuspend', $user->username]);

        if (($res['status'] ?? '') === 'success') {
            $user->update(['status' => 'active']);

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'user.unsuspend',
                'command' => "lc-cli user unsuspend {$user->username}",
                'status' => 'success',
                'ip_address' => $request->ip(),
                'details' => ['username' => $user->username],
            ]);

            return back()->with('success', "User '{$user->username}' unsuspended.");
        }

        return back()->with('error', 'Failed to unsuspend user: ' . ($res['message'] ?? 'Error'));
    }

    /**
     * Delete a hosting user.
     */
    public function destroy(Request $request, User $user)
    {
        if ($user->id === Auth::id() || $user->isAdmin()) {
            return back()->with('error', 'Cannot delete active administrator account.');
        }

        $username = $user->username;

        // Call lc-cli to remove linux user and files
        $this->lcCli->run('user', ['delete', $username, '--remove-home']);

        // Clean up DB records
        $user->domains()->delete();
        $user->databases()->delete();
        $user->databaseUsers()->delete();
        $user->backups()->delete();
        $user->delete();

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'user.delete',
            'command' => "lc-cli user delete {$username} --remove-home",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => ['username' => $username],
        ]);

        return redirect()->route('users.index')->with('success', "User '{$username}' deleted successfully.");
    }

    /**
     * Impersonate a customer user (Admin Only).
     */
    public function impersonate(Request $request, User $user)
    {
        if ($user->isAdmin()) {
            return back()->with('error', 'Cannot impersonate another administrator.');
        }

        $adminId = Auth::id();
        session(['impersonator_id' => $adminId]);

        Auth::login($user);

        return redirect()->route('dashboard')->with('success', "Now impersonating {$user->username}. You can return to admin view anytime.");
    }

    /**
     * Revert back to the admin user session.
     */
    public function revertImpersonation(Request $request)
    {
        $impersonatorId = session('impersonator_id');

        if (! $impersonatorId) {
            return redirect()->route('dashboard');
        }

        $admin = User::findOrFail($impersonatorId);
        session()->forget('impersonator_id');

        Auth::login($admin);

        return redirect()->route('users.index')->with('success', 'Returned to administrator account.');
    }
}

