<?php

namespace Tests\Feature;

use App\Domains\Backup\Models\Backup;
use App\Domains\Backup\Models\BackupSchedule;
use App\Domains\Database\Models\Database as DbModel;
use App\Domains\Identity\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DatabaseAndBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->seed(DemoDataSeeder::class);
    }

    public function test_user_can_view_databases(): void
    {
        $client = User::where('username', 'client1')->first();

        $response = $this->actingAs($client)->get('/databases');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Databases/Index')
            ->has('databases')
            ->has('dbUsers')
        );
    }

    public function test_user_can_create_database_with_automatic_prefix(): void
    {
        $client = User::where('username', 'client1')->first();

        $response = $this->actingAs($client)->post('/databases', [
            'db_name' => 'laravelapp',
        ]);

        $response->assertRedirect('/databases');
        $expectedName = 'client1_laravelapp';
        $this->assertDatabaseHas('databases', [
            'db_name' => $expectedName,
            'user_id' => $client->id,
        ]);
    }

    public function test_database_creation_enforces_package_limit(): void
    {
        $client = User::where('username', 'client1')->first();
        $client->package->update(['max_databases' => 1]);

        // Client already has client1_db from seeder
        $response = $this->actingAs($client)->post('/databases', [
            'db_name' => 'extra_db',
        ]);

        $response->assertSessionHasErrors('db_name');
        $this->assertDatabaseMissing('databases', ['db_name' => 'client1_extra_db']);
    }

    public function test_user_cannot_delete_protected_database(): void
    {
        $client = User::where('username', 'client1')->first();
        $protectedDb = DbModel::create([
            'user_id' => $client->id,
            'db_name' => 'mysql',
            'size_bytes' => 100,
        ]);

        $response = $this->actingAs($client)->delete("/databases/{$protectedDb->id}");
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('databases', ['db_name' => 'mysql']);
    }

    public function test_user_can_delete_their_database(): void
    {
        $client = User::where('username', 'client1')->first();
        $db = DbModel::where('user_id', $client->id)->first();

        $response = $this->actingAs($client)->delete("/databases/{$db->id}");
        $response->assertRedirect('/databases');
        $this->assertDatabaseMissing('databases', ['id' => $db->id]);
    }

    public function test_user_can_create_db_user_and_grant_privileges(): void
    {
        $client = User::where('username', 'client1')->first();
        $db = DbModel::where('user_id', $client->id)->first();

        // 1. Create DB User
        $userRes = $this->actingAs($client)->post('/databases/users', [
            'username' => 'dbclient',
            'password' => 'secretPass1234!',
            'database_id' => $db->id,
        ]);

        $userRes->assertRedirect('/databases');
        $expectedUsername = 'client1_dbclient';
        $this->assertDatabaseHas('database_users', [
            'db_username' => $expectedUsername,
            'user_id' => $client->id,
        ]);

        // 2. Grant privileges
        $grantRes = $this->actingAs($client)->post('/databases/grant', [
            'db_username' => $expectedUsername,
            'database_id' => $db->id,
        ]);
        $grantRes->assertStatus(302);
    }

    public function test_file_manager_lists_directory_and_blocks_path_traversal(): void
    {
        $client = User::where('username', 'client1')->first();

        // Safe listing
        $listRes = $this->actingAs($client)->getJson('/files/list?path=');
        $listRes->assertStatus(200);
        $listRes->assertJsonStructure(['status', 'current_path', 'items']);

        // Traversal attempt (../../etc)
        $traversalRes = $this->actingAs($client)->getJson('/files/list?path=../../etc');
        // Will sanitize or reject
        $this->assertStringNotContainsString('passwd', (string) $traversalRes->getContent());
    }

    public function test_file_manager_can_create_save_and_delete_file(): void
    {
        $client = User::where('username', 'client1')->first();

        // 1. Create file
        $createRes = $this->actingAs($client)->postJson('/files/create', [
            'path' => '',
            'name' => 'testfile.txt',
            'type' => 'file',
        ]);
        $createRes->assertJson(['status' => 'success']);

        // 2. Save content
        $saveRes = $this->actingAs($client)->postJson('/files/save', [
            'path' => 'testfile.txt',
            'content' => 'Hello from LiteControl tests!',
        ]);
        $saveRes->assertJson(['status' => 'success']);

        // 3. Read content
        $readRes = $this->actingAs($client)->getJson('/files/content?path=testfile.txt');
        $readRes->assertJson([
            'status' => 'success',
            'content' => 'Hello from LiteControl tests!',
        ]);

        // 4. Delete file
        $delRes = $this->actingAs($client)->postJson('/files/delete', [
            'path' => 'testfile.txt',
        ]);
        $delRes->assertJson(['status' => 'success']);
    }

    public function test_user_can_create_and_restore_backup(): void
    {
        $client = User::where('username', 'client1')->first();

        // View Backups
        $viewRes = $this->actingAs($client)->get('/backups');
        $viewRes->assertStatus(200);
        $viewRes->assertInertia(fn (Assert $page) => $page->component('Backups/Index'));

        // Trigger Backup
        $createRes = $this->actingAs($client)->post('/backups/create');
        $createRes->assertStatus(302);
        $this->assertDatabaseHas('backups', [
            'user_id' => $client->id,
            'type' => 'manual',
            'status' => 'completed',
        ]);

        $backup = Backup::where('user_id', $client->id)->first();

        // Restore Backup
        $restoreRes = $this->actingAs($client)->post('/backups/restore', [
            'backup_path' => $backup->path,
        ]);
        $restoreRes->assertStatus(302);
    }

    public function test_user_can_configure_and_delete_backup_schedule(): void
    {
        $client = User::where('username', 'client1')->first();

        $scheduleRes = $this->actingAs($client)->post('/backups/schedules', [
            'frequency' => 'daily',
            'retention_count' => 7,
            'destination' => 'local',
        ]);

        $scheduleRes->assertStatus(302);
        $this->assertDatabaseHas('backup_schedules', [
            'user_id' => $client->id,
            'frequency' => 'daily',
            'retention_count' => 7,
        ]);

        $sched = BackupSchedule::where('user_id', $client->id)->first();

        $delRes = $this->actingAs($client)->delete("/backups/schedules/{$sched->id}");
        $delRes->assertStatus(302);
        $this->assertDatabaseMissing('backup_schedules', ['id' => $sched->id]);
    }
}
