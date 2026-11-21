<?php

declare(strict_types=1);

namespace App\Http\Requests\Dns;

use App\Domains\Dns\ValueObjects\PdnsDaemonConfig;
use App\Domains\Dns\ValueObjects\UpdateZoneModePayload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateZoneModeRequest extends FormRequest
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
            'kind' => ['required', 'string', 'in:Native,Primary,Secondary'],
            'masters' => ['nullable'],
            'allow_axfr_from' => ['nullable'],
            'also_notify' => ['nullable'],
            'confirm_overwrite' => ['nullable', 'boolean'],
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

                $kind = (string) $this->input('kind', 'Native');

                if ($kind === 'Secondary') {
                    $masters = UpdateZoneModePayload::parseEntryList($this->input('masters'));
                    if ($masters === []) {
                        $validator->errors()->add(
                            'masters',
                            'At least one upstream primary (master) IP address is required for Secondary zones.'
                        );
                    } else {
                        foreach ($masters as $master) {
                            if (! PdnsDaemonConfig::isValidIpWithOptionalPort($master)) {
                                $validator->errors()->add(
                                    'masters',
                                    "Invalid upstream primary IP or IP:port entry: {$master}"
                                );
                            }
                        }
                    }
                }

                $allowAxfrFrom = UpdateZoneModePayload::parseEntryList($this->input('allow_axfr_from'));
                foreach ($allowAxfrFrom as $entry) {
                    if (! PdnsDaemonConfig::isValidIpOrCidr($entry)) {
                        $validator->errors()->add(
                            'allow_axfr_from',
                            "Invalid IP or CIDR '{$entry}' in ALLOW-AXFR-FROM."
                        );
                    }
                }

                $alsoNotify = UpdateZoneModePayload::parseEntryList($this->input('also_notify'));
                foreach ($alsoNotify as $entry) {
                    if (! PdnsDaemonConfig::isValidIpWithOptionalPort($entry)) {
                        $validator->errors()->add(
                            'also_notify',
                            "Invalid IP or IP:port '{$entry}' in ALSO-NOTIFY."
                        );
                    }
                }
            },
        ];
    }

    public function toValueObject(string $zoneId): UpdateZoneModePayload
    {
        return new UpdateZoneModePayload(
            zoneId: $zoneId,
            kind: (string) $this->input('kind', 'Native'),
            masters: $this->input('masters'),
            allowAxfrFrom: $this->input('allow_axfr_from'),
            alsoNotify: $this->input('also_notify'),
            confirmOverwrite: (bool) $this->boolean('confirm_overwrite', false),
        );
    }
}
