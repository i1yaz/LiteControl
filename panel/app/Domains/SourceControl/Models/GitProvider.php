<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Models;

use App\Domains\Identity\Models\User;
use App\Domains\SourceControl\Enums\GitProviderStatus;
use App\Domains\SourceControl\ValueObjects\ProviderCredentials;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class GitProvider extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'provider',
        'label',
        'base_url',
        'external_app_id',
        'external_app_slug',
        'external_account_login',
        'external_account_type',
        'installation_id',
        'repository_selection',
        'credentials',
        'webhook_secret',
        'configured_webhook_url',
        'status',
        'webhook_verified_at',
        'last_webhook_at',
        'suspended_at',
        'last_verified_at',
    ];

    protected $hidden = [
        'credentials',
        'webhook_secret',
    ];

    protected $attributes = [
        'provider' => 'github_app',
        'base_url' => 'https://api.github.com',
        'status' => GitProviderStatus::PendingInstallation->value,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $provider): void {
            if (! is_string($provider->uuid) || $provider->uuid === '') {
                $provider->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
            'credentials' => 'encrypted:array',
            'webhook_secret' => 'encrypted',
            'status' => GitProviderStatus::class,
            'webhook_verified_at' => 'immutable_datetime',
            'last_webhook_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'last_verified_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function safeCredentials(): ?ProviderCredentials
    {
        try {
            $raw = $this->credentials;
            if (! is_array($raw)) {
                throw new DecryptException('Decrypted git provider credentials payload is invalid.');
            }

            return ProviderCredentials::fromArray($raw);
        } catch (DecryptException|InvalidArgumentException $e) {
            $this->markDecryptionFailed('credentials', $e);

            return null;
        }
    }

    public function safeWebhookSecret(): ?string
    {
        try {
            $secret = $this->webhook_secret;
            if (! is_string($secret) || $secret === '') {
                throw new DecryptException('Decrypted git provider webhook secret is empty.');
            }

            return $secret;
        } catch (DecryptException $e) {
            $this->markDecryptionFailed('webhook_secret', $e);

            return null;
        }
    }

    private function markDecryptionFailed(string $attribute, Throwable $e): void
    {
        Log::error('Failed to decrypt GitProvider secret attribute.', [
            'git_provider_id' => $this->id,
            'git_provider_uuid' => $this->uuid,
            'user_id' => $this->user_id,
            'attribute' => $attribute,
            'exception' => $e->getMessage(),
        ]);

        if ($this->exists && $this->status !== GitProviderStatus::DecryptionFailed) {
            $this->status = GitProviderStatus::DecryptionFailed;
            static::withoutEvents(function (): void {
                static::query()
                    ->whereKey($this->getKey())
                    ->update(['status' => GitProviderStatus::DecryptionFailed->value]);
            });
        }
    }
}
