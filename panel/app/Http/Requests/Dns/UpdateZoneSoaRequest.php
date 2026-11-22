<?php

declare(strict_types=1);

namespace App\Http\Requests\Dns;

use App\Domains\Dns\ValueObjects\RecordValidator;
use App\Domains\Dns\ValueObjects\UpdateZoneSoaPayload;
use Illuminate\Foundation\Http\FormRequest;

class UpdateZoneSoaRequest extends FormRequest
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
            'primary_ns' => ['required', 'string', 'max:253'],
            'hostmaster' => ['required', 'string', 'max:253'],
            'refresh' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'retry' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'expire' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'minimum' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'ttl' => [
                'required',
                'integer',
                'min:'.RecordValidator::MIN_TTL,
                'max:'.RecordValidator::MAX_TTL,
            ],
            'manual_serial_override' => ['sometimes', 'boolean'],
            'serial' => ['exclude_unless:manual_serial_override,true', 'nullable', 'integer', 'min:0', 'max:4294967295'],
            'comment' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function toValueObject(string $zoneId): UpdateZoneSoaPayload
    {
        $manualOverride = (bool) $this->boolean('manual_serial_override', false);
        $rawSerial = $this->validated('serial');

        return new UpdateZoneSoaPayload(
            zoneId: $zoneId,
            primaryNs: (string) $this->validated('primary_ns'),
            hostmaster: (string) $this->validated('hostmaster'),
            refresh: (int) $this->validated('refresh'),
            retry: (int) $this->validated('retry'),
            expire: (int) $this->validated('expire'),
            minimum: (int) $this->validated('minimum'),
            ttl: (int) $this->validated('ttl'),
            serial: $manualOverride && $rawSerial !== null && $rawSerial !== '' ? (int) $rawSerial : null,
            manualSerialOverride: $manualOverride,
            comment: $this->validated('comment') !== null ? (string) $this->validated('comment') : null,
        );
    }
}
