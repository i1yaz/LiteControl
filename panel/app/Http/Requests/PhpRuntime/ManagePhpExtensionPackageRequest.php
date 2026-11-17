<?php

declare(strict_types=1);

namespace App\Http\Requests\PhpRuntime;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManagePhpExtensionPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'string', Rule::in(['74', '80', '81', '82', '83', '84', '85'])],
            'extension' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_-]+$/'],
        ];
    }
}
