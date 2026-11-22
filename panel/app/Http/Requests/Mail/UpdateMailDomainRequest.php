<?php

declare(strict_types=1);

namespace App\Http\Requests\Mail;

use App\Domains\Mail\Models\MailDomain;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMailDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        $mailDomain = $this->route('mailDomain');

        if (! $mailDomain instanceof MailDomain) {
            return false;
        }

        return $this->user()?->can('update', $mailDomain) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'default_quota_mb' => ['required', 'integer', 'min:0'],
            'max_accounts' => ['required', 'integer', 'min:0'],
            'outbound_hourly_limit' => ['required', 'integer', 'min:1'],
            'active' => ['required', 'boolean'],
        ];
    }
}
