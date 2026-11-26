<?php

declare(strict_types=1);

namespace App\Http\Requests\SourceControl;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BindDomainSourceControlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'git_provider_id' => ['required', 'integer', 'exists:git_providers,id'],
            'remote_repo_id' => ['required', 'string', 'max:64'],
            'remote_repo_full_name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/'],
            'branch' => ['required', 'string', 'max:120'],
            'auto_deploy' => ['sometimes', 'boolean'],
            'confirm_clear_credentials' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', Rule::in(['simple', 'atomic'])],
            'preset' => ['sometimes', Rule::in(['laravel', 'wordpress', 'nextjs', 'nodejs', 'static', 'custom'])],
            'fetch_driver' => ['sometimes', Rule::in(['shallow_git', 'provider_tarball'])],
            'web_subpath' => ['nullable', 'string', 'max:120'],
            'php_version' => ['nullable', 'string', 'max:16'],
            'keep_releases' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'shared_dirs' => ['nullable', 'array'],
            'shared_dirs.*' => ['string', 'max:255'],
            'shared_files' => ['nullable', 'array'],
            'shared_files.*' => ['string', 'max:255'],
            'health_check_enabled' => ['sometimes', 'boolean'],
            'health_check_path' => ['sometimes', 'string', 'max:255'],
            'auto_rollback' => ['sometimes', 'boolean'],
        ];
    }
}
