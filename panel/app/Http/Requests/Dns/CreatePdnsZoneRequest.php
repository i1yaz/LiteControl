<?php

declare(strict_types=1);

namespace App\Http\Requests\Dns;

use App\Domains\Dns\Models\DnsZoneTemplate;
use App\Domains\Dns\Models\PdnsSetting;
use App\Domains\Dns\ValueObjects\CreatePdnsZonePayload;
use App\Domains\Dns\ValueObjects\ZoneNameNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class CreatePdnsZoneRequest extends FormRequest
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
            'zone_type' => ['required', 'string', 'in:forward,reverse_ipv4,reverse_ipv6'],
            'name' => ['nullable', 'string', 'max:255'],
            'reverse_prefix' => ['nullable', 'string', 'max:255'],
            'kind' => ['required', 'string', 'in:Native,Primary,Secondary'],
            'nameservers' => ['nullable'],
            'masters' => ['nullable'],
            'template_id' => ['nullable', 'integer', 'exists:dns_zone_templates,id'],
            'template_ipv4' => ['nullable', 'string', 'max:45'],
            'template_ipv6' => ['nullable', 'string', 'max:64'],
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

                $normalizer = app(ZoneNameNormalizer::class);
                $zoneType = (string) $this->input('zone_type', 'forward');
                $kind = (string) $this->input('kind', 'Native');

                if ($zoneType === 'forward') {
                    $rawName = trim((string) $this->input('name', ''));
                    if ($rawName === '') {
                        $validator->errors()->add('name', 'A domain name is required for forward zones.');
                    } else {
                        try {
                            $normalizer->normalizeForwardZone($rawName);
                        } catch (InvalidArgumentException $e) {
                            $validator->errors()->add('name', $e->getMessage());
                        }
                    }
                } else {
                    $rawPrefix = trim((string) $this->input('reverse_prefix', ''));
                    if ($rawPrefix === '') {
                        $validator->errors()->add('reverse_prefix', 'A CIDR block or network prefix is required for reverse zones.');
                    } else {
                        try {
                            $normalizer->normalize($rawPrefix, $zoneType);
                        } catch (InvalidArgumentException $e) {
                            $validator->errors()->add('reverse_prefix', $e->getMessage());
                        }
                    }
                }

                if ($kind === 'Secondary') {
                    $masters = $this->parseMasters($this->input('masters'));
                    if ($masters === []) {
                        $validator->errors()->add('masters', 'At least one upstream primary (master) IP address is required for Secondary zones.');
                    } else {
                        foreach ($masters as $master) {
                            if (! $this->isValidMasterAddress($master)) {
                                $validator->errors()->add('masters', "Invalid upstream primary IP or IP:port entry: {$master}");
                            }
                        }
                    }
                } else {
                    $nameservers = $this->parseNameservers($this->input('nameservers'));
                    foreach ($nameservers as $ns) {
                        try {
                            $normalizer->normalizeFqdn($ns);
                        } catch (InvalidArgumentException $e) {
                            $validator->errors()->add('nameservers', "Invalid nameserver hostname '{$ns}': {$e->getMessage()}");
                        }
                    }

                    $rawTemplateId = $this->input('template_id');
                    if ($rawTemplateId !== null && $rawTemplateId !== '' && $zoneType === 'forward') {
                        $ipv4 = $this->resolveTemplateIpv4();
                        $ipv6 = trim((string) $this->input('template_ipv6', ''));

                        if ($ipv4 !== '' && filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                            $validator->errors()->add('template_ipv4', 'Please provide a valid IPv4 address for {{IPV4}} template substitution.');
                        }

                        if ($ipv6 !== '' && filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                            $validator->errors()->add('template_ipv6', 'Please provide a valid IPv6 address for {{IPV6}} template substitution.');
                        }

                        if ($ipv4 === '') {
                            $template = DnsZoneTemplate::query()->with('records')->find((int) $rawTemplateId);
                            $needsIpv4 = $template?->records->contains(
                                static fn ($r): bool => str_contains((string) $r->content, '{{IPV4}}') || str_contains((string) $r->name, '{{IPV4}}')
                            ) ?? false;

                            if ($needsIpv4) {
                                $validator->errors()->add('template_ipv4', 'A target IPv4 address ({{IPV4}}) is required for the selected zone template.');
                            }
                        }
                    }
                }
            },
        ];
    }

    public function toValueObject(): CreatePdnsZonePayload
    {
        $normalizer = app(ZoneNameNormalizer::class);
        $zoneType = (string) $this->input('zone_type', 'forward');
        $kind = (string) $this->input('kind', 'Native');

        $rawZoneInput = $zoneType === 'forward'
            ? (string) $this->input('name', '')
            : (string) $this->input('reverse_prefix', '');

        $canonicalName = $normalizer->normalize($rawZoneInput, $zoneType);

        if ($kind === 'Secondary') {
            return new CreatePdnsZonePayload(
                name: $canonicalName,
                kind: 'Secondary',
                zoneType: $zoneType,
                nameservers: [],
                masters: $this->parseMasters($this->input('masters')),
                soaEditApi: 'DEFAULT',
                apiRectify: true,
            );
        }

        $rawNameservers = $this->parseNameservers($this->input('nameservers'));
        $normalizedNameservers = [];

        foreach ($rawNameservers as $ns) {
            $normalizedNameservers[] = $normalizer->normalizeFqdn($ns);
        }

        if ($normalizedNameservers === []) {
            $normalizedNameservers = $this->resolveDefaultNameservers($canonicalName, $zoneType, $normalizer);
        }

        $rawTemplateId = $this->input('template_id');
        $templateId = ($zoneType === 'forward' && $rawTemplateId !== null && $rawTemplateId !== '')
            ? (int) $rawTemplateId
            : null;

        return new CreatePdnsZonePayload(
            name: $canonicalName,
            kind: $kind,
            zoneType: $zoneType,
            nameservers: array_values(array_unique($normalizedNameservers)),
            masters: [],
            soaEditApi: 'DEFAULT',
            apiRectify: true,
            templateId: $templateId,
            templateIpv4: $templateId !== null ? $this->resolveTemplateIpv4() : null,
            templateIpv6: $templateId !== null ? trim((string) $this->input('template_ipv6', '')) : null,
        );
    }

    private function resolveTemplateIpv4(): string
    {
        $explicit = trim((string) $this->input('template_ipv4', ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $setting = PdnsSetting::current();
        foreach ($setting->normalizedDefaultNameservers() as $ns) {
            if ($ns['ipv4'] !== '' && filter_var($ns['ipv4'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $ns['ipv4'];
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function parseNameservers(mixed $raw): array
    {
        return $this->splitListInput($raw);
    }

    /**
     * @return list<string>
     */
    private function parseMasters(mixed $raw): array
    {
        return $this->splitListInput($raw);
    }

    /**
     * @return list<string>
     */
    private function splitListInput(mixed $raw): array
    {
        if (is_array($raw)) {
            $items = $raw;
        } elseif (is_string($raw)) {
            $items = preg_split('/[\s,;]+/', $raw) ?: [];
        } else {
            return [];
        }

        $cleaned = [];
        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }
            $trimmed = trim($item);
            if ($trimmed !== '') {
                $cleaned[] = $trimmed;
            }
        }

        return $cleaned;
    }

    /**
     * @return list<string>
     */
    private function resolveDefaultNameservers(string $canonicalZoneName, string $zoneType, ZoneNameNormalizer $normalizer): array
    {
        $setting = PdnsSetting::current();
        $configured = $setting->default_nameservers ?? [];
        $resolved = [];

        if (is_array($configured)) {
            foreach ($configured as $entry) {
                $hostname = is_array($entry)
                    ? (string) ($entry['hostname'] ?? '')
                    : (is_string($entry) ? $entry : '');

                if (trim($hostname) !== '') {
                    try {
                        $resolved[] = $normalizer->normalizeFqdn($hostname);
                    } catch (InvalidArgumentException) {
                        // Ignore malformed stored entry
                    }
                }
            }
        }

        if ($resolved !== []) {
            return array_values(array_unique($resolved));
        }

        if ($zoneType === 'forward') {
            return [
                'ns1.'.$canonicalZoneName,
                'ns2.'.$canonicalZoneName,
            ];
        }

        return ['ns1.localhost.', 'ns2.localhost.'];
    }

    private function isValidMasterAddress(string $entry): bool
    {
        if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // Support [IPv6]:port
        if (preg_match('/^\[([^\]]+)\]:(\d+)$/', $entry, $matches) === 1) {
            $port = (int) $matches[2];

            return filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                && $port >= 1
                && $port <= 65535;
        }

        // Support IPv4:port
        if (substr_count($entry, ':') === 1) {
            [$ip, $portStr] = explode(':', $entry, 2);
            if (! ctype_digit($portStr)) {
                return false;
            }
            $port = (int) $portStr;

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                && $port >= 1
                && $port <= 65535;
        }

        return false;
    }
}
