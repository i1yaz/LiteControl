<?php

declare(strict_types=1);

namespace App\Http\Requests\Mail;

use App\Domains\Mail\Models\MailAlias;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMailAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        $mailAlias = $this->route('mailAlias');

        return $mailAlias instanceof MailAlias
            && ($this->user()?->can('update', $mailAlias) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
        ];
    }
}
