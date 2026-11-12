<?php

namespace Tests\Feature\Actions\Backup;

use App\Actions\Backup\AddBackupScheduleAction;
use App\Actions\Backup\CreateBackupAction;
use App\Actions\Backup\RemoveBackupScheduleAction;
use App\Actions\Backup\RestoreBackupAction;
use App\Exceptions\System\LcCliException;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\Package;
use App\Models\User;
use App\Services\System\BackupCliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BackupActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $package = Package::create([
            'name' => 'Standard',
            'disk_quota_mb' => 2000,
            'bandwidth_mb' => 10000,
            'max_domains' => 5,
            'max_subdomains' => 5,
            'max_databases' => 5,
            'max_ftp_accounts' => 5,
        ]);

        $this->user = User::factory()->create([
            'package_id' => $package->id,
            'username' => 'backupuser',
        ]);
    }

    public function test_create_backup_action(): void
    {
        $cliMock = Mockery::mock(BackupCliService::class);
        $cliMock->shouldReceive('createBackup')
            ->once()
            ->with('backupuser')
            ->andReturn([
                'filename' => 'backup-backupuser-20261111_120000.tar.gz',
                'path' => '/home/backupuser/backups/backup-backupuser-20261111_120000.tar.gz',
                'size_bytes' => 1048576,
            ]);

        $action = new CreateBackupAction($cliMock);
        $backup = $action->handle($this->user, $this->user, '127.0.0.1');

        $this->assertInstanceOf(Backup::class, $backup);
        $this->assertDatabaseHas('backups', [
            'user_id' => $this->user->id,
            'filename' => 'backup-backupuser-20261111_120000.tar.gz',
            'path' => '/home/backupuser/backups/backup-backupuser-20261111_120000.tar.gz',
            'size_bytes' => 1048576,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'backup.create',
            'ip_address' => '127.0.0.1',
        ]);
    }

    public function test_create_backup_action_handles_cli_failure(): void
    {
        $cliMock = Mockery::mock(BackupCliService::class);
        $cliMock->shouldReceive('createBackup')
            ->once()
            ->with('backupuser')
            ->andThrow(new LcCliException('Backup creation failed.', 'backup', ['create', 'backupuser']));

        $this->expectException(LcCliException::class);

        $action = new CreateBackupAction($cliMock);
        $action->handle($this->user, $this->user);

        $this->assertDatabaseCount('backups', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_restore_backup_action(): void
    {
        $cliMock = Mockery::mock(BackupCliService::class);
        $cliMock->shouldReceive('restoreBackup')
            ->once()
            ->with('/home/backupuser/backups/backup.tar.gz')
            ->andReturn(['status' => 'success']);

        $action = new RestoreBackupAction($cliMock);
        $res = $action->handle('/home/backupuser/backups/backup.tar.gz', $this->user, '127.0.0.1');

        $this->assertEquals(['status' => 'success'], $res);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'backup.restore',
            'command' => 'lc-cli backup restore /home/backupuser/backups/backup.tar.gz',
        ]);
    }

    public function test_add_backup_schedule_action(): void
    {
        $cliMock = Mockery::mock(BackupCliService::class);
        $cliMock->shouldReceive('addSchedule')
            ->once()
            ->with('backupuser', 'daily', 7)
            ->andReturn(['status' => 'success']);

        $action = new AddBackupScheduleAction($cliMock);
        $schedule = $action->handle($this->user, 'daily', 7, 'local', $this->user, '127.0.0.1');

        $this->assertInstanceOf(BackupSchedule::class, $schedule);
        $this->assertDatabaseHas('backup_schedules', [
            'user_id' => $this->user->id,
            'frequency' => 'daily',
            'retention_count' => 7,
            'destination' => 'local',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'backup.schedule.add',
        ]);
    }

    public function test_remove_backup_schedule_action(): void
    {
        $schedule = BackupSchedule::create([
            'user_id' => $this->user->id,
            'frequency' => 'weekly',
            'retention_count' => 4,
            'destination' => 'local',
            'is_active' => true,
        ]);

        $cliMock = Mockery::mock(BackupCliService::class);
        $cliMock->shouldReceive('removeSchedule')
            ->once()
            ->with('backupuser')
            ->andReturn(['status' => 'success']);

        $action = new RemoveBackupScheduleAction($cliMock);
        $action->handle($schedule, $this->user, '127.0.0.1');

        $this->assertDatabaseMissing('backup_schedules', ['id' => $schedule->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'backup.schedule.remove',
        ]);
    }
}

