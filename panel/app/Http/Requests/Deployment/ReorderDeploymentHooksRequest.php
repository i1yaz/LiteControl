<?php

declare(strict_types=1);

namespace App\Http\Requests\Deployment;

use App\Domains\Deployment\Enums\DeploymentHookSlot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReorderDeploymentHooksRequest extends FormRequest
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
            'hooks' => ['present', 'array'],
            'hooks.*.id' => ['required', 'integer'],
            'hooks.*.slot' => ['required', 'string', Rule::enum(DeploymentHookSlot::class)],
            'hooks.*.sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
