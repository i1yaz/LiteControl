<?php

use App\Domains\Identity\Models\User;
use App\Domains\WebServer\Contracts\FtpDriverInterface;
use App\Domains\WebServer\Models\Domain;
use App\Domains\WebServer\Models\FtpAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->ftpDriver = Mockery::mock(FtpDriverInterface::class);
    $this->ftpDriver->shouldReceive('ensureDirectory')->byDefault();
    $this->ftpDriver->shouldReceive('resolveUidGid')->andReturn(['uid' => 1001, 'gid' => 1001])->byDefault();
    $this->app->instance(FtpDriverInterface::class, $this->ftpDriver);
});

function createFtpTestDomain(User $owner, string $domainName): Domain
{
    return Domain::create([
        'user_id' => $owner->id,
        'domain' => $domainName,
        'document_root' => "/home/{$owner->username}/{$domainName}/public_html",
        'php_version' => '8.3',
        'status' => 'active',
    ]);
}

function createFtpTestAccount(User $owner, Domain $domain, string $username, int $quotaMb = 100): FtpAccount
{
    return FtpAccount::create([
        'user_id' => $owner->id,
        'domain_id' => $domain->id,
        'username' => $username,
        'password_hash' => password_hash('InitialPass123!', PASSWORD_DEFAULT),
        'uid' => 1001,
        'gid' => 1001,
        'dir' => "/home/{$owner->username}/{$domain->domain}/public_html",
        'quota_mb' => $quotaMb,
        'status' => 'active',
    ]);
}

test('user can list only their own ftp accounts with domain and search filters', function () {
    $alice = User::factory()->create(['username' => 'alice', 'role' => 'user']);
    $bob = User::factory()->create(['username' => 'bob', 'role' => 'user']);

    $aliceDomain = createFtpTestDomain($alice, 'alice.test');
    $bobDomain = createFtpTestDomain($bob, 'bob.test');

    createFtpTestAccount($alice, $aliceDomain, 'alice_deploy');
    createFtpTestAccount($alice, $aliceDomain, 'alice_media');
    createFtpTestAccount($bob, $bobDomain, 'bob_secret');

    $response = $this->actingAs($alice)->get('/ftp?search=deploy');

    $response->assertOk();
    $page = $response->viewData('page');
    expect($page['component'])->toBe('Ftp/Index');
    expect($page['props']['ftpUsers']['data'])->toHaveCount(1);
    expect($page['props']['ftpUsers']['data'][0]['username'])->toBe('alice_deploy');
    expect($page['props']['domains'])->toHaveCount(1);
    expect($page['props']['connectionInfo'])->toHaveKeys(['host', 'port']);
});

test('admin can see ftp accounts across all users', function () {
    $admin = User::factory()->create(['username' => 'admin', 'role' => 'admin']);
    $alice = User::factory()->create(['username' => 'alice', 'role' => 'user']);
    $bob = User::factory()->create(['username' => 'bob', 'role' => 'user']);

    $aliceDomain = createFtpTestDomain($alice, 'alice.test');
    $bobDomain = createFtpTestDomain($bob, 'bob.test');

    createFtpTestAccount($alice, $aliceDomain, 'alice_ftp');
    createFtpTestAccount($bob, $bobDomain, 'bob_ftp');

    $response = $this->actingAs($admin)->get('/ftp');

    $response->assertOk();
    $page = $response->viewData('page');
    expect($page['props']['ftpUsers']['data'])->toHaveCount(2);
    expect($page['props']['domains'])->toHaveCount(2);
});

test('user can create an ftp account for their own domain', function () {
    $alice = User::factory()->create(['username' => 'alice', 'role' => 'user']);
    $domain = createFtpTestDomain($alice, 'alice.test');

    $response = $this->actingAs($alice)->post('/ftp', [
        'domain_id' => $domain->id,
        'username' => 'uploader',
        'password' => 'SuperSecret123!',
        'home_directory' => 'uploads',
        'disk_quota' => 250,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $account = FtpAccount::where('username', 'alice_uploader')->first();
    expect($account)->not->toBeNull();
    expect($account->dir)->toBe('/home/alice/alice.test/public_html/uploads');
    expect($account->quota_mb)->toBe(250);
    expect(password_verify('SuperSecret123!', $account->password_hash))->toBeTrue();
});

test('creating ftp account rejects path traversal in home_directory', function () {
    $alice = User::factory()->create(['username' => 'alice', 'role' => 'user']);
    $domain = createFtpTestDomain($alice, 'alice.test');

    $response = $this->actingAs($alice)->post('/ftp', [
        'domain_id' => $domain->id,
        'username' => 'badpath',
        'password' => 'SuperSecret123!',
        'home_directory' => '../../etc',
        'disk_quota' => 0,
    ]);

    $response->assertSessionHasErrors('home_directory');
    expect(FtpAccount::where('username', 'alice_badpath')->exists())->toBeFalse();
});

test('user cannot create ftp account for another users domain', function () {
    $alice = User::factory()->create(['username' => 'alice', 'role' => 'user']);
    $bob = User::factory()->create(['username' => 'bob', 'role' => 'user']);
    $bobDomain = createFtpTestDomain($bob, 'bob.test');

    $response = $this->actingAs($alice)->post('/ftp', [
        'domain_id' => $bobDomain->id,
        'username' => 'intruder',
        'password' => 'SuperSecret123!',
        'home_directory' => '',
        'disk_quota' => 100,
    ]);

    $response->assertForbidden();
    expect(FtpAccount::count())->toBe(0);
});

test('user can update home directory and quota for their own ftp account', function () {
    $alice = User::factory()->create(['username' => 'alice', 'role' => 'user']);
    $domain = createFtpTestDomain($alice, 'alice.test');
    $ftp = createFtpTestAccount($alice, $domain, 'alice_app', 100);

    $response = $this->actingAs($alice)->put("/ftp/{$ftp->id}", [
        'home_directory' => 'releases/current',
        'disk_quota' => 500,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $ftp->refresh();
    expect($ftp->dir)->toBe('/home/alice/alice.test/public_html/releases/current');
    expect($ftp->quota_mb)->toBe(500);
});

test('user can change password for their own ftp account', function () {
    $alice = User::factory()->create(['username' => 'alice', 'role' => 'user']);
    $domain = createFtpTestDomain($alice, 'alice.test');
    $ftp = createFtpTestAccount($alice, $domain, 'alice_app');

    $response = $this->actingAs($alice)->patch("/ftp/{$ftp->id}/password", [
        'password' => 'NewStrongPass999!',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $ftp->refresh();
    expect(password_verify('NewStrongPass999!', $ftp->password_hash))->toBeTrue();
});

test('user can delete their own ftp account', function () {
    $alice = User::factory()->create(['username' => 'alice', 'role' => 'user']);
    $domain = createFtpTestDomain($alice, 'alice.test');
    $ftp = createFtpTestAccount($alice, $domain, 'alice_app');

    $response = $this->actingAs($alice)->delete("/ftp/{$ftp->id}");

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect(FtpAccount::find($ftp->id))->toBeNull();
});

test('user cannot update, change password, or delete another users ftp account', function () {
    $alice = User::factory()->create(['username' => 'alice', 'role' => 'user']);
    $bob = User::factory()->create(['username' => 'bob', 'role' => 'user']);
    $bobDomain = createFtpTestDomain($bob, 'bob.test');
    $bobFtp = createFtpTestAccount($bob, $bobDomain, 'bob_app');

    $this->actingAs($alice)->put("/ftp/{$bobFtp->id}", [
        'home_directory' => 'hacked',
        'disk_quota' => 999,
    ])->assertForbidden();

    $this->actingAs($alice)->patch("/ftp/{$bobFtp->id}/password", [
        'password' => 'HackedPass123!',
    ])->assertForbidden();

    $this->actingAs($alice)->delete("/ftp/{$bobFtp->id}")->assertForbidden();

    expect(FtpAccount::find($bobFtp->id))->not->toBeNull();
});
