<?php

declare(strict_types=1);

namespace App\Http\Requests\Dns;

use App\Domains\Dns\ValueObjects\AutoprimaryEntry;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

class CreateAutoprimaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ip' => ['required', 'string', 'ip'],
            'nameserver' => ['required', 'string', 'max:253'],
            'account' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]*$/'],
        ];
    }

    /**
     * @throws InvalidArgumentException
     */
    public function toValueObject(): AutoprimaryEntry
    {
        return new AutoprimaryEntry(
            ip: (string) $this->validated('ip'),
            nameserver: (string) $this->validated('nameserver'),
            account: (string) ($this->validated('account') ?? ''),
        );
    }
}
