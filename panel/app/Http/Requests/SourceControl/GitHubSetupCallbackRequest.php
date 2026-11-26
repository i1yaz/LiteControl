<?php

declare(strict_types=1);

namespace App\Http\Requests\SourceControl;

use Illuminate\Foundation\Http\FormRequest;

class GitHubSetupCallbackRequest extends FormRequest
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
            'installation_id' => ['required', 'integer', 'min:1'],
            'state' => ['nullable', 'string', 'max:255'],
            'setup_action' => ['nullable', 'string', 'max:64'],
        ];
    }
}
