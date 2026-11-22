<?php

declare(strict_types=1);

namespace App\Domains\Dns\ValueObjects;

use InvalidArgumentException;

final readonly class ZoneTemplateRecordData
{
    public const ALLOWED_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR'];

    public string $name;

    public string $type;

    public int $ttl;

    public ?int $priority;

    public string $content;

    public int $sortOrder;

    public function __construct(
        string $name,
        string $type,
        int $ttl,
        ?int $priority,
        string $content,
        int $sortOrder = 0,
    ) {
        $trimmedName = trim($name);
        if ($trimmedName === '') {
            throw new InvalidArgumentException('Template record name cannot be empty (use @ for zone apex).');
        }

        $upperType = strtoupper(trim($type));
        if (! in_array($upperType, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported template record type: {$type}");
        }

        if ($ttl < 60 || $ttl > 604800) {
            throw new InvalidArgumentException('Template record TTL must be between 60 and 604800 seconds.');
        }

        if ($priority !== null && ($priority < 0 || $priority > 65535)) {
            throw new InvalidArgumentException('Template record priority must be between 0 and 65535.');
        }

        $trimmedContent = trim($content);
        if ($trimmedContent === '') {
            throw new InvalidArgumentException("Template record content cannot be empty for {$trimmedName} ({$upperType}).");
        }

        if ($upperType === 'CNAME' && in_array(strtolower(rtrim($trimmedName, '.')), ['@', '{{domain}}'], true)) {
            throw new InvalidArgumentException('CNAME records are not allowed at the zone apex (@).');
        }

        $this->name = $trimmedName;
        $this->type = $upperType;
        $this->ttl = $ttl;
        $this->priority = $upperType === 'MX' ? ($priority ?? 10) : $priority;
        $this->content = $trimmedContent;
        $this->sortOrder = max(0, $sortOrder);
    }

    /**
     * @return array{name: string, type: string, ttl: int, priority: int|null, content: string, sort_order: int}
     */
    public function toDatabaseArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'ttl' => $this->ttl,
            'priority' => $this->priority,
            'content' => $this->content,
            'sort_order' => $this->sortOrder,
        ];
    }
}
