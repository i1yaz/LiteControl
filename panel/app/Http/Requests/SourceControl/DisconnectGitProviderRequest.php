<?php

declare(strict_types=1);

namespace App\Http\Requests\SourceControl;

use Illuminate\Foundation\Http\FormRequest;

class DisconnectGitProviderRequest extends FormRequest
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
            'switch_linked_to_manual' => ['sometimes', 'boolean'],
        ];
    }
}
