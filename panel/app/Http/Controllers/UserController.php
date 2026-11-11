<?php

namespace App\Http\Controllers;

use App\Actions\User\CreateUserAction;
use App\Actions\User\DeleteUserAction;
use App\Actions\User\SuspendUserAction;
use App\Actions\User\UnsuspendUserAction;
use App\Actions\User\UpdateUserAction;
use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\User;
use App\Services\System\UserCliService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(
        protected UserCliService $userCli
    ) {}

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

        // Fetch real-time disk usage via UserCliService
        $cliUsers = $this->userCli->listUsers();
        $diskUsageMap = [];
        foreach ($cliUsers as $item) {
            $diskUsageMap[$item['username'] ?? ''] = [
                'disk_usage_bytes' => $item['disk_usage_bytes'] ?? 0,
                'disk_usage_human' => $item['disk_usage_human'] ?? '0 B',
                'is_locked' => $item['is_locked'] ?? false,
            ];
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
    public function store(Request $request, CreateUserAction $createUser)
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

        try {
            $result = $createUser->handle($validated, Auth::user(), $request->ip());
        } catch (LcCliException $e) {
            return back()->withErrors([
                'username' => 'System user creation failed: ' . $e->getMessage(),
            ]);
        }

        $user = $result['user'];
        $domainCreated = $result['domainCreated'];
        $warning = $result['warning'];

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
    public function update(Request $request, User $user, UpdateUserAction $updateUser)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'package_id' => ['required', 'exists:packages,id'],
            'ssh_public_key' => ['nullable', 'string'],
        ]);

        try {
            $updateUser->handle($user, $validated, Auth::user(), $request->ip());
        } catch (LcCliException $e) {
            return back()->with('error', 'Failed to update system user: ' . $e->getMessage());
        }

        return back()->with('success', "User '{$user->username}' updated successfully.");
    }

    /**
     * Suspend a hosting user.
     */
    public function suspend(Request $request, User $user, SuspendUserAction $suspendUser)
    {
        try {
            $suspendUser->handle($user, Auth::user(), $request->ip());
            return back()->with('success', "User '{$user->username}' suspended.");
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (LcCliException $e) {
            return back()->with('error', 'Failed to suspend user: ' . $e->getMessage());
        }
    }

    /**
     * Unsuspend a hosting user.
     */
    public function unsuspend(Request $request, User $user, UnsuspendUserAction $unsuspendUser)
    {
        try {
            $unsuspendUser->handle($user, Auth::user(), $request->ip());
            return back()->with('success', "User '{$user->username}' unsuspended.");
        } catch (LcCliException $e) {
            return back()->with('error', 'Failed to unsuspend user: ' . $e->getMessage());
        }
    }

    /**
     * Delete a hosting user.
     */
    public function destroy(Request $request, User $user, DeleteUserAction $deleteUser)
    {
        try {
            $username = $user->username;
            $deleteUser->handle($user, Auth::user(), $request->ip());
            return redirect()->route('users.index')->with('success', "User '{$username}' deleted successfully.");
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (LcCliException $e) {
            return back()->with('error', 'Failed to delete user: ' . $e->getMessage());
        }
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
