<?php

declare(strict_types=1);

namespace App\Domains\Dns\ValueObjects;

use InvalidArgumentException;

final readonly class UpdateZoneSoaPayload
{
    public string $zoneId;

    public string $primaryNs;

    public string $hostmaster;

    public int $refresh;

    public int $retry;

    public int $expire;

    public int $minimum;

    public int $ttl;

    public ?int $serial;

    public bool $manualSerialOverride;

    public ?string $comment;

    public function __construct(
        string $zoneId,
        string $primaryNs,
        string $hostmaster,
        int $refresh = 10800,
        int $retry = 3600,
        int $expire = 604800,
        int $minimum = 3600,
        int $ttl = 3600,
        ?int $serial = null,
        bool $manualSerialOverride = false,
        ?string $comment = null,
    ) {
        $normalizer = new ZoneNameNormalizer;
        $validator = new RecordValidator;

        $this->zoneId = $normalizer->ensureTrailingDot($zoneId);
        $this->primaryNs = trim($primaryNs);
        $this->hostmaster = trim($hostmaster);

        if ($this->primaryNs === '') {
            throw new InvalidArgumentException('SOA primary nameserver cannot be empty.');
        }

        if ($this->hostmaster === '') {
            throw new InvalidArgumentException('SOA hostmaster email/RNAME cannot be empty.');
        }

        $this->ttl = $validator->validateTtl($ttl);

        foreach ([
            'refresh' => $refresh,
            'retry' => $retry,
            'expire' => $expire,
            'minimum' => $minimum,
        ] as $field => $value) {
            if ($value < 1 || $value > 2147483647) {
                throw new InvalidArgumentException("SOA {$field} must be between 1 and 2147483647 seconds.");
            }
        }

        $this->refresh = $refresh;
        $this->retry = $retry;
        $this->expire = $expire;
        $this->minimum = $minimum;

        if ($serial !== null && ($serial < 0 || $serial > 4294967295)) {
            throw new InvalidArgumentException('SOA serial must be an unsigned 32-bit integer (0 to 4294967295).');
        }

        $this->manualSerialOverride = $manualSerialOverride;
        $this->serial = $manualSerialOverride ? $serial : null;

        $trimmedComment = $comment !== null ? trim($comment) : '';
        $this->comment = $trimmedComment !== '' ? $trimmedComment : null;
    }
}
