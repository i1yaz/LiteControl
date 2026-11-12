<?php

namespace App\Http\Controllers;

use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\LcCliService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseController extends Controller
{
    protected LcCliService $lcCli;

    public function __construct(LcCliService $lcCli)
    {
        $this->lcCli = $lcCli;
    }

    /**
     * Display a listing of databases and database users.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();

        $dbQuery = Database::with(['user:id,username', 'users']);
        $dbUsersQuery = DatabaseUser::with(['user:id,username', 'database:id,db_name']);

        if (! $user->isAdmin()) {
            $dbQuery->where('user_id', $user->id);
            $dbUsersQuery->where('user_id', $user->id);
        }

        $databases = $dbQuery->latest()->get();
        $dbUsers = $dbUsersQuery->latest()->get();

        // Optionally fetch real-time sizes from lc-cli db list
        $cliRes = $this->lcCli->run('db', ['list']);
        $liveDbMap = [];
        if (($cliRes['status'] ?? '') === 'success' && is_array($cliRes['data'] ?? null)) {
            foreach ($cliRes['data'] as $dbItem) {
                $liveDbMap[$dbItem['name'] ?? ''] = [
                    'size_bytes' => $dbItem['size_bytes'] ?? 0,
                    'size_human' => $dbItem['size_human'] ?? '0 B',
                    'tables' => $dbItem['tables'] ?? 0,
                ];
            }
        }

        return Inertia::render('Databases/Index', [
            'databases' => $databases,
            'dbUsers' => $dbUsers,
            'liveDbMap' => $liveDbMap,
            'users' => $user->isAdmin() ? User::where('role', 'user')->get(['id', 'username', 'name']) : [],
        ]);
    }

    /**
     * Store a newly created database.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $rules = [
            'db_name' => ['required', 'string', 'regex:/^[a-zA-Z0-9_]{1,64}$/'],
            'domain_id' => ['nullable', 'exists:domains,id'],
        ];

        if ($user->isAdmin()) {
            $rules['user_id'] = ['required', 'exists:users,id'];
        }

        $validated = $request->validate($rules);

        $targetUser = $user->isAdmin() && !empty($validated['user_id'])
            ? User::with('package')->findOrFail($validated['user_id'])
            : $user->load('package');

        // Check package limit for non-admin
        if (! $user->isAdmin() && $targetUser->package) {
            $currentDbCount = Database::where('user_id', $targetUser->id)->count();
            if ($currentDbCount >= $targetUser->package->max_databases) {
                return back()->withErrors([
                    'db_name' => "Package limit reached: your package allows up to {$targetUser->package->max_databases} databases.",
                ]);
            }
        }

        // Standardize db name with username prefix if not already prefixed
        $prefix = $targetUser->username . '_';
        $finalDbName = str_starts_with($validated['db_name'], $prefix)
            ? $validated['db_name']
            : $prefix . $validated['db_name'];

        if (Database::where('db_name', $finalDbName)->exists()) {
            return back()->withErrors(['db_name' => "Database '{$finalDbName}' already exists."]);
        }

        // Call lc-cli db create-db
        $cliRes = $this->lcCli->run('db', ['create-db', $finalDbName]);

        if (($cliRes['status'] ?? '') !== 'success') {
            return back()->withErrors(['db_name' => 'Failed to create database: ' . ($cliRes['message'] ?? 'Error')]);
        }

        $database = Database::create([
            'user_id' => $targetUser->id,
            'domain_id' => $validated['domain_id'] ?? null,
            'db_name' => $finalDbName,
            'size_bytes' => 0,
        ]);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'db.create',
            'command' => "lc-cli db create-db {$finalDbName}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => ['db_name' => $finalDbName, 'user' => $targetUser->username],
        ]);

        return redirect()->route('databases.index')->with('success', "Database '{$finalDbName}' created successfully.");
    }

    /**
     * Delete a database.
     */
    public function destroy(Request $request, Database $database)
    {
        $user = Auth::user();

        if (! $user->isAdmin() && $database->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $protected = ['mysql', 'information_schema', 'performance_schema', 'sys', 'litecontrol', 'pdns'];
        if (in_array(strtolower($database->db_name), $protected, true)) {
            return back()->with('error', "Database '{$database->db_name}' is a protected system database.");
        }

        $res = $this->lcCli->run('db', ['delete-db', $database->db_name]);

        $name = $database->db_name;
        $database->delete();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'db.delete',
            'command' => "lc-cli db delete-db {$name}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => ['db_name' => $name],
        ]);

        return redirect()->route('databases.index')->with('success', "Database '{$name}' deleted successfully.");
    }

    /**
     * Store a database user.
     */
    public function storeUser(Request $request)
    {
        $user = Auth::user();

        $rules = [
            'username' => ['required', 'string', 'regex:/^[a-zA-Z0-9_]{1,32}$/'],
            'password' => ['required', 'string', 'min:8'],
            'database_id' => ['nullable', 'exists:databases,id'],
        ];

        if ($user->isAdmin()) {
            $rules['user_id'] = ['required', 'exists:users,id'];
        }

        $validated = $request->validate($rules);

        $targetUser = $user->isAdmin() && !empty($validated['user_id'])
            ? User::findOrFail($validated['user_id'])
            : $user;

        $prefix = $targetUser->username . '_';
        $finalDbUser = str_starts_with($validated['username'], $prefix)
            ? $validated['username']
            : $prefix . $validated['username'];

        if (DatabaseUser::where('db_username', $finalDbUser)->exists()) {
            return back()->withErrors(['username' => "Database user '{$finalDbUser}' already exists."]);
        }

        $cliRes = $this->lcCli->run('db', ['create-user', $finalDbUser, $validated['password']]);

        if (($cliRes['status'] ?? '') !== 'success') {
            return back()->withErrors(['username' => 'Failed to create DB user: ' . ($cliRes['message'] ?? 'Error')]);
        }

        $dbUser = DatabaseUser::create([
            'user_id' => $targetUser->id,
            'database_id' => $validated['database_id'] ?? null,
            'db_username' => $finalDbUser,
            'host' => 'localhost',
        ]);

        // If a database was assigned, grant privileges
        if (!empty($validated['database_id'])) {
            $database = Database::findOrFail($validated['database_id']);
            $this->lcCli->run('db', ['grant', $finalDbUser, $database->db_name]);
        }

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'db_user.create',
            'command' => "lc-cli db create-user {$finalDbUser}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => ['db_username' => $finalDbUser],
        ]);

        return redirect()->route('databases.index')->with('success', "Database user '{$finalDbUser}' created successfully.");
    }

    /**
     * Grant database user privileges to a database.
     */
    public function grantPrivileges(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'db_username' => ['required', 'string'],
            'database_id' => ['required', 'exists:databases,id'],
        ]);

        $database = Database::findOrFail($validated['database_id']);

        if (! $user->isAdmin() && $database->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $res = $this->lcCli->run('db', ['grant', $validated['db_username'], $database->db_name]);

        // Link in DB table if not linked
        DatabaseUser::where('db_username', $validated['db_username'])->update(['database_id' => $database->id]);

        return back()->with('success', "Granted '{$validated['db_username']}' full privileges on '{$database->db_name}'.");
    }

    /**
     * Update database user password.
     */
    public function updatePassword(Request $request, DatabaseUser $dbUser)
    {
        $user = Auth::user();

        if (! $user->isAdmin() && $dbUser->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $res = $this->lcCli->run('db', ['password', $dbUser->db_username, $validated['password']]);

        if (($res['status'] ?? '') === 'success') {
            return back()->with('success', "Password for DB user '{$dbUser->db_username}' updated.");
        }

        return back()->with('error', 'Failed to update password: ' . ($res['message'] ?? 'Error'));
    }

    /**
     * Delete a database user.
     */
    public function destroyUser(Request $request, DatabaseUser $dbUser)
    {
        $user = Auth::user();

        if (! $user->isAdmin() && $dbUser->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $this->lcCli->run('db', ['delete-user', $dbUser->db_username]);

        $name = $dbUser->db_username;
        $dbUser->delete();

        return redirect()->route('databases.index')->with('success', "Database user '{$name}' deleted.");
    }
}

