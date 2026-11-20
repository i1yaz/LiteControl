<?php

declare(strict_types=1);

namespace App\Http\Requests\Deployment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeploymentConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['simple', 'atomic'])],
            'preset' => ['required', Rule::in(['laravel', 'wordpress', 'nextjs', 'nodejs', 'static', 'custom'])],
            'repo_provider' => ['nullable', Rule::in(['github', 'gitlab', 'bitbucket', 'custom'])],
            'repo_url' => ['nullable', 'string', 'max:500'],
            'branch' => ['required', 'string', 'max:120'],
            'fetch_driver' => ['required', Rule::in(['shallow_git', 'provider_tarball'])],
            'web_subpath' => ['nullable', 'string', 'max:120'],
            'php_version' => ['nullable', 'string', 'max:16'],
            'keep_releases' => ['required', 'integer', 'min:1', 'max:20'],
            'shared_dirs' => ['nullable', 'array'],
            'shared_dirs.*' => ['string', 'max:255'],
            'shared_files' => ['nullable', 'array'],
            'shared_files.*' => ['string', 'max:255'],
            'health_check_enabled' => ['required', 'boolean'],
            'health_check_path' => ['required', 'string', 'max:255'],
            'auto_rollback' => ['required', 'boolean'],
            'api_token' => ['nullable', 'string', 'max:500'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
        ];
    }
}
