<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Package;
use App\Models\Domain;
use App\Models\AuditLog;
use App\Services\LcCliService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    protected LcCliService $lcCli;

    public function __construct(LcCliService $lcCli)
    {
        $this->lcCli = $lcCli;
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
            'domain' => [
                'required',
                'string',
                'regex:/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',
                'unique:domains,domain',
            ],
            'php_version' => ['nullable', 'string', Rule::in(['74', '80', '81', '82', '83', '84', '85'])],
        ]);

        $package = Package::findOrFail($validated['package_id']);
        $phpVersion = $validated['php_version'] ?? '83';

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

        // 2. Create database user record
        $user = User::create([
            'username' => $validated['username'],
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => 'user',
            'package_id' => $package->id,
            'home_directory' => "/home/{$validated['username']}",
            'status' => 'active',
        ]);

        // 3. Create primary vhost via lc-cli
        $docRoot = "/home/{$validated['username']}/{$validated['domain']}/public_html";
        $vhostRes = $this->lcCli->run('vhost', [
            'add',
            $validated['domain'],
            $validated['username'],
            $phpVersion,
            $docRoot,
        ]);

        // 4. Create primary domain in DB
        Domain::create([
            'user_id' => $user->id,
            'domain' => $validated['domain'],
            'type' => 'primary',
            'document_root' => $docRoot,
            'php_version' => $phpVersion,
            'ssl_enabled' => false,
            'status' => 'active',
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'user.create',
            'command' => "lc-cli user add {$user->username}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => ['username' => $user->username, 'domain' => $validated['domain'], 'package' => $package->name],
        ]);

        return redirect()->route('users.index')->with('success', "User '{$user->username}' and primary domain '{$validated['domain']}' created successfully.");
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

