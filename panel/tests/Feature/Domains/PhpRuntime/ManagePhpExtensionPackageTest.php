<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\PhpRuntime;

use App\Domains\PhpRuntime\Contracts\PhpRuntimeDriverInterface;
use App\Domains\PhpRuntime\ValueObjects\PhpExtensionName;
use App\Domains\PhpRuntime\ValueObjects\PhpVersion;
use App\Exceptions\System\LcCliException;
use App\Domains\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ManagePhpExtensionPackageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'username' => 'admin_test']);
        $this->user = User::factory()->create(['role' => 'user', 'username' => 'user_test']);
    }

    public function test_admin_can_install_php_extension_package(): void
    {
        $mock = Mockery::mock(PhpRuntimeDriverInterface::class);
        $mock->shouldReceive('installExtensionPackage')
            ->once()
            ->withArgs(fn (PhpVersion $v, PhpExtensionName $e) => $v->value === '83' && $e->value === 'imagick')
            ->andReturn(['installed' => 'imagick']);

        $this->app->instance(PhpRuntimeDriverInterface::class, $mock);

        $this->actingAs($this->admin)
            ->post('/php/extensions/install', [
                'version' => '83',
                'extension' => 'imagick',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_admin_can_uninstall_php_extension_package(): void
    {
        $mock = Mockery::mock(PhpRuntimeDriverInterface::class);
        $mock->shouldReceive('uninstallExtensionPackage')
            ->once()
            ->withArgs(fn (PhpVersion $v, PhpExtensionName $e) => $v->value === '83' && $e->value === 'imagick')
            ->andReturn(['uninstalled' => 'imagick']);

        $this->app->instance(PhpRuntimeDriverInterface::class, $mock);

        $this->actingAs($this->admin)
            ->post('/php/extensions/uninstall', [
                'version' => '83',
                'extension' => 'imagick',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_non_admin_cannot_install_or_uninstall_php_extensions(): void
    {
        $this->actingAs($this->user)
            ->post('/php/extensions/install', ['version' => '83', 'extension' => 'imagick'])
            ->assertForbidden();

        $this->actingAs($this->user)
            ->post('/php/extensions/uninstall', ['version' => '83', 'extension' => 'imagick'])
            ->assertForbidden();
    }

    public function test_validates_version_and_extension_name(): void
    {
        $this->actingAs($this->admin)
            ->post('/php/extensions/install', ['version' => '99', 'extension' => 'bad;cmd'])
            ->assertSessionHasErrors(['version', 'extension']);
    }

    public function test_returns_error_flash_when_cli_throws_exception(): void
    {
        $mock = Mockery::mock(PhpRuntimeDriverInterface::class);
        $mock->shouldReceive('installExtensionPackage')
            ->once()
            ->andThrow(new LcCliException('apt-get failed', 'php', ['ext-install', '83', 'imagick']));

        $this->app->instance(PhpRuntimeDriverInterface::class, $mock);

        $this->actingAs($this->admin)
            ->post('/php/extensions/install', [
                'version' => '83',
                'extension' => 'imagick',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
