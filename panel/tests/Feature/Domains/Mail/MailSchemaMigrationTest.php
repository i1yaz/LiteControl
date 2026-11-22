<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('creates all mail tables with expected columns', function (): void {
    expect(Schema::hasTable('mail_domains'))->toBeTrue()
        ->and(Schema::hasColumns('mail_domains', [
            'id', 'user_id', 'domain', 'ownership_source', 'active',
            'default_quota_mb', 'max_accounts', 'dkim_enabled',
            'spam_filter_enabled', 'spam_header_score', 'spam_reject_score',
            'outbound_hourly_limit', 'tls_sni_synced_at', 'created_at', 'updated_at',
        ]))->toBeTrue();

    expect(Schema::hasTable('mail_accounts'))->toBeTrue()
        ->and(Schema::hasColumns('mail_accounts', [
            'id', 'mail_domain_id', 'local_part', 'email', 'password_hash',
            'quota_mb', 'used_bytes', 'active', 'allow_imap', 'allow_pop3',
            'allow_smtp', 'created_at', 'updated_at',
        ]))->toBeTrue();

    expect(Schema::hasTable('mail_aliases'))->toBeTrue()
        ->and(Schema::hasColumns('mail_aliases', [
            'id', 'mail_domain_id', 'source_email', 'destination_email',
            'is_catch_all', 'active', 'created_at', 'updated_at',
        ]))->toBeTrue();

    expect(Schema::hasTable('mail_dkim_keys'))->toBeTrue()
        ->and(Schema::hasColumns('mail_dkim_keys', [
            'id', 'mail_domain_id', 'selector', 'algorithm', 'public_key_txt',
            'private_key_path', 'key_sha256', 'status', 'active_slot',
            'dns_verified_at', 'created_at', 'rotated_at',
        ]))->toBeTrue();

    expect(Schema::hasTable('mail_pending_purges'))->toBeTrue()
        ->and(Schema::hasColumns('mail_pending_purges', [
            'id', 'entity_type', 'domain', 'local_part', 'email',
            'status', 'attempts', 'last_error', 'created_at', 'updated_at',
        ]))->toBeTrue();
});

it('enforces ON DELETE RESTRICT on mail tables', function (): void {
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA foreign_keys = ON');
    }

    $user = User::factory()->create(['username' => 'mailtestuser']);

    $domainId = DB::table('mail_domains')->insertGetId([
        'user_id' => $user->id,
        'domain' => 'example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('users')->where('id', $user->id)->delete())
        ->toThrow(QueryException::class);

    DB::table('mail_accounts')->insert([
        'mail_domain_id' => $domainId,
        'local_part' => 'alice',
        'email' => 'alice@example.com',
        'password_hash' => '{ARGON2ID}dummy',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('mail_domains')->where('id', $domainId)->delete())
        ->toThrow(QueryException::class);
});

it('computes is_catch_all generated column on mail_aliases', function (): void {
    $user = User::factory()->create(['username' => 'mailtestuser']);
    $domainId = DB::table('mail_domains')->insertGetId([
        'user_id' => $user->id,
        'domain' => 'example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('mail_aliases')->insert([
        [
            'mail_domain_id' => $domainId,
            'source_email' => '@example.com',
            'destination_email' => 'catchall@example.com',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'mail_domain_id' => $domainId,
            'source_email' => 'sales@example.com',
            'destination_email' => 'alice@example.com',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $catchAll = DB::table('mail_aliases')->where('source_email', '@example.com')->first();
    $regular = DB::table('mail_aliases')->where('source_email', 'sales@example.com')->first();

    expect((bool) $catchAll->is_catch_all)->toBeTrue()
        ->and((bool) $regular->is_catch_all)->toBeFalse();
});

it('enforces single active DKIM key per domain and algorithm via active_slot', function (): void {
    $user = User::factory()->create(['username' => 'mailtestuser']);
    $domainId = DB::table('mail_domains')->insertGetId([
        'user_id' => $user->id,
        'domain' => 'example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Multiple retired/pending keys for the same domain + algorithm are allowed
    DB::table('mail_dkim_keys')->insert([
        [
            'mail_domain_id' => $domainId,
            'selector' => 's1',
            'algorithm' => 'rsa2048',
            'status' => 'retired',
            'created_at' => now(),
        ],
        [
            'mail_domain_id' => $domainId,
            'selector' => 's2',
            'algorithm' => 'rsa2048',
            'status' => 'retired',
            'created_at' => now(),
        ],
        [
            'mail_domain_id' => $domainId,
            'selector' => 's3',
            'algorithm' => 'rsa2048',
            'status' => 'active',
            'created_at' => now(),
        ],
    ]);

    $activeKey = DB::table('mail_dkim_keys')->where('selector', 's3')->first();
    $retiredKey = DB::table('mail_dkim_keys')->where('selector', 's1')->first();

    expect($activeKey->active_slot)->toBe(1)
        ->and($retiredKey->active_slot)->toBeNull();

    // A second active key for the same domain + algorithm must violate unique constraint
    expect(fn () => DB::table('mail_dkim_keys')->insert([
        'mail_domain_id' => $domainId,
        'selector' => 's4',
        'algorithm' => 'rsa2048',
        'status' => 'active',
        'created_at' => now(),
    ]))->toThrow(QueryException::class);

    // An active key with a different algorithm is allowed
    DB::table('mail_dkim_keys')->insert([
        'mail_domain_id' => $domainId,
        'selector' => 'ed1',
        'algorithm' => 'ed25519',
        'status' => 'active',
        'created_at' => now(),
    ]);

    expect(DB::table('mail_dkim_keys')->where('selector', 'ed1')->value('active_slot'))->toBe(1);
});

it('queries all seven non-UNION read-only views accurately', function (): void {
    $user = User::factory()->create(['username' => 'mailtestuser']);

    $activeDomainId = DB::table('mail_domains')->insertGetId([
        'user_id' => $user->id,
        'domain' => 'active.test',
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $inactiveDomainId = DB::table('mail_domains')->insertGetId([
        'user_id' => $user->id,
        'domain' => 'inactive.test',
        'active' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('mail_accounts')->insert([
        [
            'mail_domain_id' => $activeDomainId,
            'local_part' => 'alice',
            'email' => 'alice@active.test',
            'password_hash' => '{ARGON2ID}hash1',
            'quota_mb' => 2048,
            'active' => true,
            'allow_imap' => true,
            'allow_pop3' => false,
            'allow_smtp' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'mail_domain_id' => $activeDomainId,
            'local_part' => 'nosmtp',
            'email' => 'nosmtp@active.test',
            'password_hash' => '{ARGON2ID}hash2',
            'quota_mb' => 512,
            'active' => true,
            'allow_imap' => true,
            'allow_pop3' => false,
            'allow_smtp' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'mail_domain_id' => $inactiveDomainId,
            'local_part' => 'bob',
            'email' => 'bob@inactive.test',
            'password_hash' => '{ARGON2ID}hash3',
            'quota_mb' => 1024,
            'active' => true,
            'allow_imap' => true,
            'allow_pop3' => true,
            'allow_smtp' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('mail_aliases')->insert([
        [
            'mail_domain_id' => $activeDomainId,
            'source_email' => 'info@active.test',
            'destination_email' => 'alice@active.test',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'mail_domain_id' => $activeDomainId,
            'source_email' => 'muted@active.test',
            'destination_email' => 'nosmtp@active.test',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    expect(DB::table('v_postfix_domains')->pluck('domain')->all())
        ->toBe(['active.test']);

    $mailboxes = DB::table('v_postfix_mailboxes')->orderBy('email')->get();
    expect($mailboxes)->toHaveCount(2)
        ->and($mailboxes[0]->email)->toBe('alice@active.test')
        ->and($mailboxes[0]->maildir)->toBe('active.test/alice/');

    expect(DB::table('v_postfix_aliases')->pluck('source_email')->all())
        ->toEqualCanonicalizing(['info@active.test', 'muted@active.test']);

    expect(DB::table('v_postfix_mailbox_self')->pluck('destination_email')->all())
        ->toEqualCanonicalizing(['alice@active.test', 'nosmtp@active.test']);

    expect(DB::table('v_postfix_sender_mailboxes')->pluck('sender_email')->all())
        ->toBe(['alice@active.test']);

    $senderAliases = DB::table('v_postfix_sender_aliases')->get();
    expect($senderAliases)->toHaveCount(1)
        ->and($senderAliases[0]->sender_email)->toBe('info@active.test')
        ->and($senderAliases[0]->sasl_login)->toBe('alice@active.test');

    $dovecotUsers = DB::table('v_dovecot_users')->where('email', 'alice@active.test')->first();
    expect($dovecotUsers)->not->toBeNull()
        ->and($dovecotUsers->local_part)->toBe('alice')
        ->and($dovecotUsers->domain)->toBe('active.test')
        ->and((int) $dovecotUsers->quota_mb)->toBe(2048);
});

it('rolls back and re-runs all Phase 1 mail migrations cleanly', function (): void {
    Artisan::call('migrate:rollback', ['--step' => 6]);

    expect(Schema::hasTable('mail_domains'))->toBeFalse()
        ->and(Schema::hasTable('mail_accounts'))->toBeFalse()
        ->and(Schema::hasTable('mail_aliases'))->toBeFalse()
        ->and(Schema::hasTable('mail_dkim_keys'))->toBeFalse()
        ->and(Schema::hasTable('mail_pending_purges'))->toBeFalse();

    Artisan::call('migrate');

    expect(Schema::hasTable('mail_domains'))->toBeTrue()
        ->and(Schema::hasTable('mail_accounts'))->toBeTrue()
        ->and(Schema::hasTable('mail_aliases'))->toBeTrue()
        ->and(Schema::hasTable('mail_dkim_keys'))->toBeTrue()
        ->and(Schema::hasTable('mail_pending_purges'))->toBeTrue();
});
