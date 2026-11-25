<?php

declare(strict_types=1);

namespace App\Http\Requests\Deployment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInstallScriptRequest extends FormRequest
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
            'install_script' => ['nullable', 'string', 'max:65535'],
        ];
    }
}
