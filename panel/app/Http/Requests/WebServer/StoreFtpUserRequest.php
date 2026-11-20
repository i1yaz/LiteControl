<?php

namespace App\Http\Requests\WebServer;

use App\Domains\WebServer\Models\Domain;
use Illuminate\Foundation\Http\FormRequest;

class StoreFtpUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $domainId = $this->input('domain_id');
        if (! $domainId) {
            return true;
        }

        $domain = Domain::find($domainId);

        // If domain does not exist, let validation handle exists:domains,id.
        if (! $domain) {
            return true;
        }

        return $domain->user_id === $user->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'integer', 'exists:domains,id'],
            'username' => ['required', 'string', 'regex:/^[a-zA-Z0-9_]{1,32}$/'],
            'password' => ['required', 'string', 'min:8'],
            'home_directory' => ['nullable', 'string', 'max:255', 'not_regex:/\.\./'],
            'disk_quota' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'home_directory.not_regex' => 'Home directory cannot contain parent directory traversal (..).',
        ];
    }
}
