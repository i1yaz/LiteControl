<?php

declare(strict_types=1);

namespace App\Http\Requests\SourceControl;

use App\Domains\SourceControl\ValueObjects\GitHubOrgName;
use App\Domains\SourceControl\ValueObjects\SafeReturnPath;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class InitiateGitHubManifestRequest extends FormRequest
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
            'label' => ['nullable', 'string', 'max:120'],
            'org_name' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null || trim((string) $value) === '') {
                        return;
                    }

                    if (! GitHubOrgName::isValid(trim((string) $value))) {
                        $fail('The organization name may only contain letters, numbers, and hyphens (1–39 characters).');
                    }
                },
            ],
            'return_to' => [
                'nullable',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null || trim((string) $value) === '') {
                        return;
                    }

                    if (! SafeReturnPath::isValid(trim((string) $value))) {
                        $fail('The return path must be a relative same-origin path starting with a single slash.');
                    }
                },
            ],
        ];
    }
}
