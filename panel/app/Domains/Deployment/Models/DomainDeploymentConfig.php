<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Models;

use App\Domains\SourceControl\Enums\SourceMode;
use App\Domains\SourceControl\Models\GitProvider;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DomainDeploymentConfig extends Model
{
    protected $fillable = [
        'domain_id',
        'mode',
        'source_mode',
        'git_provider_id',
        'remote_repo_id',
        'remote_repo_full_name',
        'auto_deploy',
        'preset',
        'repo_provider',
        'repo_url',
        'branch',
        'fetch_driver',
        'deploy_key_public',
        'deploy_key_private_encrypted',
        'api_token_encrypted',
        'webhook_token',
        'webhook_secret_encrypted',
        'web_subpath',
        'php_version',
        'keep_releases',
        'shared_dirs',
        'shared_files',
        'health_check_enabled',
        'health_check_path',
        'auto_rollback',
    ];

    protected $hidden = [
        'deploy_key_private_encrypted',
        'api_token_encrypted',
        'webhook_secret_encrypted',
    ];

    protected $attributes = [
        'mode' => 'simple',
        'source_mode' => SourceMode::Manual->value,
        'auto_deploy' => false,
        'preset' => 'laravel',
        'branch' => 'main',
        'fetch_driver' => 'shallow_git',
        'web_subpath' => 'public',
        'php_version' => '83',
        'keep_releases' => 5,
        'shared_dirs' => '["storage"]',
        'shared_files' => '[".env"]',
        'health_check_enabled' => false,
        'health_check_path' => '/',
        'auto_rollback' => true,
    ];

    protected function casts(): array
    {
        return [
            'source_mode' => SourceMode::class,
            'git_provider_id' => 'integer',
            'auto_deploy' => 'boolean',
            'shared_dirs' => 'array',
            'shared_files' => 'array',
            'deploy_key_private_encrypted' => 'encrypted',
            'api_token_encrypted' => 'encrypted',
            'webhook_secret_encrypted' => 'encrypted',
            'keep_releases' => 'integer',
            'health_check_enabled' => 'boolean',
            'auto_rollback' => 'boolean',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function gitProvider(): BelongsTo
    {
        return $this->belongsTo(GitProvider::class);
    }

    public function hooks(): HasMany
    {
        return $this->hasMany(DomainDeploymentHook::class, 'config_id')->orderBy('sort_order');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(DomainDeployment::class, 'domain_id', 'domain_id')->latest();
    }
}
