<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Enums;

enum SourceMode: string
{
    case Manual = 'manual';
    case Provider = 'provider';
}
