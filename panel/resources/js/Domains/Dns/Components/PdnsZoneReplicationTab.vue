<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import {
    Radio,
    RefreshCw,
    Server,
    ShieldAlert,
    AlertTriangle,
    CheckCircle2,
    Save,
    ArrowRightLeft,
    Clock,
    Lock,
    Network,
    Send,
    DownloadCloud,
    Settings,
} from '@lucide/vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
import UiModal from '@shared/Ui/UiModal.vue';

const props = defineProps({
    zone: {
        type: Object as () => Record<string, any>,
        required: true,
    },
    capabilities: {
        type: Object as () => Record<string, any>,
        default: () => ({
            has_api_key: false,
            primary_enabled: true,
            secondary_enabled: false,
            autosecondary_enabled: false,
            allow_axfr_ips: '',
            also_notify: '',
        }),
    },
    notifying: {
        type: Boolean,
        default: false,
    },
    retrieving: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['trigger-notify', 'trigger-axfr-retrieve']);

const showOverwriteModal = ref(false);

const form = useForm({
    kind: 'Native' as 'Native' | 'Primary' | 'Secondary',
    masters: '',
    allow_axfr_from: '',
    also_notify: '',
    confirm_overwrite: false,
});

watch(
    () => props.zone,
    (zone) => {
        if (!zone) {
            return;
        }
        form.clearErrors();
        form.kind = (zone.kind as 'Native' | 'Primary' | 'Secondary') || 'Native';
        form.masters = Array.isArray(zone.masters) ? zone.masters.join(', ') : '';
        form.allow_axfr_from = Array.isArray(zone.allow_axfr_from)
            ? zone.allow_axfr_from.join(', ')
            : '';
        form.also_notify = Array.isArray(zone.also_notify)
            ? zone.also_notify.join(', ')
            : '';
        form.confirm_overwrite = false;
    },
    { immediate: true, deep: true }
);

const currentKind = computed(() => String(props.zone?.kind || 'Native'));
const isSwitchingToSecondary = computed(
    () => form.kind === 'Secondary' && currentKind.value !== 'Secondary'
);
const isSwitchingFromSecondary = computed(
    () => currentKind.value === 'Secondary' && form.kind !== 'Secondary'
);

const isGlobalPrimaryEnabled = computed(() => Boolean(props.capabilities?.primary_enabled));
const isGlobalSecondaryEnabled = computed(() => Boolean(props.capabilities?.secondary_enabled));

const formattedLastCheck = computed(() => {
    const ts = props.zone?.last_check;
    if (!ts || Number(ts) <= 0) {
        return 'Never checked / Pending';
    }
    try {
        return new Date(Number(ts) * 1000).toLocaleString();
    } catch {
        return String(ts);
    }
});

const modeOptions = [
    {
        id: 'Native' as const,
        title: 'Native Mode',
        badge: 'Standalone / DB Replication',
        description:
            'No DNS AXFR/IXFR transfers. Relies on standalone single-VPS serving or database-level replication. Full local RRSet editing enabled with SOA-EDIT-API=DEFAULT and API-RECTIFY=1.',
    },
    {
        id: 'Primary' as const,
        title: 'Primary (Master) Mode',
        badge: 'Outbound NOTIFY & AXFR',
        description:
            'Authoritative source pushing DNS NOTIFY packets and answering AXFR/IXFR transfer requests from allowlisted secondary nameservers.',
    },
    {
        id: 'Secondary' as const,
        title: 'Secondary (Slave) Mode',
        badge: 'Read-Only AXFR Replica',
        description:
            'Pulls zone records from upstream primary server IP(s) via AXFR/IXFR. Local record editing is locked to prevent divergence from upstream masters.',
    },
];

const encodedZoneId = computed(() =>
    encodeURIComponent(String(props.zone?.id || props.zone?.name || ''))
);

const executeSubmit = () => {
    form.put(`/dns/zones/${encodedZoneId.value}/mode`, {
        preserveScroll: true,
        onSuccess: () => {
            showOverwriteModal.value = false;
        },
    });
};

const handleFormSubmit = () => {
    if (isSwitchingToSecondary.value && !form.confirm_overwrite) {
        showOverwriteModal.value = true;
        return;
    }

    executeSubmit();
};

const confirmSecondaryOverwrite = () => {
    form.confirm_overwrite = true;
    executeSubmit();
};
</script>

<template>
    <div class="space-y-6">
        <!-- Global Daemon Capability Warnings -->
        <UiAlert
            v-if="form.kind === 'Primary' && !isGlobalPrimaryEnabled"
            variant="warning"
        >
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-start gap-2.5">
                    <AlertTriangle class="w-4 h-4 shrink-0 mt-0.5" />
                    <div class="space-y-1">
                        <div class="font-semibold">
                            {{ __('Global Daemon Primary Role is Disabled (primary=no)') }}
                        </div>
                        <p class="text-xs">
                            {{
                                __('PowerDNS will not send outbound DNS NOTIFY packets or answer incoming AXFR/IXFR transfer queries until global primary=yes is enabled in Daemon Settings.')
                            }}
                        </p>
                    </div>
                </div>
                <Link
                    href="/dns/settings"
                    class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-theme-sm text-xs font-semibold bg-theme-bg-card border border-theme-border text-theme-text-primary hover:bg-theme-bg-surface"
                >
                    <Settings class="w-3.5 h-3.5" />
                    <span>{{ __('Enable in Daemon Settings') }}</span>
                </Link>
            </div>
        </UiAlert>

        <UiAlert
            v-if="form.kind === 'Secondary' && !isGlobalSecondaryEnabled"
            variant="warning"
        >
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-start gap-2.5">
                    <AlertTriangle class="w-4 h-4 shrink-0 mt-0.5" />
                    <div class="space-y-1">
                        <div class="font-semibold">
                            {{ __('Global Daemon Secondary Role is Disabled (secondary=no)') }}
                        </div>
                        <p class="text-xs">
                            {{
                                __('PowerDNS will not poll upstream primary servers or perform AXFR/IXFR zone pulls until global secondary=yes is enabled in Daemon Settings.')
                            }}
                        </p>
                    </div>
                </div>
                <Link
                    href="/dns/settings"
                    class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-theme-sm text-xs font-semibold bg-theme-bg-card border border-theme-border text-theme-text-primary hover:bg-theme-bg-surface"
                >
                    <Settings class="w-3.5 h-3.5" />
                    <span>{{ __('Enable in Daemon Settings') }}</span>
                </Link>
            </div>
        </UiAlert>

        <!-- Live Replication Telemetry & Quick Transfer Actions Card -->
        <UiCard
            :title="__('Live Replication Telemetry & Transfer Actions')"
            :subtitle="__('Current authoritative serial status and immediate NOTIFY / AXFR transfer triggers.')"
        >
            <template #header>
                <div class="flex flex-wrap items-center gap-2">
                    <UiBadge
                        :variant="
                            currentKind === 'Primary'
                                ? 'primary'
                                : currentKind === 'Secondary'
                                  ? 'warning'
                                  : 'info'
                        "
                    >
                        <ArrowRightLeft class="w-3.5 h-3.5" />
                        <span>{{ __('Active Mode:') }} {{ currentKind }}</span>
                    </UiBadge>

                    <UiButton
                        v-if="currentKind === 'Primary'"
                        type="button"
                        variant="primary"
                        size="sm"
                        :loading="notifying"
                        :disabled="notifying"
                        @click="emit('trigger-notify')"
                    >
                        <Send v-if="!notifying" class="w-3.5 h-3.5" />
                        <span>{{ notifying ? __('Sending NOTIFY...') : __('Send Notify') }}</span>
                    </UiButton>

                    <UiButton
                        v-if="currentKind === 'Secondary'"
                        type="button"
                        variant="primary"
                        size="sm"
                        :loading="retrieving"
                        :disabled="retrieving"
                        @click="emit('trigger-axfr-retrieve')"
                    >
                        <DownloadCloud v-if="!retrieving" class="w-3.5 h-3.5" />
                        <span>{{ retrieving ? __('Syncing AXFR...') : __('Retrieve / Sync Now') }}</span>
                    </UiButton>
                </div>
            </template>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
                <div class="p-3 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle space-y-1">
                    <div class="text-theme-text-muted flex items-center gap-1.5">
                        <Server class="w-3.5 h-3.5" />
                        <span>{{ __('Current SOA Serial') }}</span>
                    </div>
                    <div class="font-mono text-sm font-bold text-theme-text-primary">
                        {{ zone?.serial || '—' }}
                    </div>
                </div>

                <div class="p-3 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle space-y-1">
                    <div class="text-theme-text-muted flex items-center gap-1.5">
                        <Radio class="w-3.5 h-3.5" />
                        <span>{{ __('Last Notified Serial') }}</span>
                    </div>
                    <div class="font-mono text-sm font-bold text-theme-text-primary">
                        {{ zone?.notified_serial || '—' }}
                    </div>
                </div>

                <div class="p-3 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle space-y-1">
                    <div class="text-theme-text-muted flex items-center gap-1.5">
                        <Clock class="w-3.5 h-3.5" />
                        <span>{{ __('Last Secondary Check') }}</span>
                    </div>
                    <div class="font-mono text-xs font-semibold text-theme-text-primary">
                        {{ formattedLastCheck }}
                    </div>
                </div>

                <div class="p-3 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle space-y-1">
                    <div class="text-theme-text-muted flex items-center gap-1.5">
                        <Lock class="w-3.5 h-3.5" />
                        <span>{{ __('Record Editing Policy') }}</span>
                    </div>
                    <div class="flex items-center gap-1.5 pt-0.5">
                        <UiBadge :variant="currentKind === 'Secondary' ? 'warning' : 'success'">
                            <span>
                                {{
                                    currentKind === 'Secondary'
                                        ? __('Read-Only (AXFR Slave)')
                                        : __('Full CRUD Enabled')
                                }}
                            </span>
                        </UiBadge>
                    </div>
                </div>
            </div>
        </UiCard>

        <!-- Operating Mode & Transfer Controls Form -->
        <UiCard
            :title="__('Zone Operating Mode & Replication Controls')"
            :subtitle="__('Switch between Native, Primary (Master), and Secondary (Slave) modes and configure per-zone AXFR / NOTIFY metadata.')"
        >
            <form @submit.prevent="handleFormSubmit" class="space-y-6">
                <!-- Mode Selection Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <button
                        v-for="option in modeOptions"
                        :key="option.id"
                        type="button"
                        class="text-left p-4 rounded-theme-lg border transition-all cursor-pointer flex flex-col justify-between gap-3"
                        :class="
                            form.kind === option.id
                                ? 'border-theme-accent bg-theme-accent/10 ring-1 ring-theme-accent'
                                : 'border-theme-border bg-theme-bg-surface/60 hover:bg-theme-bg-surface'
                        "
                        @click="form.kind = option.id"
                    >
                        <div class="space-y-2">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-bold text-theme-text-primary">
                                    {{ __(option.title) }}
                                </span>
                                <CheckCircle2
                                    v-if="form.kind === option.id"
                                    class="w-4 h-4 text-theme-accent shrink-0"
                                />
                            </div>
                            <UiBadge
                                :variant="
                                    option.id === 'Primary'
                                        ? 'primary'
                                        : option.id === 'Secondary'
                                          ? 'warning'
                                          : 'info'
                                "
                            >
                                <span>{{ __(option.badge) }}</span>
                            </UiBadge>
                            <p class="text-xs text-theme-text-secondary leading-relaxed">
                                {{ __(option.description) }}
                            </p>
                        </div>
                    </button>
                </div>

                <!-- Primary Mode Settings: ALLOW-AXFR-FROM & ALSO-NOTIFY -->
                <div
                    v-if="form.kind === 'Primary'"
                    class="p-4 rounded-theme-lg bg-theme-bg-surface border border-theme-border-subtle space-y-4"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-theme-border-subtle pb-3">
                        <div>
                            <h4 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary flex items-center gap-2">
                                <Radio class="w-4 h-4 text-theme-accent" />
                                <span>{{ __('Primary Zone Transfer & Notification Metadata') }}</span>
                            </h4>
                            <p class="text-xs text-theme-text-secondary mt-0.5">
                                {{
                                    __('Enforces SOA-EDIT-API=DEFAULT and API-RECTIFY=1 so every record change increments the SOA serial automatically.')
                                }}
                            </p>
                        </div>
                        <UiBadge variant="primary">
                            <span class="font-mono text-[11px]">SOA-EDIT-API=DEFAULT</span>
                        </UiBadge>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <UiInput
                                id="zone-mode-allow-axfr"
                                v-model="form.allow_axfr_from"
                                :label="__('ALLOW-AXFR-FROM (Secondary IPs / CIDRs)')"
                                placeholder="198.51.100.20, 203.0.113.0/24, 2001:db8::/64"
                                :error="form.errors.allow_axfr_from"
                            />
                            <p class="text-[11px] text-theme-text-muted leading-relaxed">
                                {{
                                    __('Comma-separated list of secondary nameserver IPs or CIDR ranges authorized to pull AXFR/IXFR transfers for this zone.')
                                }}
                                <span v-if="capabilities?.allow_axfr_ips" class="block font-mono text-theme-text-secondary mt-0.5">
                                    {{ __('Global default allow-axfr-ips:') }} {{ capabilities.allow_axfr_ips }}
                                </span>
                            </p>
                        </div>

                        <div class="space-y-1.5">
                            <UiInput
                                id="zone-mode-also-notify"
                                v-model="form.also_notify"
                                :label="__('ALSO-NOTIFY (Hidden / Non-NS Secondaries)')"
                                placeholder="198.51.100.25, 203.0.113.30:5300"
                                :error="form.errors.also_notify"
                            />
                            <p class="text-[11px] text-theme-text-muted leading-relaxed">
                                {{
                                    __('Optional comma-separated IP or IP:port targets to receive DNS NOTIFY in addition to the zone NS records.')
                                }}
                                <span v-if="capabilities?.also_notify" class="block font-mono text-theme-text-secondary mt-0.5">
                                    {{ __('Global default also-notify:') }} {{ capabilities.also_notify }}
                                </span>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Secondary Mode Settings: Upstream Primary Masters -->
                <div
                    v-if="form.kind === 'Secondary'"
                    class="p-4 rounded-theme-lg bg-theme-bg-surface border border-theme-border-subtle space-y-4"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-theme-border-subtle pb-3">
                        <div>
                            <h4 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary flex items-center gap-2">
                                <Network class="w-4 h-4 text-theme-warning" />
                                <span>{{ __('Secondary Zone Upstream Primary (Masters) Configuration') }}</span>
                            </h4>
                            <p class="text-xs text-theme-text-secondary mt-0.5">
                                {{
                                    __('Specify one or more authoritative primary IP addresses (with optional :port) from which PowerDNS will transfer this zone.')
                                }}
                            </p>
                        </div>
                        <UiBadge variant="warning">
                            <Lock class="w-3 h-3" />
                            <span>{{ __('Read-Only Local Grid') }}</span>
                        </UiBadge>
                    </div>

                    <div class="space-y-1.5">
                        <UiInput
                            id="zone-mode-masters"
                            v-model="form.masters"
                            :label="__('Upstream Primary Server IP(s) (masters)')"
                            placeholder="203.0.113.10, 198.51.100.53:53, [2001:db8::10]:53"
                            :error="form.errors.masters"
                            required
                        />
                        <p class="text-[11px] text-theme-text-muted">
                            {{
                                __('Enter IPv4 or IPv6 addresses separated by commas. Optional non-standard ports may be specified as IP:port or [IPv6]:port.')
                            }}
                        </p>
                    </div>

                    <UiAlert v-if="isSwitchingToSecondary" variant="warning">
                        <div class="flex items-start gap-2.5">
                            <ShieldAlert class="w-4 h-4 shrink-0 mt-0.5" />
                            <div class="text-xs space-y-1">
                                <div class="font-semibold">
                                    {{ __('Caution: Switching to Secondary Mode Overwrites Local Records') }}
                                </div>
                                <p>
                                    {{
                                        __('Once converted to Secondary mode, the next AXFR zone transfer from the configured upstream primary servers will overwrite all existing local RRSets in this zone.')
                                    }}
                                </p>
                            </div>
                        </div>
                    </UiAlert>
                </div>

                <!-- Transitioning Out of Secondary Notice -->
                <UiAlert v-if="isSwitchingFromSecondary" variant="info">
                    <div class="flex items-start gap-2.5">
                        <RefreshCw class="w-4 h-4 shrink-0 mt-0.5" />
                        <div class="text-xs space-y-1">
                            <div class="font-semibold">
                                {{ __('Promote Secondary Zone to Local Authoritative Editing') }}
                            </div>
                            <p>
                                {{
                                    __('Switching from Secondary to Primary or Native mode will clear upstream masters, unlock local RRSet editing, enforce SOA-EDIT-API=DEFAULT, and automatically rectify the zone.')
                                }}
                            </p>
                        </div>
                    </div>
                </UiAlert>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-theme-border-subtle">
                    <UiButton
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                        :disabled="form.processing"
                    >
                        <Save class="w-4 h-4" />
                        <span>
                            {{
                                form.processing
                                    ? __('Saving Mode & Transfer Settings...')
                                    : __('Save Operating Mode & Transfer Settings')
                            }}
                        </span>
                    </UiButton>
                </div>
            </form>
        </UiCard>

        <!-- Explicit Confirmation Modal when switching Primary/Native -> Secondary -->
        <UiModal
            :show="showOverwriteModal"
            :title="__('Confirm Switch to Secondary (Slave) Mode')"
            max-width="lg"
            @close="showOverwriteModal = false"
        >
            <div class="space-y-4">
                <UiAlert variant="danger">
                    <div class="flex items-start gap-2.5">
                        <ShieldAlert class="w-5 h-5 shrink-0 mt-0.5" />
                        <div class="space-y-1 text-xs">
                            <div class="font-bold text-sm">
                                {{ __('Next AXFR Transfer Will Overwrite Local Records') }}
                            </div>
                            <p>
                                {{
                                    __('You are converting this zone from an authoritative local zone into a Secondary (Slave) replica. When PowerDNS completes its next AXFR transfer from the upstream primary server(s), all locally edited RRSets in this zone will be replaced by the upstream zone contents.')
                                }}
                            </p>
                        </div>
                    </div>
                </UiAlert>

                <div class="p-3.5 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle text-xs space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-theme-text-secondary">{{ __('Target Zone:') }}</span>
                        <span class="font-mono font-bold text-theme-text-primary">{{ zone?.name }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-theme-text-secondary">{{ __('Mode Transition:') }}</span>
                        <span class="font-mono font-semibold text-theme-warning">
                            {{ currentKind }} → Secondary
                        </span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-theme-text-secondary">{{ __('Configured Upstream Masters:') }}</span>
                        <span class="font-mono text-theme-text-primary">{{ form.masters || '—' }}</span>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-theme-border-subtle">
                    <UiButton
                        type="button"
                        variant="secondary"
                        :disabled="form.processing"
                        @click="showOverwriteModal = false"
                    >
                        {{ __('Cancel') }}
                    </UiButton>
                    <UiButton
                        type="button"
                        variant="danger"
                        :loading="form.processing"
                        :disabled="form.processing"
                        @click="confirmSecondaryOverwrite"
                    >
                        <ShieldAlert class="w-4 h-4" />
                        <span>{{ __('Confirm & Switch to Secondary Mode') }}</span>
                    </UiButton>
                </div>
            </div>
        </UiModal>
    </div>
</template>
