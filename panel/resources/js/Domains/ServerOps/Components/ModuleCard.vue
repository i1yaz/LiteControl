<script setup lang="ts">
import { computed } from 'vue';
import { Download, ExternalLink, Trash2 } from '@lucide/vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiCard from '@shared/Ui/UiCard.vue';

const CLAMAV_MIN_RAM_MB = 4096;

const props = defineProps({
    module: {
        type: Object,
        required: true,
    },
    allModules: {
        type: Array,
        default: () => [],
    },
    serverRamMb: {
        type: Number,
        default: 0,
    },
    busy: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['install', 'uninstall']);

const moduleMap = computed(() => {
    const map = new Map();
    for (const item of props.allModules as any[]) {
        map.set(item.key || item.name, item);
    }
    return map;
});

const requiredDependencies = computed<string[]>(() => {
    const deps = new Set<string>(props.module.depends_on || []);
    if (props.module.parent_key) {
        deps.add(props.module.parent_key);
    }
    return Array.from(deps);
});

const unmetDependencies = computed<string[]>(() => {
    return requiredDependencies.value.filter((depKey) => {
        const dep = moduleMap.value.get(depKey);
        return !dep || !dep.is_active;
    });
});

const isClamavRamBlocked = computed(() => {
    return props.module.key === 'clamav' && !props.module.is_active && props.serverRamMb < CLAMAV_MIN_RAM_MB;
});

const isInFlight = computed(() => {
    return props.module.status === 'pending' || props.module.status === 'provisioning';
});

const installDisabled = computed(() => {
    return props.busy || isInFlight.value || unmetDependencies.value.length > 0 || isClamavRamBlocked.value;
});

const statusBadgeVariant = computed(() => {
    switch (props.module.status) {
        case 'active':
            return 'success';
        case 'pending':
        case 'provisioning':
            return 'warning';
        case 'failed':
            return 'danger';
        default:
            return 'neutral';
    }
});

const statusLabel = computed(() => {
    switch (props.module.status) {
        case 'active':
            return 'Active';
        case 'pending':
            return 'Pending';
        case 'provisioning':
            return 'Provisioning';
        case 'failed':
            return 'Failed';
        default:
            return 'Inactive';
    }
});

const statusDotClass = computed(() => {
    switch (props.module.status) {
        case 'active':
            return 'bg-theme-success';
        case 'pending':
        case 'provisioning':
            return 'bg-theme-warning animate-pulse';
        case 'failed':
            return 'bg-theme-danger';
        default:
            return 'bg-theme-text-muted';
    }
});
</script>

<template>
    <UiCard class="flex flex-col justify-between space-y-5 hover:border-theme-border-hover transition-all">
        <div class="space-y-3">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-bold text-theme-text-primary">{{ module.title || module.key }}</h2>
                    <div class="flex flex-wrap items-center gap-2 text-[11px] font-mono text-theme-accent-text mt-0.5">
                        <span>module: {{ module.key }}</span>
                        <span v-if="module.driver && module.driver !== module.key" class="text-theme-text-muted">
                            driver: {{ module.driver }}
                        </span>
                    </div>
                </div>
                <UiBadge :variant="statusBadgeVariant">
                    <span class="w-2 h-2 rounded-full" :class="statusDotClass"></span>
                    <span>{{ statusLabel }}</span>
                </UiBadge>
            </div>

            <p class="text-xs text-theme-text-secondary leading-relaxed">
                {{ module.description }}
            </p>

            <div
                v-if="requiredDependencies.length > 0"
                class="flex flex-wrap items-center gap-1.5 text-[11px] text-theme-text-muted"
            >
                <span>Requires:</span>
                <span
                    v-for="dep in requiredDependencies"
                    :key="dep"
                    :class="[
                        'px-1.5 py-0.5 rounded font-mono',
                        unmetDependencies.includes(dep)
                            ? 'bg-theme-warning-bg text-theme-warning-text border border-theme-warning-border'
                            : 'bg-theme-success-bg text-theme-success-text border border-theme-success-border',
                    ]"
                >
                    {{ dep }}
                </span>
            </div>

            <UiAlert v-if="isClamavRamBlocked" variant="warning">
                ClamAV requires at least {{ CLAMAV_MIN_RAM_MB }} MB of server RAM (detected {{ serverRamMb }} MB).
                Upgrade server memory before enabling ClamAV.
            </UiAlert>

            <UiAlert v-if="module.status === 'failed' && module.error_message" variant="danger">
                {{ module.error_message }}
            </UiAlert>
        </div>

        <div class="pt-4 border-t border-theme-border-subtle flex flex-wrap items-center justify-between gap-2">
            <span class="text-[11px] text-theme-text-muted">
                <template v-if="isInFlight">Provisioning in background...</template>
                <template v-else-if="module.is_active">Active on server</template>
                <template v-else-if="unmetDependencies.length > 0">
                    Install {{ unmetDependencies.join(', ') }} first
                </template>
                <template v-else-if="isClamavRamBlocked">Insufficient RAM (&lt; 4096 MB)</template>
                <template v-else>Ready to install on-demand</template>
            </span>

            <div class="flex items-center gap-2">
                <a
                    v-if="module.key === 'mail_webmail' && module.is_active && module.launch_url"
                    :href="module.launch_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-theme-md text-xs font-medium border border-theme-border text-theme-text-primary hover:bg-theme-bg-surface transition-colors"
                >
                    <ExternalLink class="w-3.5 h-3.5" />
                    <span>Launch SnappyMail</span>
                </a>

                <UiButton
                    v-if="!module.is_active"
                    variant="primary"
                    size="sm"
                    :loading="isInFlight"
                    :disabled="installDisabled"
                    @click="emit('install', module)"
                >
                    <Download v-if="!isInFlight" class="w-3.5 h-3.5" />
                    <span>{{ isInFlight ? 'Provisioning...' : 'Install' }}</span>
                </UiButton>

                <UiButton
                    v-else
                    variant="danger-soft"
                    size="sm"
                    :disabled="busy"
                    @click="emit('uninstall', module)"
                >
                    <Trash2 class="w-3.5 h-3.5" />
                    <span>Uninstall</span>
                </UiButton>
            </div>
        </div>
    </UiCard>
</template>
