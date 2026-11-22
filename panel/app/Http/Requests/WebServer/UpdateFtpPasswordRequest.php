<?php

namespace App\Http\Requests\WebServer;

use App\Domains\WebServer\Models\FtpAccount;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFtpPasswordRequest extends FormRequest
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
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}
