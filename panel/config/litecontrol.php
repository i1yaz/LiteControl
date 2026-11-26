<?php

declare(strict_types=1);

return [
    'max_git_providers_per_user' => (int) env('LITECONTROL_MAX_GIT_PROVIDERS_PER_USER', 10),
    'panel_ssl_cert_path' => env('LITECONTROL_PANEL_SSL_CERT_PATH', '/etc/litecontrol/ssl/fullchain.pem'),
    'panel_ssl_ca_bundle_path' => env('LITECONTROL_PANEL_SSL_CA_BUNDLE_PATH'),
    'panel_ssl_expiry_warning_days' => (int) env('LITECONTROL_PANEL_SSL_EXPIRY_WARNING_DAYS', 14),
    'deployment_hook_timeout_seconds' => (int) env('LITECONTROL_DEPLOYMENT_HOOK_TIMEOUT_SECONDS', 300),
];
