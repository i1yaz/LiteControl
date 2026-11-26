export const PROVIDER_STATUS_META = {
    active: {
        label: 'Active',
        variant: 'success',
    },
    pending_installation: {
        label: 'Pending Installation',
        variant: 'warning',
    },
    suspended: {
        label: 'Suspended',
        variant: 'warning',
    },
    reauth_required: {
        label: 'Needs Re-auth',
        variant: 'danger',
    },
    source_unavailable: {
        label: 'Source Unavailable',
        variant: 'danger',
    },
    decryption_failed: {
        label: 'Decryption Failed',
        variant: 'danger',
    },
};

export const WEBHOOK_HEALTH_META = {
    verified: {
        label: 'Webhooks Verified',
        variant: 'success',
    },
    failing: {
        label: 'Webhook Failing',
        variant: 'danger',
    },
    none_yet: {
        label: 'No successful delivery yet',
        variant: 'warning',
    },
};

export function getProviderStatusMeta(status) {
    return PROVIDER_STATUS_META[status] ?? {
        label: status || 'Unknown',
        variant: 'neutral',
    };
}

export function getWebhookHealthMeta(status) {
    return WEBHOOK_HEALTH_META[status] ?? WEBHOOK_HEALTH_META.none_yet;
}
