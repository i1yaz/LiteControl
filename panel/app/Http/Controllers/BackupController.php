<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\LcCliService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class BackupController extends Controller
{
    protected LcCliService $lcCli;

    public function __construct(LcCliService $lcCli)
    {
        $this->lcCli = $lcCli;
    }

    /**
     * Display a listing of backups and schedules.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();

        $query = Backup::with('user:id,username');
        $scheduleQuery = BackupSchedule::with('user:id,username');

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
            $scheduleQuery->where('user_id', $user->id);
        }

        $backups = $query->latest()->get();
        $schedules = $scheduleQuery->latest()->get();

        // Also fetch filesystem backups reported by lc-cli
        $cliRes = $this->lcCli->run('backup', ['list', $user->isAdmin() ? '' : $user->username]);
        $cliBackups = ($cliRes['status'] ?? '') === 'success' ? ($cliRes['data'] ?? []) : [];

        return Inertia::render('Backups/Index', [
            'backups' => $backups,
            'cliBackups' => $cliBackups,
            'schedules' => $schedules,
            'users' => $user->isAdmin() ? User::where('role', 'user')->get(['id', 'username']) : [],
        ]);
    }

    /**
     * Create an on-demand full backup.
     */
    public function create(Request $request)
    {
        $user = Auth::user();

        $targetUser = $user;
        if ($user->isAdmin() && $request->filled('user_id')) {
            $targetUser = User::findOrFail($request->input('user_id'));
        }

        $cliRes = $this->lcCli->run('backup', ['create', $targetUser->username]);

        if (($cliRes['status'] ?? '') === 'success') {
            $data = $cliRes['data'] ?? [];
            $filename = $data['filename'] ?? ("backup-" . $targetUser->username . "-" . now()->format('Ymd_His') . ".tar.gz");
            $path = $data['path'] ?? ("/home/{$targetUser->username}/backups/{$filename}");
            $size = $data['size_bytes'] ?? 0;

            $backup = Backup::create([
                'user_id' => $targetUser->id,
                'filename' => $filename,
                'path' => $path,
                'size_bytes' => $size,
                'type' => 'manual',
                'status' => 'completed',
            ]);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'backup.create',
                'command' => "lc-cli backup create {$targetUser->username}",
                'status' => 'success',
                'ip_address' => $request->ip(),
                'details' => ['filename' => $filename, 'target_user' => $targetUser->username],
            ]);

            return back()->with('success', "Backup '{$filename}' created successfully.");
        }

        return back()->with('error', 'Backup creation failed: ' . ($cliRes['message'] ?? 'Error'));
    }

    /**
     * Restore an existing backup archive.
     */
    public function restore(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'backup_path' => ['required', 'string'],
        ]);

        $res = $this->lcCli->run('backup', ['restore', $validated['backup_path']]);

        if (($res['status'] ?? '') === 'success') {
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'backup.restore',
                'command' => "lc-cli backup restore {$validated['backup_path']}",
                'status' => 'success',
                'ip_address' => $request->ip(),
                'details' => ['path' => $validated['backup_path']],
            ]);

            return back()->with('success', 'Backup restored successfully.');
        }

        return back()->with('error', 'Restore failed: ' . ($res['message'] ?? 'Error'));
    }

    /**
     * Delete a backup archive.
     */
    public function destroy(Request $request, Backup $backup)
    {
        $user = Auth::user();

        if (! $user->isAdmin() && $backup->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        if (file_exists($backup->path)) {
            @unlink($backup->path);
        }

        $filename = $backup->filename;
        $backup->delete();

        return back()->with('success', "Backup '{$filename}' deleted.");
    }

    /**
     * Store automated backup schedule.
     */
    public function storeSchedule(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'frequency' => ['required', 'in:daily,weekly,monthly'],
            'retention_count' => ['required', 'integer', 'min:1', 'max:30'],
            'destination' => ['required', 'in:local,sftp,s3'],
        ]);

        $schedule = BackupSchedule::create([
            'user_id' => $user->id,
            'frequency' => $validated['frequency'],
            'retention_count' => $validated['retention_count'],
            'destination' => $validated['destination'],
            'is_active' => true,
        ]);

        // Register schedule with lc-cli
        $this->lcCli->run('backup', ['schedule', 'add', $user->username, $validated['frequency'], (string) $validated['retention_count']]);

        return back()->with('success', 'Automated backup schedule configured.');
    }

    /**
     * Delete automated backup schedule.
     */
    public function destroySchedule(Request $request, BackupSchedule $schedule)
    {
        $user = Auth::user();

        if (! $user->isAdmin() && $schedule->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $this->lcCli->run('backup', ['schedule', 'remove', $schedule->user->username ?? $user->username]);

        $schedule->delete();

        return back()->with('success', 'Backup schedule removed.');
    }
}

