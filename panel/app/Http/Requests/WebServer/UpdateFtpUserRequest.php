<?php

namespace App\Http\Requests\WebServer;

use App\Domains\WebServer\Models\FtpAccount;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFtpUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        /** @var FtpAccount|null $ftpUser */
        $ftpUser = $this->route('ftpUser');

        if (! $user || ! $ftpUser) {
            return false;
        }

        return $user->isAdmin() || $ftpUser->domain->user_id === $user->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
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
