<?php

namespace Tests\Feature\Domains\Database;

use App\Domains\Database\Actions\ChangeDatabaseUserPasswordAction;
use App\Domains\Database\Actions\CreateDatabaseAction;
use App\Domains\Database\Actions\CreateDatabaseUserAction;
use App\Domains\Database\Actions\DeleteDatabaseAction;
use App\Domains\Database\Actions\DeleteDatabaseUserAction;
use App\Domains\Database\Actions\GrantDatabaseAccessAction;
use App\Domains\Database\Contracts\DatabaseDriverInterface;
use App\Domains\Database\Models\Database;
use App\Domains\Database\Models\DatabaseUser;
use App\Domains\Database\ValueObjects\DatabaseName;
use App\Domains\Database\ValueObjects\DatabaseUsername;
use App\Models\Package;
use App\Models\User;
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
        $driverMock = Mockery::mock(DatabaseDriverInterface::class);
        $driverMock->shouldReceive('createDatabase')
            ->once()
            ->with(Mockery::on(fn (DatabaseName $n) => $n->value === 'testuser_newdb'))
            ->andReturn(['status' => 'success']);

        $action = new CreateDatabaseAction($driverMock);
        $created = $action->handle(DatabaseName::fromString('testuser_newdb'), $this->user, $this->user);

        $this->assertInstanceOf(Database::class, $created);
        $this->assertDatabaseHas('databases', ['db_name' => 'testuser_newdb', 'user_id' => $this->user->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'db.create',
        ]);
    }

    public function test_delete_database_action(): void
    {
        $driverMock = Mockery::mock(DatabaseDriverInterface::class);
        $driverMock->shouldReceive('deleteDatabase')
            ->once()
            ->with(Mockery::on(fn (DatabaseName $n) => $n->value === 'testuser_app'))
            ->andReturn([]);

        $action = new DeleteDatabaseAction($driverMock);
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

        $driverMock = Mockery::mock(DatabaseDriverInterface::class);
        $driverMock->shouldNotReceive('deleteDatabase');

        $action = new DeleteDatabaseAction($driverMock);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Database 'mysql' is a protected system database.");

        $action->handle($protectedDb, $this->user);
    }

    public function test_create_database_user_action_with_grant(): void
    {
        $driverMock = Mockery::mock(DatabaseDriverInterface::class);
        $driverMock->shouldReceive('createUser')
            ->once()
            ->with(Mockery::on(fn (DatabaseUsername $u) => $u->value === 'testuser_alice'), 'secret1234')
            ->andReturn([]);
        $driverMock->shouldReceive('grantUser')
            ->once()
            ->with(
                Mockery::on(fn (DatabaseUsername $u) => $u->value === 'testuser_alice'),
                Mockery::on(fn (DatabaseName $n) => $n->value === 'testuser_app')
            )
            ->andReturn([]);

        $action = new CreateDatabaseUserAction($driverMock);
        $created = $action->handle(
            DatabaseUsername::fromString('testuser_alice'),
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
        $driverMock = Mockery::mock(DatabaseDriverInterface::class);
        $driverMock->shouldReceive('deleteUser')
            ->once()
            ->with(Mockery::on(fn (DatabaseUsername $u) => $u->value === 'testuser_dbuser'))
            ->andReturn([]);

        $action = new DeleteDatabaseUserAction($driverMock);
        $action->handle($this->databaseUser, $this->user);

        $this->assertDatabaseMissing('database_users', ['id' => $this->databaseUser->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'db_user.delete',
        ]);
    }

    public function test_grant_database_access_action(): void
    {
        $driverMock = Mockery::mock(DatabaseDriverInterface::class);
        $driverMock->shouldReceive('grantUser')
            ->once()
            ->with(
                Mockery::on(fn (DatabaseUsername $u) => $u->value === 'testuser_dbuser'),
                Mockery::on(fn (DatabaseName $n) => $n->value === 'testuser_app')
            )
            ->andReturn([]);

        $action = new GrantDatabaseAccessAction($driverMock);
        $action->handle(DatabaseUsername::fromString('testuser_dbuser'), $this->database, $this->user);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'db_user.grant',
        ]);
    }

    public function test_change_database_user_password_action(): void
    {
        $driverMock = Mockery::mock(DatabaseDriverInterface::class);
        $driverMock->shouldReceive('changePassword')
            ->once()
            ->with(Mockery::on(fn (DatabaseUsername $u) => $u->value === 'testuser_dbuser'), 'newpassword123')
            ->andReturn([]);

        $action = new ChangeDatabaseUserPasswordAction($driverMock);
        $action->handle($this->databaseUser, 'newpassword123', $this->user);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'db_user.password',
        ]);
    }
}

