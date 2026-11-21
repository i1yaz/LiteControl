<?php

declare(strict_types=1);

namespace App\Http\Requests\Mail;

use App\Domains\Mail\Models\MailAccount;
use Illuminate\Foundation\Http\FormRequest;

class CreateMailAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MailAccount::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mail_domain_id' => ['required', 'integer', 'exists:mail_domains,id'],
            'local_part' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'quota_mb' => ['required', 'integer', 'min:0'],
            'allow_imap' => ['sometimes', 'boolean'],
            'allow_pop3' => ['sometimes', 'boolean'],
            'allow_smtp' => ['sometimes', 'boolean'],
        ];
    }
}
