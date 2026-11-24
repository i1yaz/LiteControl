<?php

declare(strict_types=1);

namespace App\Http\Requests\SourceControl;

use Illuminate\Foundation\Http\FormRequest;

class GitHubManifestCallbackRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'max:255'],
        ];
    }
}
