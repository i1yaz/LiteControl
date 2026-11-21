<?php

declare(strict_types=1);

namespace App\Http\Requests\Dns;

use App\Domains\Dns\ValueObjects\RecordNormalizer;
use App\Domains\Dns\ValueObjects\RecordValidator;
use App\Domains\Dns\ValueObjects\RrsetPayloadBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateZoneRrsetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rrsets' => ['required', 'array', 'min:1', 'max:200'],
            'rrsets.*.name' => ['required', 'string', 'max:255'],
            'rrsets.*.type' => ['required', 'string', Rule::in(RecordNormalizer::SUPPORTED_TYPES)],
            'rrsets.*.changetype' => ['sometimes', 'string', Rule::in(RrsetPayloadBuilder::ALLOWED_CHANGETYPES)],
            'rrsets.*.ttl' => [
                'nullable',
                'integer',
                'min:'.RecordValidator::MIN_TTL,
                'max:'.RecordValidator::MAX_TTL,
            ],
            'rrsets.*.records' => ['nullable', 'array'],
            'rrsets.*.comments' => ['nullable', 'array'],
        ];
    }
}
