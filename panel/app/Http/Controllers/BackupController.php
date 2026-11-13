<?php

namespace App\Http\Controllers;

use App\Domains\Backup\Actions\AddBackupScheduleAction;
use App\Domains\Backup\Actions\CreateBackupAction;
use App\Domains\Backup\Actions\RemoveBackupScheduleAction;
use App\Domains\Backup\Actions\RestoreBackupAction;
use App\Domains\Backup\Contracts\BackupDriverInterface;
use App\Domains\Backup\Models\Backup;
use App\Domains\Backup\Models\BackupSchedule;
use App\Domains\Backup\ValueObjects\BackupFrequency;
use App\Domains\Backup\ValueObjects\BackupPath;
use App\Domains\Backup\ValueObjects\RetentionCount;
use App\Exceptions\System\LcCliException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class BackupController extends Controller
{
    public function __construct(
        protected BackupDriverInterface $backupDriver
    ) {}

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
        $cliBackups = $this->backupDriver->listBackups($user->isAdmin() ? null : $user->username);

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
    public function create(Request $request, CreateBackupAction $createBackupAction)
    {
        $user = Auth::user();

        $targetUser = $user;
        if ($user->isAdmin() && $request->filled('user_id')) {
            $targetUser = User::findOrFail($request->input('user_id'));
        }

        try {
            $backup = $createBackupAction->handle($targetUser, $user, $request->ip());

            return back()->with('success', "Backup '{$backup->filename}' created successfully.");
        } catch (LcCliException $e) {
            return back()->with('error', 'Backup creation failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', 'Backup creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Restore an existing backup archive.
     */
    public function restore(Request $request, RestoreBackupAction $restoreBackupAction)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'backup_path' => ['required', 'string'],
        ]);

        try {
            $restoreBackupAction->handle(new BackupPath($validated['backup_path']), $user, $request->ip());

            return back()->with('success', 'Backup restored successfully.');
        } catch (LcCliException $e) {
            return back()->with('error', 'Restore failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', 'Restore failed: ' . $e->getMessage());
        }
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
    public function storeSchedule(Request $request, AddBackupScheduleAction $addBackupScheduleAction)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'frequency' => ['required', 'in:daily,weekly,monthly'],
            'retention_count' => ['required', 'integer', 'min:1', 'max:30'],
            'destination' => ['required', 'in:local,sftp,s3'],
        ]);

        try {
            $addBackupScheduleAction->handle(
                $user,
                new BackupFrequency($validated['frequency']),
                new RetentionCount((int) $validated['retention_count']),
                $validated['destination'],
                $user,
                $request->ip()
            );

            return back()->with('success', 'Automated backup schedule configured.');
        } catch (LcCliException $e) {
            return back()->with('error', 'Failed to configure schedule: ' . $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to configure schedule: ' . $e->getMessage());
        }
    }

    /**
     * Delete automated backup schedule.
     */
    public function destroySchedule(
        Request $request,
        BackupSchedule $schedule,
        RemoveBackupScheduleAction $removeBackupScheduleAction
    ) {
        $user = Auth::user();

        if (! $user->isAdmin() && $schedule->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        try {
            $removeBackupScheduleAction->handle($schedule, $user, $request->ip());

            return back()->with('success', 'Backup schedule removed.');
        } catch (LcCliException $e) {
            return back()->with('error', 'Failed to remove schedule: ' . $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to remove schedule: ' . $e->getMessage());
        }
    }
}
