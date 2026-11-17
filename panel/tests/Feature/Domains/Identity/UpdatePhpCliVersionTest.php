<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $mockPhpRuntime = Mockery::mock(PhpRuntimeDriverInterface::class);
    $mockPhpRuntime->shouldReceive('listVersions')
        ->andReturn([
            ['version' => '8.4'],
            ['version' => '8.3'],
            ['version' => '8.2'],
        ]);
    app()->instance(PhpRuntimeDriverInterface::class, $mockPhpRuntime);
});

test('unauthorized user cannot update another users php cli version', function () {
    $actor = User::factory()->create(['role' => 'user', 'username' => 'alice']);
    $target = User::factory()->create(['role' => 'user', 'username' => 'bob', 'php_cli_version' => '8.3']);

    Process::fake();

    $response = $this->actingAs($actor)
        ->patch(route('users.php-cli-version', $target), ['version' => '8.2']);

    $response->assertForbidden();
    expect($target->fresh()->php_cli_version)->toBe('8.3');
    Process::assertNothingRan();
});

test('submitting uninstalled or malformed php version returns 422 without invoking process runner', function (string $invalidVersion) {
    $admin = User::factory()->create(['role' => 'admin', 'username' => 'admin1']);
    $target = User::factory()->create(['role' => 'user', 'username' => 'bob', 'php_cli_version' => '8.3']);

    Process::fake();

    $response = $this->actingAs($admin)
        ->patch(route('users.php-cli-version', $target), ['version' => $invalidVersion]);

    $response->assertSessionHasErrors('version');
    expect($target->fresh()->php_cli_version)->toBe('8.3');
    Process::assertNothingRan();
})->with([
    '7.4',
    '83',
    '8.3.1',
    '../',
    '8.3;id',
]);

test('admin can update user php cli version when host script succeeds', function () {
    $admin = User::factory()->create(['role' => 'admin', 'username' => 'admin1']);
    $target = User::factory()->create(['role' => 'user', 'username' => 'bob', 'php_cli_version' => '8.3']);

    Process::fake([
        '*' => Process::result(output: '', exitCode: 0),
    ]);

    $response = $this->actingAs($admin)
        ->patch(route('users.php-cli-version', $target), ['version' => '8.2']);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect($target->fresh()->php_cli_version)->toBe('8.2');

    Process::assertRan(function ($process) {
        return $process->command === [
            '/usr/local/lib/lc-panel/set-user-php-cli.sh',
            'bob',
            '8.2',
        ];
    });
});

test('database remains unchanged and error is logged and flashed when host script fails', function () {
    $admin = User::factory()->create(['role' => 'admin', 'username' => 'admin1']);
    $target = User::factory()->create(['role' => 'user', 'username' => 'bob', 'php_cli_version' => '8.3']);

    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: "ERR_VERIFICATION_FAILED: Expected '8.2:cli' but resolved '8.3:cli'\n",
            exitCode: 5
        ),
    ]);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $message === 'Failed to set user PHP CLI version'
                && $context['username'] === 'bob'
                && $context['requested_version'] === '8.2'
                && $context['exit_code'] === 5;
        });

    $response = $this->actingAs($admin)
        ->patch(route('users.php-cli-version', $target), ['version' => '8.2']);

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Could not switch PHP CLI version on the host.');
    expect($target->fresh()->php_cli_version)->toBe('8.3');
});
