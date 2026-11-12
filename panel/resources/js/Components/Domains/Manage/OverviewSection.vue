<script setup>
import { computed } from 'vue';
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
        <!-- Metric Cards Grid matching Screenshot 2026-11-09 132331.png -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <!-- 1. Total Website Size -->
            <div class="bg-theme-bg-card border border-theme-border rounded-xl p-5 shadow-sm space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-theme-text-secondary">
                        WEBSITE STORAGE
                    </span>
                    <div class="w-8 h-8 rounded-lg bg-blue-500/10 text-blue-400 flex items-center justify-center border border-blue-500/20">
                        <HardDrive class="w-4 h-4" />
                    </div>
                </div>

                <div class="text-3xl font-extrabold text-theme-text-primary tracking-tight">
                    {{ totalSizeHuman }}
                </div>

                <p class="text-xs text-theme-text-secondary">
                    Total disk footprint inside <code class="text-theme-text-primary font-mono text-[11px] px-1 py-0.5 rounded bg-theme-bg-surface border border-theme-border">/home/{{ domain.user?.username }}/{{ domain.domain }}</code>
                </p>
            </div>

            <!-- 2. Associated Database Count -->
            <div class="bg-theme-bg-card border border-theme-border rounded-xl p-5 shadow-sm space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-theme-text-secondary">
                        ASSOCIATED DATABASES
                    </span>
                    <div class="w-8 h-8 rounded-lg bg-cyan-500/10 text-cyan-400 flex items-center justify-center border border-cyan-500/20">
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

        <!-- 3. SSL Certificate Summary Banner matching Screenshot -->
        <div class="bg-theme-bg-card border border-theme-border rounded-xl p-5 shadow-sm space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-theme-text-secondary">
                    SSL CERTIFICATE
                </span>
                <div
                    :class="[
                        'w-8 h-8 rounded-lg flex items-center justify-center border',
                        sslStatus.status === 'secure'
                            ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
                            : 'bg-rose-500/10 text-rose-400 border-rose-500/20'
                    ]"
                >
                    <Lock class="w-4 h-4" />
                </div>
            </div>

            <div class="flex items-center gap-2.5">
                <ShieldCheck v-if="sslStatus.status === 'secure'" class="w-5 h-5 text-emerald-400 shrink-0" />
                <ShieldAlert v-else class="w-5 h-5 text-rose-400 shrink-0" />
                <h3 class="text-base font-bold text-theme-text-primary">
                    {{ sslStatus.label }}
                </h3>
            </div>

            <div class="flex items-center gap-2 pt-1">
                <span
                    :class="[
                        'px-2 py-0.5 rounded text-[11px] font-semibold border flex items-center gap-1',
                        sslStatus.status === 'secure'
                            ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30'
                            : 'bg-rose-500/10 text-rose-400 border-rose-500/30'
                    ]"
                >
                    <span class="w-1.5 h-1.5 rounded-full" :class="sslStatus.status === 'secure' ? 'bg-emerald-400' : 'bg-rose-400'"></span>
                    {{ sslStatus.badge }}
                </span>
                <span class="text-xs text-theme-text-secondary">
                    • {{ sslStatus.detail }}
                </span>
            </div>
        </div>
    </div>
</template>

