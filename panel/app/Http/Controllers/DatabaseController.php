<?php

namespace App\Http\Controllers;

use App\Actions\Database\ChangeDatabaseUserPasswordAction;
use App\Actions\Database\CreateDatabaseAction;
use App\Actions\Database\CreateDatabaseUserAction;
use App\Actions\Database\DeleteDatabaseAction;
use App\Actions\Database\DeleteDatabaseUserAction;
use App\Actions\Database\GrantDatabaseAccessAction;
use App\Exceptions\System\LcCliException;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\User;
use App\Services\System\DatabaseCliService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class DatabaseController extends Controller
{
    public function __construct(
        protected DatabaseCliService $databaseCli
    ) {}

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

        // Fetch real-time sizes from DatabaseCliService
        $liveDbMap = [];
        try {
            $cliData = $this->databaseCli->listDatabases();
            foreach ($cliData as $dbItem) {
                $liveDbMap[$dbItem['name'] ?? ''] = [
                    'size_bytes' => $dbItem['size_bytes'] ?? 0,
                    'size_human' => $dbItem['size_human'] ?? '0 B',
                    'tables' => $dbItem['tables'] ?? 0,
                ];
            }
        } catch (\Throwable $e) {
            // Keep empty map if listing fails
        }

        return Inertia::render('Databases/Index', [
            'databases' => $databases,
            'dbUsers' => $dbUsers,
            'liveDbMap' => $liveDbMap,
            'users' => $user->isAdmin() ? User::where('role', 'user')->get(['id', 'username']) : [],
        ]);
    }

    /**
     * Store a newly created database.
     */
    public function store(Request $request, CreateDatabaseAction $createDatabaseAction)
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

        try {
            $createDatabaseAction->handle(
                dbName: $finalDbName,
                targetUser: $targetUser,
                actor: $user,
                domainId: $validated['domain_id'] ?? null,
                ipAddress: $request->ip()
            );
        } catch (LcCliException $e) {
            return back()->withErrors(['db_name' => 'Failed to create database: ' . $e->getMessage()]);
        }

        return redirect()->route('databases.index')->with('success', "Database '{$finalDbName}' created successfully.");
    }

    /**
     * Delete a database.
     */
    public function destroy(Request $request, Database $database, DeleteDatabaseAction $deleteDatabaseAction)
    {
        $user = Auth::user();

        if (! $user->isAdmin() && $database->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $name = $database->db_name;

        try {
            $deleteDatabaseAction->handle($database, $user, $request->ip());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (LcCliException $e) {
            return back()->with('error', 'Failed to delete database: ' . $e->getMessage());
        }

        return redirect()->route('databases.index')->with('success', "Database '{$name}' deleted successfully.");
    }

    /**
     * Store a database user.
     */
    public function storeUser(Request $request, CreateDatabaseUserAction $createDatabaseUserAction)
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

        try {
            $createDatabaseUserAction->handle(
                username: $finalDbUser,
                password: $validated['password'],
                targetUser: $targetUser,
                actor: $user,
                databaseId: !empty($validated['database_id']) ? (int) $validated['database_id'] : null,
                ipAddress: $request->ip()
            );
        } catch (LcCliException $e) {
            return back()->withErrors(['username' => 'Failed to create DB user: ' . $e->getMessage()]);
        }

        return redirect()->route('databases.index')->with('success', "Database user '{$finalDbUser}' created successfully.");
    }

    /**
     * Grant database user privileges to a database.
     */
    public function grantPrivileges(Request $request, GrantDatabaseAccessAction $grantDatabaseAccessAction)
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

        try {
            $grantDatabaseAccessAction->handle($validated['db_username'], $database, $user, $request->ip());
        } catch (LcCliException $e) {
            return back()->with('error', 'Failed to grant privileges: ' . $e->getMessage());
        }

        return back()->with('success', "Granted '{$validated['db_username']}' full privileges on '{$database->db_name}'.");
    }

    /**
     * Update database user password.
     */
    public function updatePassword(
        Request $request,
        DatabaseUser $dbUser,
        ChangeDatabaseUserPasswordAction $changePasswordAction
    ) {
        $user = Auth::user();

        if (! $user->isAdmin() && $dbUser->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        try {
            $changePasswordAction->handle($dbUser, $validated['password'], $user, $request->ip());
            return back()->with('success', "Password for DB user '{$dbUser->db_username}' updated.");
        } catch (LcCliException $e) {
            return back()->with('error', 'Failed to update password: ' . $e->getMessage());
        }
    }

    /**
     * Delete a database user.
     */
    public function destroyUser(
        Request $request,
        DatabaseUser $dbUser,
        DeleteDatabaseUserAction $deleteDatabaseUserAction
    ) {
        $user = Auth::user();

        if (! $user->isAdmin() && $dbUser->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $name = $dbUser->db_username;

        try {
            $deleteDatabaseUserAction->handle($dbUser, $user, $request->ip());
        } catch (LcCliException $e) {
            return back()->with('error', 'Failed to delete database user: ' . $e->getMessage());
        }

        return redirect()->route('databases.index')->with('success', "Database user '{$name}' deleted.");
    }
}
