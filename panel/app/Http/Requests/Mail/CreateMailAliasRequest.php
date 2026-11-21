<?php

declare(strict_types=1);

namespace App\Http\Requests\Mail;

use App\Domains\Mail\Models\MailAlias;
use Illuminate\Foundation\Http\FormRequest;

class CreateMailAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MailAlias::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mail_domain_id' => ['required', 'integer', 'exists:mail_domains,id'],
            'source_email' => ['required', 'string', 'max:320'],
            'destination_email' => ['required', 'string', 'max:320'],
        ];
    }
}
