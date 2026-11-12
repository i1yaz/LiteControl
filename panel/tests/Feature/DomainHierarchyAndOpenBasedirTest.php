<?php

namespace Tests\Feature;

use App\Actions\Domain\DeleteDomainAction;
use App\Models\Domain;
use App\Models\User;
use App\Services\DomainService;
use App\Services\LcCliService;
use App\Services\System\VhostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DomainHierarchyAndOpenBasedirTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $lcCliMock = Mockery::mock(LcCliService::class);
        $lcCliMock->shouldReceive('run')->andReturn(['status' => 'success']);
        $this->app->instance(LcCliService::class, $lcCliMock);
    }

    public function test_it_auto_detects_multi_level_parent_domains(): void
    {
        $user = User::factory()->create(['username' => 'testuser']);
        $service = app(DomainService::class);

        $root = $service->createDomain($user, ['domain' => 'example.com', 'ssl_enabled' => false])['domain'];
        $api = $service->createDomain($user, ['domain' => 'api.example.com', 'ssl_enabled' => false])['domain'];
        $v1 = $service->createDomain($user, ['domain' => 'v1.api.example.com', 'ssl_enabled' => false])['domain'];

        $this->assertEquals('primary', $root->type);
        $this->assertNull($root->parent_domain_id);

        $this->assertEquals('subdomain', $api->type);
        $this->assertEquals($root->id, $api->parent_domain_id);

        $this->assertEquals('subdomain', $v1->type);
        $this->assertEquals($api->id, $v1->parent_domain_id);
    }

    public function test_it_treats_subdomain_as_standalone_when_parent_does_not_exist(): void
    {
        $user = User::factory()->create(['username' => 'testuser']);
        $service = app(DomainService::class);

        $standalone = $service->createDomain($user, ['domain' => 'app.client.com', 'ssl_enabled' => false])['domain'];

        $this->assertEquals('primary', $standalone->type);
        $this->assertNull($standalone->parent_domain_id);
        $this->assertFalse($standalone->open_basedir_enabled);
    }

    public function test_it_relinks_existing_subdomains_when_parent_domain_is_added_later(): void
    {
        $user = User::factory()->create(['username' => 'testuser']);
        $service = app(DomainService::class);

        // Add v1.api.example.com first (standalone)
        $v1 = $service->createDomain($user, ['domain' => 'v1.api.example.com', 'ssl_enabled' => false])['domain'];
        $this->assertNull($v1->parent_domain_id);

        // Add example.com later -> v1.api.example.com should link under example.com
        $root = $service->createDomain($user, ['domain' => 'example.com', 'ssl_enabled' => false])['domain'];
        $v1->refresh();
        $this->assertEquals('subdomain', $v1->type);
        $this->assertEquals($root->id, $v1->parent_domain_id);

        // Add api.example.com even later -> api links under example.com, and v1 re-links under api.example.com
        $api = $service->createDomain($user, ['domain' => 'api.example.com', 'ssl_enabled' => false])['domain'];
        $v1->refresh();
        $this->assertEquals($root->id, $api->parent_domain_id);
        $this->assertEquals($api->id, $v1->parent_domain_id);
    }

    public function test_deleting_parent_domain_promotes_children_cleanly(): void
    {
        $user = User::factory()->create(['username' => 'testuser']);
        $service = app(DomainService::class);

        $root = $service->createDomain($user, ['domain' => 'example.com', 'ssl_enabled' => false])['domain'];
        $api = $service->createDomain($user, ['domain' => 'api.example.com', 'ssl_enabled' => false])['domain'];
        $v1 = $service->createDomain($user, ['domain' => 'v1.api.example.com', 'ssl_enabled' => false])['domain'];

        $vhostMock = Mockery::mock(VhostService::class);
        $vhostMock->shouldReceive('remove')->andReturnNull();
        $deleteAction = new DeleteDomainAction($vhostMock);

        // Delete middle parent (api.example.com) -> v1.api.example.com promotes to example.com
        $deleteAction->handle($api, $user, false);
        $v1->refresh();
        $this->assertEquals('subdomain', $v1->type);
        $this->assertEquals($root->id, $v1->parent_domain_id);

        // Delete root parent (example.com) -> v1.api.example.com promotes to standalone primary
        $deleteAction->handle($root, $user, false);
        $v1->refresh();
        $this->assertEquals('primary', $v1->type);
        $this->assertNull($v1->parent_domain_id);
    }

    public function test_open_basedir_can_be_toggled_on_and_off(): void
    {
        $user = User::factory()->create(['username' => 'testuser']);
        $domain = Domain::create([
            'user_id' => $user->id,
            'domain' => 'example.com',
            'type' => 'primary',
            'document_root' => "/home/{$user->username}/example.com/public_html",
            'php_version' => '83',
            'ssl_enabled' => false,
            'open_basedir_enabled' => false,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post("/domains/{$domain->id}/open-basedir", ['enabled' => true])
            ->assertRedirect();

        $this->assertTrue($domain->fresh()->open_basedir_enabled);

        $this->actingAs($user)
            ->post("/domains/{$domain->id}/open-basedir", ['enabled' => false])
            ->assertRedirect();

        $this->assertFalse($domain->fresh()->open_basedir_enabled);
    }
}
