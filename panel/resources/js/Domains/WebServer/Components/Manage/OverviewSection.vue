<script setup lang="ts">
import { computed } from 'vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import {
    HardDrive,
    Database,
    ShieldCheck,
    ShieldAlert,
    Clock,
    Lock
} from '@lucide/vue';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
    databasesCount: {
        type: Number,
        default: 0,
    },
    totalSizeHuman: {
        type: String,
        default: '0 B',
    },
});

const sslStatus = computed(() => {
    if (!props.domain.ssl_enabled) {
        return {
            status: 'insecure',
            label: 'No active SSL certificate',
            badge: 'Insecure',
            detail: 'Traffic is unencrypted (Plaintext HTTP)',
        };
    }

    const expiryStr = props.domain.ssl_expires_at;
    let daysRemaining = null;
    let formattedDate = 'N/A';

    if (expiryStr) {
        const expDate = new Date(expiryStr);
        formattedDate = expDate.toLocaleDateString();
        const diffMs = expDate.getTime() - new Date().getTime();
        daysRemaining = Math.max(0, Math.ceil(diffMs / (1000 * 60 * 60 * 24)));
    }

    return {
        status: 'secure',
        label: `${props.domain.domain} has SSL from Let's Encrypt / Custom.`,
        badge: 'Secure',
        detail: daysRemaining !== null ? `Valid for ${daysRemaining} days (Expires ${formattedDate})` : 'Valid Certificate',
    };
});
</script>

<template>
    <div class="space-y-6">
        <!-- Metric Cards Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <!-- 1. Total Website Size -->
            <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-5 shadow-xs space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-theme-text-secondary theme-ui-control">
                        WEBSITE STORAGE
                    </span>
                    <div class="w-8 h-8 rounded-theme-md bg-theme-accent-subtle text-theme-accent-text flex items-center justify-center border border-theme-accent-border">
                        <HardDrive class="w-4 h-4" />
                    </div>
                </div>

                <div class="text-3xl font-extrabold text-theme-text-primary tracking-tight">
                    {{ totalSizeHuman }}
                </div>

                <p class="text-xs text-theme-text-secondary">
                    Total disk footprint inside <code class="text-theme-text-primary font-mono text-[11px] px-1 py-0.5 rounded-theme-sm bg-theme-bg-surface border border-theme-border">/home/{{ domain.user?.username }}/{{ domain.domain }}</code>
                </p>
            </div>

            <!-- 2. Associated Database Count -->
            <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-5 shadow-xs space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-theme-text-secondary theme-ui-control">
                        ASSOCIATED DATABASES
                    </span>
                    <div class="w-8 h-8 rounded-theme-md bg-theme-info-bg text-theme-info-text flex items-center justify-center border border-theme-info-border">
                        <Database class="w-4 h-4" />
                    </div>
                </div>

                <div class="flex items-baseline gap-2">
                    <span class="text-3xl font-extrabold text-theme-text-primary tracking-tight">
                        {{ databasesCount }}
                    </span>
                    <span v-if="domain.user?.package?.max_databases" class="text-xs text-theme-text-muted">
                        of {{ domain.user.package.max_databases }} allowed in package
                    </span>
                </div>

                <p class="text-xs text-theme-text-secondary">
                    Databases mapped explicitly to <span class="text-theme-text-primary font-medium">{{ domain.domain }}</span> and its subdomains.
                </p>
            </div>
        </div>

        <!-- 3. SSL Certificate Summary Banner -->
        <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-5 shadow-xs space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-theme-text-secondary theme-ui-control">
                    SSL CERTIFICATE
                </span>
                <div
                    :class="[
                        'w-8 h-8 rounded-theme-md flex items-center justify-center border',
                        sslStatus.status === 'secure'
                            ? 'bg-theme-success-bg text-theme-success-text border-theme-success-border'
                            : 'bg-theme-danger-bg text-theme-danger-text border-theme-danger-border'
                    ]"
                >
                    <Lock class="w-4 h-4" />
                </div>
            </div>

            <div class="flex items-center gap-2.5">
                <ShieldCheck v-if="sslStatus.status === 'secure'" class="w-5 h-5 text-theme-success-text shrink-0" />
                <ShieldAlert v-else class="w-5 h-5 text-theme-danger-text shrink-0" />
                <h3 class="text-base font-bold text-theme-text-primary">
                    {{ sslStatus.label }}
                </h3>
            </div>

            <div class="flex items-center gap-2 pt-1">
                <UiBadge :variant="sslStatus.status === 'secure' ? 'success' : 'danger'">
                    <span class="w-1.5 h-1.5 rounded-full" :class="sslStatus.status === 'secure' ? 'bg-theme-success' : 'bg-theme-danger'"></span>
                    {{ sslStatus.badge }}
                </UiBadge>
                <span class="text-xs text-theme-text-secondary">
                    • {{ sslStatus.detail }}
                </span>
            </div>
        </div>
    </div>
</template>

