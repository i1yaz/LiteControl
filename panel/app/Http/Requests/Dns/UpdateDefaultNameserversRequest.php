<?php

declare(strict_types=1);

namespace App\Http\Requests\Dns;

use App\Domains\Dns\ValueObjects\DefaultNameserverEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class UpdateDefaultNameserversRequest extends FormRequest
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
            'default_nameservers' => ['required', 'array', 'min:1', 'max:8'],
            'default_nameservers.*.hostname' => ['required', 'string', 'max:253'],
            'default_nameservers.*.ipv4' => ['nullable', 'string', 'max:45'],
            'default_nameservers.*.ipv6' => ['nullable', 'string', 'max:64'],
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

                $entries = $this->input('default_nameservers', []);
                if (! is_array($entries)) {
                    return;
                }

                $seenHostnames = [];
                foreach ($entries as $index => $raw) {
                    if (! is_array($raw)) {
                        continue;
                    }

                    try {
                        $entry = new DefaultNameserverEntry(
                            hostname: (string) ($raw['hostname'] ?? ''),
                            ipv4: (string) ($raw['ipv4'] ?? ''),
                            ipv6: (string) ($raw['ipv6'] ?? ''),
                        );

                        if (isset($seenHostnames[$entry->hostname])) {
                            $validator->errors()->add(
                                "default_nameservers.{$index}.hostname",
                                "Duplicate nameserver hostname: {$entry->hostname}"
                            );
                        }
                        $seenHostnames[$entry->hostname] = true;
                    } catch (InvalidArgumentException $e) {
                        $validator->errors()->add("default_nameservers.{$index}.hostname", $e->getMessage());
                    }
                }
            },
        ];
    }

    /**
     * @return list<DefaultNameserverEntry>
     */
    public function toValueObjects(): array
    {
        $rawEntries = (array) $this->validated('default_nameservers', []);
        $entries = [];

        foreach ($rawEntries as $raw) {
            $entries[] = new DefaultNameserverEntry(
                hostname: (string) ($raw['hostname'] ?? ''),
                ipv4: (string) ($raw['ipv4'] ?? ''),
                ipv6: (string) ($raw['ipv6'] ?? ''),
            );
        }

        return $entries;
    }
}
