<?php

declare(strict_types=1);

namespace App\Http\Requests\Mail;

use Illuminate\Foundation\Http\FormRequest;

class GenerateDkimKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'mail_domain_id' => ['required', 'integer', 'exists:mail_domains,id'],
            'selector' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9][a-z0-9-]{0,62}$/i'],
            'algorithm' => ['sometimes', 'string', 'in:rsa2048,ed25519'],
        ];
    }
}
