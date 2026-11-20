<?php

declare(strict_types=1);

namespace App\Http\Requests\Deployment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeploymentHookRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'stage' => ['required', Rule::in(['clone', 'install', 'build', 'activate', 'cleanup'])],
            'position' => ['required', Rule::in(['before', 'run', 'after'])],
            'script' => ['required', 'string', 'max:10000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
