<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Domains\Identity\Models\User;
use App\Domains\PhpRuntime\Queries\InstalledPhpVersionsQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePhpCliVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $targetUser */
        $targetUser = $this->route('user');

        return $targetUser !== null && $this->user()->can('update', $targetUser);
    }

    public function rules(InstalledPhpVersionsQuery $phpVersions): array
    {
        return [
            'version' => [
                'required',
                'string',
                'regex:/^[5-9]\.[0-9]$/',
                Rule::in($phpVersions->getInstalledVersionStrings()),
            ],
        ];
    }
}
