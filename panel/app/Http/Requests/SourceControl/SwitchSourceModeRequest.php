<?php

declare(strict_types=1);

namespace App\Http\Requests\SourceControl;

use App\Domains\SourceControl\Enums\SourceMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SwitchSourceModeRequest extends FormRequest
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
            'target_mode' => ['required', Rule::in([SourceMode::Manual->value, SourceMode::Provider->value])],
            'confirm_clear_credentials' => ['sometimes', 'boolean'],
            'git_provider_id' => ['nullable', 'integer', 'exists:git_providers,id'],
            'remote_repo_id' => ['nullable', 'string', 'max:64'],
            'remote_repo_full_name' => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/'],
            'branch' => ['nullable', 'string', 'max:120'],
            'auto_deploy' => ['sometimes', 'boolean'],
        ];
    }
}
