<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import {
    SlidersHorizontal,
    Save,
    Lock,
    Unlock,
    Clock,
    Mail,
    Server,
    AlertTriangle,
} from '@lucide/vue';
import UiModal from '@shared/Ui/UiModal.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    zone: {
        type: Object as () => Record<string, any>,
        required: true,
    },
    ttlPresets: {
        type: Array as () => number[],
        default: () => [60, 300, 900, 3600, 14400, 86400],
    },
    isReadOnly: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close']);

const soaSource = computed(() => props.zone?.soa || {});

const form = useForm({
    primary_ns: '',
    hostmaster: '',
    ttl: 3600,
    refresh: 10800,
    retry: 3600,
    expire: 604800,
    minimum: 3600,
    manual_serial_override: false,
    serial: 0,
    comment: '',
});

watch(
    () => [props.show, props.zone],
    ([isOpen]) => {
        if (!isOpen) {
            return;
        }
        form.clearErrors();
        const soa = soaSource.value;
        form.primary_ns = String(soa.primary_ns || `ns1.${props.zone?.name || ''}`);
        form.hostmaster = String(
            soa.hostmaster_email || soa.hostmaster_rname || `hostmaster@${props.zone?.ascii_name || 'example.com'}`
        );
        form.ttl = Number(soa.ttl || 3600);
        form.refresh = Number(soa.refresh || 10800);
        form.retry = Number(soa.retry || 3600);
        form.expire = Number(soa.expire || 604800);
        form.minimum = Number(soa.minimum || 3600);
        form.manual_serial_override = false;
        form.serial = Number(soa.serial || props.zone?.serial || 0);
        form.comment = String(soa.comment || '');
    },
    { immediate: true }
);

const rnamePreview = computed(() => {
    const raw = form.hostmaster.trim();
    if (!raw) {
        return '—';
    }
    if (raw.includes('@')) {
        const [local, domain] = raw.split('@', 2);
        if (!local || !domain) {
            return raw;
        }
        const escapedLocal = local.toLowerCase().replace(/\./g, '\\.');
        const cleanDomain = domain.toLowerCase().replace(/\.$/, '');
        return `${escapedLocal}.${cleanDomain}.`;
    }
    return raw.endsWith('.') ? raw.toLowerCase() : `${raw.toLowerCase()}.`;
});

const formatTtlPreset = (seconds: number): string => {
    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) return `${seconds / 60}m (${seconds}s)`;
    if (seconds < 86400) return `${seconds / 3600}h (${seconds}s)`;
    return `${seconds / 86400}d (${seconds}s)`;
};

const submit = () => {
    if (props.isReadOnly) {
        return;
    }
    const encodedZone = encodeURIComponent(String(props.zone.id || props.zone.name));
    form.put(`/dns/zones/${encodedZone}/soa`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};
</script>

<template>
    <UiModal
        :show="show"
        :title="__('Zone SOA (Start of Authority) Settings')"
        max-width="2xl"
        @close="emit('close')"
    >
        <form @submit.prevent="submit" class="space-y-5">
            <!-- Zone Context Header -->
            <div class="p-3.5 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle flex flex-wrap items-center justify-between gap-3">
                <div class="space-y-0.5">
                    <div class="text-xs text-theme-text-secondary">{{ __('Authoritative Zone Apex') }}</div>
                    <div class="font-mono text-sm font-semibold text-theme-text-primary">
                        {{ zone?.name }}
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <UiBadge variant="info">
                        <SlidersHorizontal class="w-3.5 h-3.5" />
                        <span class="font-mono text-xs">SOA-EDIT-API: {{ zone?.soa_edit_api || 'DEFAULT' }}</span>
                    </UiBadge>
                    <UiBadge variant="neutral">
                        <span class="font-mono text-xs">{{ __('Current Serial:') }} {{ zone?.serial || soaSource?.serial || 0 }}</span>
                    </UiBadge>
                </div>
            </div>

            <!-- Primary NS & Hostmaster Email -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <UiInput
                        id="soa-primary-ns"
                        v-model="form.primary_ns"
                        :label="__('Primary Nameserver (MNAME)')"
                        placeholder="ns1.example.com."
                        :error="form.errors.primary_ns"
                        :disabled="isReadOnly"
                        required
                    />
                    <p class="text-[11px] text-theme-text-muted flex items-center gap-1">
                        <Server class="w-3 h-3 shrink-0" />
                        <span>{{ __('Canonical primary authoritative nameserver FQDN.') }}</span>
                    </p>
                </div>

                <div class="space-y-1.5">
                    <UiInput
                        id="soa-hostmaster"
                        v-model="form.hostmaster"
                        :label="__('Hostmaster Email or RNAME')"
                        placeholder="hostmaster@example.com"
                        :error="form.errors.hostmaster"
                        :disabled="isReadOnly"
                        required
                    />
                    <p class="text-[11px] text-theme-text-muted flex items-center gap-1">
                        <Mail class="w-3 h-3 shrink-0" />
                        <span>
                            {{ __('Serialized RNAME:') }}
                            <code class="font-mono text-theme-text-primary">{{ rnamePreview }}</code>
                        </span>
                    </p>
                </div>
            </div>

            <!-- SOA Record TTL + Presets -->
            <div class="p-3.5 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xs font-semibold text-theme-text-secondary flex items-center gap-1.5">
                        <Clock class="w-3.5 h-3.5 text-theme-accent" />
                        <span>{{ __('SOA Record TTL (60s – 604800s)') }}</span>
                    </span>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <button
                            v-for="preset in ttlPresets"
                            :key="preset"
                            type="button"
                            :disabled="isReadOnly"
                            class="px-2 py-0.5 rounded-theme-sm text-[11px] font-mono font-semibold border transition-colors cursor-pointer"
                            :class="
                                Number(form.ttl) === preset
                                    ? 'bg-theme-accent text-white border-theme-accent'
                                    : 'bg-theme-bg-card text-theme-text-secondary border-theme-border hover:text-theme-text-primary'
                            "
                            @click="form.ttl = preset"
                        >
                            {{ formatTtlPreset(preset) }}
                        </button>
                    </div>
                </div>
                <UiInput
                    id="soa-ttl"
                    v-model="form.ttl"
                    type="number"
                    :label="__('Custom SOA TTL (Seconds)')"
                    :error="form.errors.ttl"
                    :disabled="isReadOnly"
                    required
                />
            </div>

            <!-- SOA Replication & Caching Timers -->
            <div class="space-y-2.5">
                <div class="text-xs font-bold uppercase tracking-wider text-theme-text-secondary">
                    {{ __('Zone Transfer & Negative Cache Timers (Seconds)') }}
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div>
                        <UiInput
                            id="soa-refresh"
                            v-model="form.refresh"
                            type="number"
                            :label="__('Refresh (s)')"
                            :error="form.errors.refresh"
                            :disabled="isReadOnly"
                            required
                        />
                        <span class="text-[11px] text-theme-text-muted">{{ __('Default: 10800 (3h)') }}</span>
                    </div>

                    <div>
                        <UiInput
                            id="soa-retry"
                            v-model="form.retry"
                            type="number"
                            :label="__('Retry (s)')"
                            :error="form.errors.retry"
                            :disabled="isReadOnly"
                            required
                        />
                        <span class="text-[11px] text-theme-text-muted">{{ __('Default: 3600 (1h)') }}</span>
                    </div>

                    <div>
                        <UiInput
                            id="soa-expire"
                            v-model="form.expire"
                            type="number"
                            :label="__('Expire (s)')"
                            :error="form.errors.expire"
                            :disabled="isReadOnly"
                            required
                        />
                        <span class="text-[11px] text-theme-text-muted">{{ __('Default: 604800 (7d)') }}</span>
                    </div>

                    <div>
                        <UiInput
                            id="soa-minimum"
                            v-model="form.minimum"
                            type="number"
                            :label="__('Minimum / Neg. TTL')"
                            :error="form.errors.minimum"
                            :disabled="isReadOnly"
                            required
                        />
                        <span class="text-[11px] text-theme-text-muted">{{ __('Default: 3600 (1h)') }}</span>
                    </div>
                </div>
            </div>

            <!-- SOA Serial (Read-only by default, optional manual override) -->
            <div class="p-3.5 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <Lock v-if="!form.manual_serial_override" class="w-4 h-4 text-theme-text-muted" />
                        <Unlock v-else class="w-4 h-4 text-theme-warning" />
                        <span class="text-xs font-semibold text-theme-text-primary">
                            {{ __('SOA Serial Number Management') }}
                        </span>
                    </div>

                    <label
                        v-if="!isReadOnly"
                        class="inline-flex items-center gap-2 text-xs font-medium text-theme-text-secondary cursor-pointer"
                    >
                        <input
                            v-model="form.manual_serial_override"
                            type="checkbox"
                            class="rounded border-theme-border text-theme-accent focus:ring-theme-accent"
                        />
                        <span>{{ __('Manual Serial Override (Advanced Troubleshooting)') }}</span>
                    </label>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-start">
                    <UiInput
                        id="soa-serial"
                        v-model="form.serial"
                        type="number"
                        :label="__('SOA Serial')"
                        :disabled="!form.manual_serial_override || isReadOnly"
                        :error="form.errors.serial"
                    />
                    <div class="text-xs text-theme-text-muted pt-1 leading-relaxed">
                        <template v-if="!form.manual_serial_override">
                            {{
                                __('Managed automatically by PowerDNS SOA-EDIT-API=DEFAULT. Every zone or record edit increments the serial automatically.')
                            }}
                        </template>
                        <template v-else>
                            <span class="text-theme-warning flex items-start gap-1.5">
                                <AlertTriangle class="w-4 h-4 shrink-0 mt-0.5" />
                                <span>
                                    {{
                                        __('Caution: Setting a lower serial than secondary nameservers hold will stall AXFR/IXFR replication until the serial exceeds the secondary value.')
                                    }}
                                </span>
                            </span>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Optional SOA Comment -->
            <UiInput
                id="soa-comment"
                v-model="form.comment"
                :label="__('SOA Audit Comment (Optional)')"
                :placeholder="__('e.g., Adjusted negative cache TTL before migration')"
                :error="form.errors.comment"
                :disabled="isReadOnly"
            />

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-theme-border-subtle">
                <UiButton
                    type="button"
                    variant="secondary"
                    :disabled="form.processing"
                    @click="emit('close')"
                >
                    {{ __('Close') }}
                </UiButton>
                <UiButton
                    v-if="!isReadOnly"
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    :disabled="form.processing"
                >
                    <Save class="w-4 h-4" />
                    <span>{{ form.processing ? __('Updating SOA...') : __('Save SOA Settings') }}</span>
                </UiButton>
            </div>
        </form>
    </UiModal>
</template>
