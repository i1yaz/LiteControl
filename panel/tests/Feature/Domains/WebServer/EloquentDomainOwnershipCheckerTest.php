<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Shared\Contracts\DomainOwnershipCheckerInterface;
use App\Domains\Shared\ValueObjects\DomainName;
use App\Domains\WebServer\Infrastructure\EloquentDomainOwnershipChecker;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('binds DomainOwnershipCheckerInterface to EloquentDomainOwnershipChecker', function () {
    expect(app(DomainOwnershipCheckerInterface::class))
        ->toBeInstanceOf(EloquentDomainOwnershipChecker::class);
});

it('returns true when the user owns the domain and false otherwise', function () {
    $owner = User::factory()->create(['username' => 'owner']);
    $otherUser = User::factory()->create(['username' => 'other']);

    Domain::create([
        'user_id' => $owner->id,
        'domain' => 'example.com',
        'type' => 'primary',
        'document_root' => '/home/owner/public_html',
        'php_version' => '8.3',
        'status' => 'active',
    ]);

    $checker = app(DomainOwnershipCheckerInterface::class);

    expect($checker->userOwnsDomain($owner->id, new DomainName('example.com')))->toBeTrue()
        ->and($checker->userOwnsDomain($owner->id, new DomainName('EXAMPLE.COM')))->toBeTrue()
        ->and($checker->userOwnsDomain($otherUser->id, new DomainName('example.com')))->toBeFalse()
        ->and($checker->userOwnsDomain($owner->id, new DomainName('unowned.com')))->toBeFalse();
});
