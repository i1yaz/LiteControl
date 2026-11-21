<?php

declare(strict_types=1);

namespace App\Http\Requests\Mail;

use App\Domains\Mail\Models\MailDomain;
use Illuminate\Foundation\Http\FormRequest;

class EnableMailDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MailDomain::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:255'],
            'admin_override' => ['sometimes', 'boolean'],
            'verify_dns_txt' => ['sometimes', 'boolean'],
            'default_quota_mb' => ['sometimes', 'integer', 'min:0'],
            'max_accounts' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
