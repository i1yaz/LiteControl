<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Jobs\ReconcileMailServerJob;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Shared\Contracts\ModuleRegistryQueryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('renders Mail/Spam/Index with filter_active = false gating when mail_filter is inactive', function (): void {
    $tenant = User::factory()->create(['username' => 'spaminactive', 'role' => 'user']);

    MailDomain::create([
        'user_id' => $tenant->id,
        'domain' => 'inactive-filter.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'spam_filter_enabled' => true,
        'spam_header_score' => 5.0,
        'spam_reject_score' => 15.0,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
    ]);

    $mockRegistry = Mockery::mock(ModuleRegistryQueryInterface::class);
    $mockRegistry->shouldReceive('isActive')->with('mail_filter')->andReturn(false);
    $mockRegistry->shouldReceive('isActive')->with('clamav')->andReturn(false);
    $mockRegistry->shouldReceive('isActive')->with('mail_core')->andReturn(true);
    $mockRegistry->shouldReceive('isActive')->andReturn(false)->byDefault();
    $this->app->instance(ModuleRegistryQueryInterface::class, $mockRegistry);

    $this->actingAs($tenant)
        ->get('/email/spam')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Mail/Spam/Index')
            ->where('filter_active', false)
            ->where('clamav_active', false)
            ->where('can_manage_modules', false)
            ->has('domains', 1)
            ->where('selected_domain.domain', 'inactive-filter.org')
        );
});

it('scopes Mail/Spam/Index domains to the authenticated tenant and selects domain by query param', function (): void {
    $tenantA = User::factory()->create(['username' => 'spamtenanta', 'role' => 'user']);
    $tenantB = User::factory()->create(['username' => 'spamtenantb', 'role' => 'user']);

    MailDomain::create([
        'user_id' => $tenantA->id,
        'domain' => 'first-a.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'spam_filter_enabled' => true,
        'spam_header_score' => 5.0,
        'spam_reject_score' => 15.0,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
    ]);

    MailDomain::create([
        'user_id' => $tenantA->id,
        'domain' => 'second-a.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'spam_filter_enabled' => false,
        'spam_header_score' => 6.5,
        'spam_reject_score' => 18.0,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
    ]);

    MailDomain::create([
        'user_id' => $tenantB->id,
        'domain' => 'secret-b.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'spam_filter_enabled' => true,
        'spam_header_score' => 5.0,
        'spam_reject_score' => 15.0,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
    ]);

    $mockRegistry = Mockery::mock(ModuleRegistryQueryInterface::class);
    $mockRegistry->shouldReceive('isActive')->with('mail_filter')->andReturn(true);
    $mockRegistry->shouldReceive('isActive')->with('clamav')->andReturn(true);
    $mockRegistry->shouldReceive('isActive')->with('mail_core')->andReturn(true);
    $mockRegistry->shouldReceive('isActive')->andReturn(false)->byDefault();
    $this->app->instance(ModuleRegistryQueryInterface::class, $mockRegistry);

    $this->actingAs($tenantA)
        ->get('/email/spam?domain=second-a.org')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Mail/Spam/Index')
            ->where('filter_active', true)
            ->where('clamav_active', true)
            ->has('domains', 2)
            ->where('selected_domain.domain', 'second-a.org')
            ->where('selected_domain.spam_filter_enabled', false)
            ->where('selected_domain.spam_header_score', 6.5)
            ->where('selected_domain.spam_reject_score', 18)
        );
});

it('updates spam policy, validates score ordering, and enforces tenant authorization', function (): void {
    Queue::fake();

    $tenantA = User::factory()->create(['username' => 'spamowner', 'role' => 'user']);
    $tenantB = User::factory()->create(['username' => 'spamother', 'role' => 'user']);

    $domainA = MailDomain::create([
        'user_id' => $tenantA->id,
        'domain' => 'policy-a.org',
        'ownership_source' => 'webserver',
        'active' => true,
        'spam_filter_enabled' => true,
        'spam_header_score' => 5.0,
        'spam_reject_score' => 15.0,
        'default_quota_mb' => 1024,
        'max_accounts' => 25,
    ]);

    $mockRegistry = Mockery::mock(ModuleRegistryQueryInterface::class);
    $mockRegistry->shouldReceive('isActive')->with('mail_filter')->andReturn(true);
    $mockRegistry->shouldReceive('isActive')->with('mail_core')->andReturn(true);
    $this->app->instance(ModuleRegistryQueryInterface::class, $mockRegistry);

    // Tenant B cannot update Tenant A's spam policy
    $this->actingAs($tenantB)
        ->put("/email/domains/{$domainA->id}/spam-policy", [
            'spam_filter_enabled' => false,
            'spam_header_score' => 4.0,
            'spam_reject_score' => 12.0,
        ])
        ->assertForbidden();

    // Invalid score ordering: header >= reject
    $this->actingAs($tenantA)
        ->put("/email/domains/{$domainA->id}/spam-policy", [
            'spam_filter_enabled' => true,
            'spam_header_score' => 15.0,
            'spam_reject_score' => 15.0,
        ])
        ->assertSessionHasErrors('spam_reject_score');

    // Valid update
    $this->actingAs($tenantA)
        ->put("/email/domains/{$domainA->id}/spam-policy", [
            'spam_filter_enabled' => true,
            'spam_header_score' => 4.5,
            'spam_reject_score' => 12.5,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $domainA->refresh();
    expect($domainA->spam_filter_enabled)->toBeTrue();
    expect((float) $domainA->spam_header_score)->toBe(4.5);
    expect((float) $domainA->spam_reject_score)->toBe(12.5);

    Queue::assertPushed(ReconcileMailServerJob::class);
});
