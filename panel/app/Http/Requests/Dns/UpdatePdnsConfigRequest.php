<?php

declare(strict_types=1);

namespace App\Http\Requests\Dns;

use App\Domains\Dns\Models\PdnsSetting;
use App\Domains\Dns\ValueObjects\PdnsDaemonConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePdnsConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'ip'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'webserver_allow_from' => ['required', 'string', 'max:500'],
            'webserver_password' => ['nullable', 'string', 'max:255'],
            'primary_enabled' => ['required', 'boolean'],
            'secondary_enabled' => ['required', 'boolean'],
            'autosecondary_enabled' => ['required', 'boolean'],
            'allow_axfr_ips' => ['nullable', 'string', 'max:500'],
            'also_notify' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $host = trim((string) $this->input('host', '127.0.0.1'));
            $allowFromRaw = (string) $this->input('webserver_allow_from', '');
            $allowEntries = PdnsDaemonConfig::parseCsvEntries($allowFromRaw);

            if ($allowEntries === []) {
                $validator->errors()->add('webserver_allow_from', 'At least one allowed IP or CIDR is required.');

                return;
            }

            foreach ($allowEntries as $entry) {
                if (! PdnsDaemonConfig::isValidIpOrCidr($entry)) {
                    $validator->errors()->add('webserver_allow_from', "Invalid IP or CIDR '{$entry}' in webserver_allow_from.");
                }
            }

            $isLoopback = PdnsDaemonConfig::isLoopbackIp($host);
            $passwordInput = trim((string) ($this->input('webserver_password') ?? ''));
            $hasExistingPassword = PdnsSetting::current()->hasWebserverPassword();

            if ($passwordInput !== '' && (str_contains($passwordInput, "\r") || str_contains($passwordInput, "\n"))) {
                $validator->errors()->add('webserver_password', 'Webserver password cannot contain newline characters.');
            }

            if (! $isLoopback) {
                foreach ($allowEntries as $entry) {
                    if (in_array($entry, ['0.0.0.0/0', '::/0'], true)) {
                        $validator->errors()->add(
                            'webserver_allow_from',
                            "Unrestricted '{$entry}' is not allowed when webserver bind address is non-loopback ({$host})."
                        );
                    }
                }

                if ($passwordInput === '' && ! $hasExistingPassword) {
                    $validator->errors()->add(
                        'webserver_password',
                        'A webserver password is required when binding PowerDNS API to a non-loopback IP address.'
                    );
                }
            }

            $axfrRaw = (string) ($this->input('allow_axfr_ips') ?? '');
            foreach (PdnsDaemonConfig::parseCsvEntries($axfrRaw) as $entry) {
                if (! PdnsDaemonConfig::isValidIpOrCidr($entry)) {
                    $validator->errors()->add('allow_axfr_ips', "Invalid IP or CIDR '{$entry}' in allow_axfr_ips.");
                }
            }

            $notifyRaw = (string) ($this->input('also_notify') ?? '');
            foreach (PdnsDaemonConfig::parseCsvEntries($notifyRaw) as $entry) {
                if (! PdnsDaemonConfig::isValidIpWithOptionalPort($entry)) {
                    $validator->errors()->add('also_notify', "Invalid IP or IP:port '{$entry}' in also_notify.");
                }
            }
        });
    }

    public function toValueObject(): PdnsDaemonConfig
    {
        return PdnsDaemonConfig::fromArray(
            $this->validated(),
            PdnsSetting::current()->hasWebserverPassword(),
        );
    }
}
