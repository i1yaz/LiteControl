<?php

declare(strict_types=1);

namespace App\Http\Requests\Mail;

use App\Domains\Mail\Models\MailDomain;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDomainSpamPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $mailDomain = $this->route('mailDomain');

        return $mailDomain instanceof MailDomain
            && $this->user()?->can('update', $mailDomain) === true;
    }

    public function rules(): array
    {
        return [
            'spam_filter_enabled' => ['required', 'boolean'],
            'spam_header_score' => ['required', 'numeric', 'min:0.1', 'max:99.9'],
            'spam_reject_score' => ['required', 'numeric', 'gt:spam_header_score', 'max:100'],
        ];
    }
}
