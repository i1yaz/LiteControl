<?php

declare(strict_types=1);

namespace App\Http\Requests\Deployment;

use App\Domains\Deployment\Enums\DeploymentHookSlot;
use App\Domains\Deployment\Models\DomainDeploymentHook;
use App\Domains\Deployment\Services\DeploymentPipelineService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateDeploymentHookRequest extends FormRequest
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
            $merged['slot'] = trim($this->input('slot'));
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
            'slot' => ['sometimes', 'required', 'string', Rule::enum(DeploymentHookSlot::class)],
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

                $routeHook = $this->route('hook');
                $existingSlot = $routeHook instanceof DomainDeploymentHook && $routeHook->slot instanceof DeploymentHookSlot
                    ? $routeHook->slot
                    : DeploymentHookSlot::default();

                $slot = $this->has('slot')
                    ? DeploymentHookSlot::tryFrom((string) $this->input('slot'))
                    : $existingSlot;

                $script = (string) $this->input('script', '');
                $pipelineService = app(DeploymentPipelineService::class);

                if ($slot === DeploymentHookSlot::BeforeClone && $pipelineService->referencesReleasePlaceholder($script)) {
                    $validator->errors()->add('script', DeploymentPipelineService::BEFORE_CLONE_RELEASE_ERROR);
                }
            },
        ];
    }
}
