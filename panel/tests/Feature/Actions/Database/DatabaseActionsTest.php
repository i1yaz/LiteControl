<?php

namespace Tests\Feature\Actions\Database;

use App\Actions\Database\ChangeDatabaseUserPasswordAction;
use App\Actions\Database\CreateDatabaseAction;
use App\Actions\Database\CreateDatabaseUserAction;
use App\Actions\Database\DeleteDatabaseAction;
use App\Actions\Database\DeleteDatabaseUserAction;
use App\Actions\Database\GrantDatabaseAccessAction;
use App\Exceptions\System\LcCliException;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Package;
use App\Models\User;
use App\Services\System\DatabaseCliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class DatabaseActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Database $database;
    protected DatabaseUser $databaseUser;

    protected function setUp(): void
    {
        parent::setUp();

        $package = Package::create([
            'name' => 'Basic',
            'disk_quota_mb' => 1000,
            'bandwidth_mb' => 5000,
            'max_domains' => 5,
            'max_subdomains' => 5,
            'max_databases' => 2,
            'max_ftp_accounts' => 2,
        ]);

        $this->user = User::factory()->create([
            'package_id' => $package->id,
            'username' => 'testuser',
        ]);

        $this->database = Database::create([
            'user_id' => $this->user->id,
            'db_name' => 'testuser_app',
            'size_bytes' => 0,
        ]);

        $this->databaseUser = DatabaseUser::create([
            'user_id' => $this->user->id,
            'database_id' => $this->database->id,
            'db_username' => 'testuser_dbuser',
            'host' => 'localhost',
        ]);
    }

    public function test_create_database_action(): void
    {
        $cliMock = Mockery::mock(DatabaseCliService::class);
        $cliMock->shouldReceive('createDatabase')
            ->once()
            ->with('testuser_newdb')
            ->andReturn(['status' => 'success']);

        $action = new CreateDatabaseAction($cliMock);
        $created = $action->handle('testuser_newdb', $this->user, $this->user);

        $this->assertInstanceOf(Database::class, $created);
        $this->assertDatabaseHas('databases', ['db_name' => 'testuser_newdb', 'user_id' => $this->user->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'db.create',
        ]);
    }

    public function test_delete_database_action(): void
    {
        $cliMock = Mockery::mock(DatabaseCliService::class);
        $cliMock->shouldReceive('deleteDatabase')
            ->once()
            ->with('testuser_app')
            ->andReturn([]);

        $action = new DeleteDatabaseAction($cliMock);
        $action->handle($this->database, $this->user);

        $this->assertDatabaseMissing('databases', ['id' => $this->database->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'db.delete',
        ]);
    }

    public function test_delete_protected_database_fails(): void
    {
        $protectedDb = Database::create([
            'user_id' => $this->user->id,
            'db_name' => 'mysql',
            'size_bytes' => 0,
        ]);

        $cliMock = Mockery::mock(DatabaseCliService::class);
        $cliMock->shouldNotReceive('deleteDatabase');

        $action = new DeleteDatabaseAction($cliMock);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Database 'mysql' is a protected system database.");

        $action->handle($protectedDb, $this->user);
    }

    public function test_create_database_user_action_with_grant(): void
    {
        $cliMock = Mockery::mock(DatabaseCliService::class);
        $cliMock->shouldReceive('createUser')
            ->once()
            ->with('testuser_alice', 'secret1234')
            ->andReturn([]);
        $cliMock->shouldReceive('grantUser')
            ->once()
            ->with('testuser_alice', 'testuser_app')
            ->andReturn([]);

        $action = new CreateDatabaseUserAction($cliMock);
        $created = $action->handle(
            'testuser_alice',
            'secret1234',
            $this->user,
            $this->user,
            $this->database->id
        );

        $this->assertInstanceOf(DatabaseUser::class, $created);
        $this->assertDatabaseHas('database_users', [
            'db_username' => 'testuser_alice',
            'database_id' => $this->database->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'db_user.create',
        ]);
    }

    public function test_delete_database_user_action(): void
    {
        $cliMock = Mockery::mock(DatabaseCliService::class);
        $cliMock->shouldReceive('deleteUser')
            ->once()
            ->with('testuser_dbuser')
            ->andReturn([]);

        $action = new DeleteDatabaseUserAction($cliMock);
        $action->handle($this->databaseUser, $this->user);

        $this->assertDatabaseMissing('database_users', ['id' => $this->databaseUser->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'db_user.delete',
        ]);
    }

    public function test_grant_database_access_action(): void
    {
        $cliMock = Mockery::mock(DatabaseCliService::class);
        $cliMock->shouldReceive('grantUser')
            ->once()
            ->with('testuser_dbuser', 'testuser_app')
            ->andReturn([]);

        $action = new GrantDatabaseAccessAction($cliMock);
        $action->handle('testuser_dbuser', $this->database, $this->user);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'db_user.grant',
        ]);
    }

    public function test_change_database_user_password_action(): void
    {
        $cliMock = Mockery::mock(DatabaseCliService::class);
        $cliMock->shouldReceive('changePassword')
            ->once()
            ->with('testuser_dbuser', 'newpassword123')
            ->andReturn([]);

        $action = new ChangeDatabaseUserPasswordAction($cliMock);
        $action->handle($this->databaseUser, 'newpassword123', $this->user);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'db_user.password',
        ]);
    }
}

