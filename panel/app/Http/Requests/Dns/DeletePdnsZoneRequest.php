<?php

declare(strict_types=1);

namespace App\Http\Requests\Dns;

use App\Domains\Dns\ValueObjects\ZoneNameNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class DeletePdnsZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm_name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $routeZone = (string) $this->route('zone', '');
                $confirmName = trim((string) $this->input('confirm_name', ''));

                try {
                    $normalizer = app(ZoneNameNormalizer::class);
                    $canonical = $normalizer->ensureTrailingDot($routeZone);
                    $displayUtf8 = $normalizer->toDisplayUtf8($canonical);

                    $expectedAscii = strtolower(rtrim($canonical, '.'));
                    $expectedUtf8 = strtolower(rtrim($displayUtf8, '.'));
                    $actual = strtolower(rtrim($confirmName, '.'));

                    if ($actual !== $expectedAscii && $actual !== $expectedUtf8) {
                        $validator->errors()->add(
                            'confirm_name',
                            sprintf('Please type "%s" to confirm deleting this zone.', rtrim($canonical, '.'))
                        );
                    }
                } catch (InvalidArgumentException $e) {
                    $validator->errors()->add('confirm_name', $e->getMessage());
                }
            },
        ];
    }
}
