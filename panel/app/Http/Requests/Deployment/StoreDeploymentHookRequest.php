<?php

declare(strict_types=1);

namespace App\Http\Requests\Deployment;

use App\Domains\Deployment\Enums\DeploymentHookSlot;
use App\Domains\Deployment\Services\DeploymentPipelineService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDeploymentHookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $pipelineService = app(DeploymentPipelineService::class);
        $merged = [];

        if (is_string($this->input('name'))) {
            $merged['name'] = trim($this->input('name'));
        }

        if (is_string($this->input('script'))) {
            $merged['script'] = $pipelineService->normalizeScript($this->input('script')) ?? '';
        }

        if ($this->has('slot') && is_string($this->input('slot'))) {
            $trimmedSlot = trim($this->input('slot'));
            $merged['slot'] = $trimmedSlot === '' ? null : $trimmedSlot;
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slot' => ['nullable', 'string', Rule::enum(DeploymentHookSlot::class)],
            'script' => ['required', 'string', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $slotValue = $this->input('slot');
                $slot = is_string($slotValue) && $slotValue !== ''
                    ? DeploymentHookSlot::tryFrom($slotValue)
                    : DeploymentHookSlot::default();

                $script = (string) $this->input('script', '');
                $pipelineService = app(DeploymentPipelineService::class);

                if ($slot === DeploymentHookSlot::BeforeClone && $pipelineService->referencesReleasePlaceholder($script)) {
                    $validator->errors()->add('script', DeploymentPipelineService::BEFORE_CLONE_RELEASE_ERROR);
                }
            },
        ];
    }
}
