<?php

declare(strict_types=1);

namespace App\Http\Requests\Mail;

use App\Domains\Mail\Models\MailAccount;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMailAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var MailAccount|null $account */
        $account = $this->route('mailAccount');

        return $account !== null && ($this->user()?->can('update', $account) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'quota_mb' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            'allow_imap' => ['sometimes', 'boolean'],
            'allow_pop3' => ['sometimes', 'boolean'],
            'allow_smtp' => ['sometimes', 'boolean'],
        ];
    }
}
