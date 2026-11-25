<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Enums;

enum DeploymentHookSlot: string
{
    case BeforeClone = 'before_clone';
    case AfterClone = 'after_clone';
    case AfterInstall = 'after_install';
    case AfterActivate = 'after_activate';
    case AfterPurge = 'after_purge';

    public static function default(): self
    {
        return self::AfterPurge;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $slot): string => $slot->value,
            self::cases(),
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::BeforeClone => 'Before Clone Release',
            self::AfterClone => 'After Clone Release',
            self::AfterInstall => 'After Install Dependencies',
            self::AfterActivate => 'After Activate Release',
            self::AfterPurge => 'After Purge Old Releases',
        };
    }
}
