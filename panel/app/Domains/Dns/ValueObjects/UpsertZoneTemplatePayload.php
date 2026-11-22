<?php

declare(strict_types=1);

namespace App\Domains\Dns\ValueObjects;

use InvalidArgumentException;

final readonly class UpsertZoneTemplatePayload
{
    public string $name;

    public string $description;

    public bool $isDefault;

    /**
     * @var list<ZoneTemplateRecordData>
     */
    public array $records;

    /**
     * @param  list<ZoneTemplateRecordData>  $records
     */
    public function __construct(
        string $name,
        string $description = '',
        bool $isDefault = false,
        array $records = [],
    ) {
        $trimmedName = trim($name);
        if ($trimmedName === '') {
            throw new InvalidArgumentException('Zone template name cannot be empty.');
        }

        if ($records === []) {
            throw new InvalidArgumentException('A zone template must contain at least one DNS record.');
        }

        $this->name = $trimmedName;
        $this->description = trim($description);
        $this->isDefault = $isDefault;
        $this->records = array_values($records);
    }
}
