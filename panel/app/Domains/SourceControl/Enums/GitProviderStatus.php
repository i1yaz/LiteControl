<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Enums;

enum GitProviderStatus: string
{
    case PendingInstallation = 'pending_installation';
    case Active = 'active';
    case Suspended = 'suspended';
    case ReauthRequired = 'reauth_required';
    case SourceUnavailable = 'source_unavailable';
    case DecryptionFailed = 'decryption_failed';
}
