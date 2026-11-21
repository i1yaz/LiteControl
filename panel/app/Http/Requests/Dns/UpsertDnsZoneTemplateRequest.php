<?php

declare(strict_types=1);

namespace App\Http\Requests\Dns;

use App\Domains\Dns\Models\DnsZoneTemplate;
use App\Domains\Dns\ValueObjects\UpsertZoneTemplatePayload;
use App\Domains\Dns\ValueObjects\ZoneTemplateRecordData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class UpsertDnsZoneTemplateRequest extends FormRequest
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
        /** @var DnsZoneTemplate|null $routeTemplate */
        $routeTemplate = $this->route('template');

        return [
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('dns_zone_templates', 'name')->ignore($routeTemplate?->id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'is_default' => ['nullable', 'boolean'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.name' => ['required', 'string', 'max:255'],
            'records.*.type' => ['required', 'string', Rule::in(ZoneTemplateRecordData::ALLOWED_TYPES)],
            'records.*.ttl' => ['required', 'integer', 'min:60', 'max:604800'],
            'records.*.priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'records.*.content' => ['required', 'string', 'max:2000'],
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

                $records = $this->input('records', []);
                if (! is_array($records)) {
                    return;
                }

                foreach ($records as $index => $raw) {
                    if (! is_array($raw)) {
                        continue;
                    }

                    try {
                        new ZoneTemplateRecordData(
                            name: (string) ($raw['name'] ?? ''),
                            type: (string) ($raw['type'] ?? 'A'),
                            ttl: (int) ($raw['ttl'] ?? 3600),
                            priority: isset($raw['priority']) && $raw['priority'] !== '' ? (int) $raw['priority'] : null,
                            content: (string) ($raw['content'] ?? ''),
                            sortOrder: (int) $index,
                        );
                    } catch (InvalidArgumentException $e) {
                        $validator->errors()->add("records.{$index}.content", $e->getMessage());
                    }
                }
            },
        ];
    }

    public function toValueObject(): UpsertZoneTemplatePayload
    {
        $rawRecords = (array) $this->validated('records', []);
        $recordObjects = [];

        foreach (array_values($rawRecords) as $index => $raw) {
            $recordObjects[] = new ZoneTemplateRecordData(
                name: (string) ($raw['name'] ?? '@'),
                type: (string) ($raw['type'] ?? 'A'),
                ttl: (int) ($raw['ttl'] ?? 3600),
                priority: isset($raw['priority']) && $raw['priority'] !== '' ? (int) $raw['priority'] : null,
                content: (string) ($raw['content'] ?? ''),
                sortOrder: $index,
            );
        }

        return new UpsertZoneTemplatePayload(
            name: (string) $this->validated('name'),
            description: (string) ($this->validated('description') ?? ''),
            isDefault: (bool) $this->boolean('is_default'),
            records: $recordObjects,
        );
    }
}
