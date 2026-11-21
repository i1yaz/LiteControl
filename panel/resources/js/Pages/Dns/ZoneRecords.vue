<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Globe,
    ArrowLeft,
    Plus,
    Zap,
    SlidersHorizontal,
    Download,
    Search,
    Layers,
    Save,
    Trash2,
    Edit3,
    ShieldCheck,
    RefreshCw,
    Lock,
    CheckCircle2,
    PauseCircle,
    MessageSquare,
    X,
    Network,
    Scissors,
    ArrowRightLeft,
    Send,
    DownloadCloud,
    Clock,
    Eraser,
} from '@lucide/vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiTabs from '@shared/Ui/UiTabs.vue';
import UiDataTable from '@shared/Ui/UiDataTable.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import PdnsRrsetModal from '@domains/Dns/Components/PdnsRrsetModal.vue';
import PdnsSoaSettingsDrawer from '@domains/Dns/Components/PdnsSoaSettingsDrawer.vue';
import PdnsZoneReplicationTab from '@domains/Dns/Components/PdnsZoneReplicationTab.vue';

const props = defineProps({
    zone: {
        type: Object as () => Record<string, any>,
        required: true,
    },
    supportedTypes: {
        type: Array as () => string[],
        default: () => ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'NS', 'PTR', 'CAA', 'SOA'],
    },
    editableRecordTypes: {
        type: Array as () => string[],
        default: () => ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'NS', 'PTR', 'CAA'],
    },
    ttlPresets: {
        type: Array as () => number[],
        default: () => [60, 300, 900, 3600, 14400, 86400],
    },
    ttlBounds: {
        type: Object as () => { min: number; max: number },
        default: () => ({ min: 60, max: 604800 }),
    },
    isReadOnly: {
        type: Boolean,
        default: false,
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
});

const activeMainTab = ref<'records' | 'replication'>('records');
const activeTypeTab = ref('ALL');
const searchFilter = ref('');
const showRrsetModal = ref(false);
const modalInitialChangetype = ref<'REPLACE' | 'EXTEND'>('REPLACE');
const editingRrset = ref<Record<string, any> | null>(null);
const showSoaDrawer = ref(false);
const patchSubmitting = ref(false);
const notifying = ref(false);
const retrievingAxfr = ref(false);
const rectifyingZone = ref(false);
const flushingZoneCache = ref(false);

const mainSectionTabs = computed(() => [
    {
        id: 'records',
        label: `DNS Records (${rrsets.value.length})`,
    },
    {
        id: 'replication',
        label: `Replication / Mode (${props.zone?.kind || 'Native'})`,
    },
]);

const formattedLastCheck = computed(() => {
    const ts = props.zone?.last_check;
    if (!ts || Number(ts) <= 0) {
        return 'Never / Pending';
    }
    try {
        return new Date(Number(ts) * 1000).toLocaleString();
    } catch {
        return String(ts);
    }
});

// Staged Bulk Operations Queue for Draft / Bulk Save Mode
const stagedOperations = ref<Array<Record<string, any>>>([]);

const rrsets = computed<Array<Record<string, any>>>(() =>
    Array.isArray(props.zone?.rrsets) ? props.zone.rrsets : []
);

const typeTabs = computed(() => {
    const counts: Record<string, number> = { ALL: rrsets.value.length };
    for (const t of props.supportedTypes) {
        counts[t] = 0;
    }
    for (const rr of rrsets.value) {
        const t = String(rr.type || '');
        counts[t] = (counts[t] || 0) + 1;
    }

    const tabs = [{ id: 'ALL', label: `All (${counts.ALL})` }];
    for (const t of props.supportedTypes) {
        if (counts[t] > 0 || ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SOA'].includes(t)) {
            tabs.push({ id: t, label: `${t} (${counts[t] || 0})` });
        }
    }
    return tabs;
});

const filteredRrsets = computed(() => {
    const q = searchFilter.value.trim().toLowerCase();

    return rrsets.value.filter((rr) => {
        if (activeTypeTab.value !== 'ALL' && rr.type !== activeTypeTab.value) {
            return false;
        }
        if (!q) {
            return true;
        }

        const nameMatch = String(rr.name || '').toLowerCase().includes(q);
        const relMatch = String(rr.relative_name || '').toLowerCase().includes(q);
        const dispMatch = String(rr.display_name || '').toLowerCase().includes(q);
        const commentMatch = String(rr.primary_comment || '').toLowerCase().includes(q);
        const recordMatch = Array.isArray(rr.records)
            ? rr.records.some((r: Record<string, any>) =>
                  String(r.content || '').toLowerCase().includes(q)
              )
            : false;

        return nameMatch || relMatch || dispMatch || commentMatch || recordMatch;
    });
});

const tableHeaders = [
    { label: 'Host / Owner Name' },
    { label: 'Type' },
    { label: 'TTL' },
    { label: 'Records & Values (RRSets)' },
    { label: 'Comment' },
    { label: 'Actions', class: 'text-right' },
];

const encodedZoneId = computed(() =>
    encodeURIComponent(String(props.zone?.id || props.zone?.name || ''))
);

const exportZoneUrl = computed(() => `/dns/zones/${encodedZoneId.value}/export`);

const typeBadgeVariant = (recType: string) => {
    switch (recType) {
        case 'SOA':
        case 'NS':
            return 'primary';
        case 'A':
        case 'AAAA':
            return 'success';
        case 'CNAME':
            return 'info';
        case 'MX':
        case 'TXT':
            return 'warning';
        default:
            return 'neutral';
    }
};

const formatTtl = (seconds: number): string => {
    if (!seconds) return '—';
    if (seconds === 60) return '60s (1m)';
    if (seconds === 300) return '300s (5m)';
    if (seconds === 900) return '900s (15m)';
    if (seconds === 3600) return '3600s (1h)';
    if (seconds === 14400) return '14400s (4h)';
    if (seconds === 86400) return '86400s (24h)';
    return `${seconds}s`;
};

const openCreateModal = (mode: 'REPLACE' | 'EXTEND' = 'REPLACE') => {
    if (props.isReadOnly) return;
    editingRrset.value = null;
    modalInitialChangetype.value = mode;
    showRrsetModal.value = true;
};

const openEditModal = (rrset: Record<string, any>) => {
    if (props.isReadOnly) return;
    if (rrset.type === 'SOA') {
        showSoaDrawer.value = true;
        return;
    }
    editingRrset.value = rrset;
    modalInitialChangetype.value = 'REPLACE';
    showRrsetModal.value = true;
};

const dispatchPatchOperations = (operations: Array<Record<string, any>>, clearStagedOnSuccess = false) => {
    if (props.isReadOnly || operations.length === 0) return;

    const cleanOps = operations.map((op) => {
        const copy: Record<string, any> = {
            name: op.name,
            type: op.type,
            changetype: op.changetype || 'REPLACE',
        };
        if (copy.changetype !== 'DELETE' && copy.changetype !== 'PRUNE' && op.ttl !== undefined && op.ttl !== null) {
            copy.ttl = Number(op.ttl);
        }
        if (copy.changetype !== 'DELETE') {
            copy.records = op.records || [];
        }
        if (copy.changetype === 'REPLACE' && Array.isArray(op.comments) && op.comments.length > 0) {
            copy.comments = op.comments;
        }
        return copy;
    });

    patchSubmitting.value = true;
    router.patch(
        `/dns/zones/${encodedZoneId.value}/records`,
        { rrsets: cleanOps },
        {
            preserveScroll: true,
            onSuccess: () => {
                showRrsetModal.value = false;
                if (clearStagedOnSuccess) {
                    stagedOperations.value = [];
                }
            },
            onFinish: () => {
                patchSubmitting.value = false;
            },
        }
    );
};

const handleSaveSingleNow = (operation: Record<string, any>) => {
    dispatchPatchOperations([operation], false);
};

const stageOperation = (operation: Record<string, any>) => {
    if (props.isReadOnly) return;
    stagedOperations.value.push({
        ...operation,
        _uid: `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
    });
};

const stageDeleteRrset = (rrset: Record<string, any>) => {
    if (props.isReadOnly || rrset.type === 'SOA') return;
    stageOperation({
        name: rrset.name,
        canonical_preview: rrset.name,
        type: rrset.type,
        changetype: 'DELETE',
        records: [],
        comments: [],
    });
};

const deleteRrsetNow = (rrset: Record<string, any>) => {
    if (props.isReadOnly || rrset.type === 'SOA') return;
    dispatchPatchOperations([
        {
            name: rrset.name,
            type: rrset.type,
            changetype: 'DELETE',
        },
    ]);
};

const pruneSingleRecordNow = (rrset: Record<string, any>, record: Record<string, any>) => {
    if (props.isReadOnly || rrset.type === 'SOA') return;
    dispatchPatchOperations([
        {
            name: rrset.name,
            type: rrset.type,
            changetype: 'PRUNE',
            records: [
                {
                    content: record.content,
                    disabled: Boolean(record.disabled),
                },
            ],
        },
    ]);
};

const toggleRecordDisabledNow = (rrset: Record<string, any>, recIndex: number) => {
    if (props.isReadOnly || rrset.type === 'SOA') return;

    const updatedRecords = rrset.records.map((r: Record<string, any>, idx: number) => ({
        content: r.content,
        disabled: idx === recIndex ? !r.disabled : Boolean(r.disabled),
    }));

    dispatchPatchOperations([
        {
            name: rrset.name,
            type: rrset.type,
            ttl: rrset.ttl,
            changetype: 'REPLACE',
            records: updatedRecords,
            comments: rrset.comments || [],
        },
    ]);
};

const removeStagedOperation = (index: number) => {
    stagedOperations.value.splice(index, 1);
};

const clearStagedOperations = () => {
    stagedOperations.value = [];
};

const applyAllStagedOperations = () => {
    dispatchPatchOperations(stagedOperations.value, true);
};

const triggerZoneNotify = () => {
    if (props.zone?.kind !== 'Primary' || notifying.value) return;
    notifying.value = true;
    router.put(
        `/dns/zones/${encodedZoneId.value}/notify`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                notifying.value = false;
            },
        }
    );
};

const triggerZoneAxfrRetrieve = () => {
    if (props.zone?.kind !== 'Secondary' || retrievingAxfr.value) return;
    retrievingAxfr.value = true;
    router.put(
        `/dns/zones/${encodedZoneId.value}/axfr-retrieve`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                retrievingAxfr.value = false;
            },
        }
    );
};

const triggerZoneRectify = () => {
    if (rectifyingZone.value) return;
    rectifyingZone.value = true;
    router.put(
        `/dns/zones/${encodedZoneId.value}/rectify`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                rectifyingZone.value = false;
            },
        }
    );
};

const triggerZoneCacheFlush = () => {
    if (flushingZoneCache.value) return;
    flushingZoneCache.value = true;
    router.put(
        `/dns/zones/${encodedZoneId.value}/cache/flush`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                flushingZoneCache.value = false;
            },
        }
    );
};
</script>

<template>
    <Head :title="`${zone?.display_name || zone?.name} — ${__('DNS Record Editor')}`" />

    <div class="space-y-6">
        <!-- Page Header -->
        <UiPageHeader
            :title="`${zone?.name}`"
            :subtitle="
                zone?.is_idn
                    ? `${__('IDN Display:')} ${zone.display_name} — ${__('Authoritative RRSet & SOA Editor')}`
                    : __('Manage authoritative DNS resource record sets (RRSets), SOA parameters, and atomic batch updates.')
            "
        >
            <template #icon>
                <Network v-if="zone?.is_reverse" class="w-6 h-6 text-theme-accent" />
                <Globe v-else class="w-6 h-6 text-theme-accent" />
            </template>

            <template #actions>
                <div class="flex flex-wrap items-center gap-2.5">
                    <Link
                        href="/dns/zones"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-theme-md text-xs font-semibold border border-theme-border bg-theme-bg-card text-theme-text-secondary hover:text-theme-text-primary hover:bg-theme-bg-surface transition-colors"
                    >
                        <ArrowLeft class="w-4 h-4" />
                        <span>{{ __('All Zones') }}</span>
                    </Link>

                    <a
                        :href="exportZoneUrl"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-theme-md text-xs font-semibold border border-theme-border bg-theme-bg-card text-theme-text-secondary hover:text-theme-text-primary hover:bg-theme-bg-surface transition-colors"
                    >
                        <Download class="w-4 h-4" />
                        <span>{{ __('Export .zone') }}</span>
                    </a>

                    <UiButton
                        v-if="zone?.kind === 'Primary'"
                        type="button"
                        variant="secondary"
                        :loading="notifying"
                        :disabled="notifying"
                        @click="triggerZoneNotify"
                    >
                        <Send v-if="!notifying" class="w-4 h-4" />
                        <span>{{ notifying ? __('Sending NOTIFY...') : __('Send Notify') }}</span>
                    </UiButton>

                    <UiButton
                        v-if="zone?.kind === 'Secondary'"
                        type="button"
                        variant="primary"
                        :loading="retrievingAxfr"
                        :disabled="retrievingAxfr"
                        @click="triggerZoneAxfrRetrieve"
                    >
                        <DownloadCloud v-if="!retrievingAxfr" class="w-4 h-4" />
                        <span>{{ retrievingAxfr ? __('Syncing AXFR...') : __('Retrieve / Sync Now') }}</span>
                    </UiButton>

                    <UiButton
                        type="button"
                        variant="secondary"
                        :loading="rectifyingZone"
                        :disabled="rectifyingZone || flushingZoneCache"
                        :title="__('Recalculate DNSSEC ordername and auth fields for this zone (PUT /zones/{id}/rectify)')"
                        @click="triggerZoneRectify"
                    >
                        <RefreshCw v-if="!rectifyingZone" class="w-4 h-4" />
                        <span>{{ rectifyingZone ? __('Rectifying...') : __('Rectify Zone') }}</span>
                    </UiButton>

                    <UiButton
                        type="button"
                        variant="secondary"
                        :loading="flushingZoneCache"
                        :disabled="flushingZoneCache || rectifyingZone"
                        :title="__('Flush PowerDNS query and packet cache for this zone (PUT /cache/flush?domain={zone})')"
                        @click="triggerZoneCacheFlush"
                    >
                        <Eraser v-if="!flushingZoneCache" class="w-4 h-4" />
                        <span>{{ flushingZoneCache ? __('Flushing Cache...') : __('Flush Cache') }}</span>
                    </UiButton>

                    <UiButton
                        type="button"
                        variant="secondary"
                        @click="showSoaDrawer = true"
                    >
                        <SlidersHorizontal class="w-4 h-4" />
                        <span>{{ __('SOA Settings') }}</span>
                    </UiButton>

                    <UiButton
                        v-if="!isReadOnly"
                        type="button"
                        variant="secondary"
                        @click="openCreateModal('EXTEND')"
                    >
                        <Zap class="w-4 h-4" />
                        <span>{{ __('Quick Add (EXTEND)') }}</span>
                    </UiButton>

                    <UiButton
                        v-if="!isReadOnly"
                        type="button"
                        variant="primary"
                        @click="openCreateModal('REPLACE')"
                    >
                        <Plus class="w-4 h-4" />
                        <span>{{ __('Add / Replace RRSet') }}</span>
                    </UiButton>
                </div>
            </template>
        </UiPageHeader>

        <!-- Zone Summary Strip -->
        <div class="p-4 rounded-theme-lg bg-theme-bg-card border border-theme-border flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-wrap items-center gap-2.5">
                <UiBadge :variant="zone?.kind === 'Primary' ? 'primary' : zone?.kind === 'Secondary' ? 'warning' : 'info'">
                    <span>{{ __('Mode:') }} {{ zone?.kind }}</span>
                </UiBadge>

                <UiBadge variant="neutral">
                    <span class="font-mono">{{ __('SOA Serial:') }} {{ zone?.serial || '—' }}</span>
                </UiBadge>

                <UiBadge :variant="zone?.dnssec ? 'success' : 'neutral'">
                    <ShieldCheck class="w-3.5 h-3.5" />
                    <span>{{ zone?.dnssec ? __('DNSSEC Signed') : __('DNSSEC Unsigned') }}</span>
                </UiBadge>

                <UiBadge variant="neutral">
                    <span class="font-mono">SOA-EDIT-API: {{ zone?.soa_edit_api || 'DEFAULT' }}</span>
                </UiBadge>

                <UiBadge :variant="zone?.api_rectify ? 'success' : 'neutral'">
                    <RefreshCw class="w-3 h-3" />
                    <span>{{ zone?.api_rectify ? __('API-RECTIFY: ON') : __('API-RECTIFY: OFF') }}</span>
                </UiBadge>

                <UiBadge v-if="zone?.kind === 'Secondary'" variant="neutral">
                    <Clock class="w-3 h-3" />
                    <span class="font-mono">{{ __('Last Check:') }} {{ formattedLastCheck }}</span>
                </UiBadge>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-theme-accent hover:underline cursor-pointer"
                    @click="activeMainTab = activeMainTab === 'records' ? 'replication' : 'records'"
                >
                    <ArrowRightLeft class="w-3.5 h-3.5" />
                    <span>
                        {{
                            activeMainTab === 'records'
                                ? __('Configure Mode & Transfer')
                                : __('Back to Record Grid')
                        }}
                    </span>
                </button>
                <span class="text-xs text-theme-text-secondary font-mono">
                    {{ rrsets.length }} {{ __('RRSets') }}
                </span>
            </div>
        </div>

        <!-- Primary View Tabs: DNS Records vs Replication / Mode -->
        <UiTabs
            v-model="activeMainTab"
            :tabs="mainSectionTabs"
        />

        <!-- Read-Only Guard Banner for Secondary Zones -->
        <UiAlert v-if="isReadOnly" variant="warning">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-start gap-2.5">
                    <Lock class="w-4 h-4 shrink-0 mt-0.5" />
                    <div class="space-y-1">
                        <div class="font-semibold">
                            {{ __('Secondary Zone — Read-Only Record Grid') }}
                        </div>
                        <p class="text-xs">
                            {{
                                __('This zone replicates records from upstream primary server(s) via AXFR/IXFR. Direct record creation, editing, and deletion are disabled.')
                            }}
                            <span v-if="zone?.masters?.length" class="font-mono font-semibold">
                                ({{ __('Masters:') }} {{ zone.masters.join(', ') }})
                            </span>
                        </p>
                        <div class="flex flex-wrap items-center gap-3 pt-1 text-[11px] font-mono text-theme-text-secondary">
                            <span>{{ __('SOA Serial:') }} {{ zone?.serial || '—' }}</span>
                            <span>•</span>
                            <span>{{ __('Last Check:') }} {{ formattedLastCheck }}</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    <UiButton
                        type="button"
                        variant="primary"
                        size="sm"
                        :loading="retrievingAxfr"
                        :disabled="retrievingAxfr"
                        @click="triggerZoneAxfrRetrieve"
                    >
                        <DownloadCloud v-if="!retrievingAxfr" class="w-3.5 h-3.5" />
                        <span>{{ retrievingAxfr ? __('Syncing AXFR...') : __('Retrieve / Sync Now') }}</span>
                    </UiButton>
                </div>
            </div>
        </UiAlert>

        <!-- Replication / Operating Mode Tab -->
        <PdnsZoneReplicationTab
            v-if="activeMainTab === 'replication'"
            :zone="zone"
            :capabilities="capabilities"
            :notifying="notifying"
            :retrieving="retrievingAxfr"
            @trigger-notify="triggerZoneNotify"
            @trigger-axfr-retrieve="triggerZoneAxfrRetrieve"
        />

        <template v-else>
        <!-- Staged Draft / Bulk Save Queue Banner -->
        <UiCard
            v-if="stagedOperations.length > 0 && !isReadOnly"
            :title="`${__('Staged Bulk RRSet Changes')} (${stagedOperations.length})`"
            :subtitle="__('These queued operations will be dispatched together in a single atomic PowerDNS PATCH transaction.')"
        >
            <template #header>
                <div class="flex items-center gap-2">
                    <UiButton
                        type="button"
                        variant="secondary"
                        size="sm"
                        :disabled="patchSubmitting"
                        @click="clearStagedOperations"
                    >
                        <X class="w-3.5 h-3.5" />
                        <span>{{ __('Clear Drafts') }}</span>
                    </UiButton>

                    <UiButton
                        type="button"
                        variant="primary"
                        size="sm"
                        :loading="patchSubmitting"
                        :disabled="patchSubmitting"
                        @click="applyAllStagedOperations"
                    >
                        <Save class="w-3.5 h-3.5" />
                        <span>
                            {{
                                patchSubmitting
                                    ? __('Committing Batch PATCH...')
                                    : `${__('Commit All Staged Changes')} (${stagedOperations.length})`
                            }}
                        </span>
                    </UiButton>
                </div>
            </template>

            <div class="divide-y divide-theme-border-subtle">
                <div
                    v-for="(op, idx) in stagedOperations"
                    :key="op._uid || idx"
                    class="py-2.5 flex flex-wrap items-center justify-between gap-3 text-xs"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <UiBadge
                            :variant="
                                op.changetype === 'DELETE' || op.changetype === 'PRUNE'
                                    ? 'danger'
                                    : op.changetype === 'EXTEND'
                                      ? 'info'
                                      : 'primary'
                            "
                        >
                            <span class="font-mono">{{ op.changetype }}</span>
                        </UiBadge>

                        <span class="font-mono font-semibold text-theme-text-primary">
                            {{ op.canonical_preview || op.name }}
                        </span>

                        <UiBadge variant="neutral">
                            <span class="font-mono">{{ op.type }}</span>
                        </UiBadge>

                        <span v-if="op.ttl && op.changetype !== 'DELETE'" class="font-mono text-theme-text-muted">
                            TTL {{ op.ttl }}s
                        </span>

                        <span
                            v-if="op.records?.length"
                            class="font-mono text-theme-text-secondary truncate max-w-md"
                        >
                            →
                            {{
                                op.records
                                    .map((r: Record<string, any>) => r.content || r.target || r.value || '')
                                    .join(', ')
                            }}
                        </span>
                    </div>

                    <button
                        type="button"
                        class="text-theme-text-muted hover:text-theme-danger cursor-pointer p-1"
                        :title="__('Remove from staged batch')"
                        @click="removeStagedOperation(idx)"
                    >
                        <X class="w-4 h-4" />
                    </button>
                </div>
            </div>
        </UiCard>

        <!-- Record Type Filter Tabs -->
        <UiTabs
            v-model="activeTypeTab"
            :tabs="typeTabs"
        />

        <!-- Searchable / Filterable RRSet Data Table -->
        <UiDataTable
            :headers="tableHeaders"
            :items-count="filteredRrsets.length"
            :empty-message="__('No DNS record sets (RRSets) match your current filter.')"
        >
            <template #toolbar>
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 w-full">
                    <div class="text-xs font-semibold text-theme-text-secondary">
                        {{ __('Showing') }} {{ filteredRrsets.length }} {{ __('of') }} {{ rrsets.length }} {{ __('RRSets') }}
                    </div>
                    <div class="w-full sm:w-80">
                        <UiInput
                            id="pdns-rrset-filter"
                            v-model="searchFilter"
                            :placeholder="__('Filter by host, IP, target, TXT value, or comment...')"
                        >
                            <template #prefix>
                                <Search class="w-4 h-4 text-theme-text-muted" />
                            </template>
                        </UiInput>
                    </div>
                </div>
            </template>

            <tr
                v-for="rrset in filteredRrsets"
                :key="rrset.id"
                class="hover:bg-theme-bg-surface/40 transition-colors align-top"
            >
                <!-- Host / Owner Name -->
                <td class="py-3.5 px-4">
                    <div class="flex flex-col gap-0.5">
                        <div class="flex items-center gap-2">
                            <span
                                class="px-2 py-0.5 rounded-theme-sm font-mono text-xs font-bold"
                                :class="
                                    rrset.is_apex
                                        ? 'bg-theme-accent/15 text-theme-accent border border-theme-accent/30'
                                        : 'bg-theme-bg-surface text-theme-text-primary border border-theme-border-subtle'
                                "
                            >
                                {{ rrset.relative_name }}
                            </span>
                            <UiBadge v-if="rrset.is_idn" variant="info">
                                IDN
                            </UiBadge>
                        </div>
                        <span class="font-mono text-[11px] text-theme-text-muted">
                            {{ rrset.name }}
                        </span>
                        <span
                            v-if="rrset.is_idn"
                            class="text-xs text-theme-text-secondary"
                        >
                            {{ rrset.display_name }}
                        </span>
                    </div>
                </td>

                <!-- Record Type -->
                <td class="py-3.5 px-4">
                    <UiBadge :variant="typeBadgeVariant(rrset.type)">
                        <span class="font-mono font-bold">{{ rrset.type }}</span>
                    </UiBadge>
                </td>

                <!-- TTL -->
                <td class="py-3.5 px-4 font-mono text-xs text-theme-text-secondary whitespace-nowrap">
                    {{ formatTtl(rrset.ttl) }}
                </td>

                <!-- Records List inside RRSet -->
                <td class="py-3.5 px-4">
                    <div class="space-y-2">
                        <div
                            v-for="(rec, recIdx) in rrset.records"
                            :key="recIdx"
                            class="flex flex-wrap items-center justify-between gap-2 p-2 rounded-theme-sm bg-theme-bg-surface/70 border border-theme-border-subtle"
                        >
                            <div class="flex flex-wrap items-center gap-2 min-w-0 flex-1">
                                <!-- Structured pill badges for MX / SRV / CAA / TXT -->
                                <UiBadge v-if="rrset.type === 'MX' && rec.parsed?.priority !== undefined" variant="neutral">
                                    <span class="font-mono text-[11px]">Prio {{ rec.parsed.priority }}</span>
                                </UiBadge>

                                <UiBadge v-else-if="rrset.type === 'SRV' && rec.parsed?.port !== undefined" variant="neutral">
                                    <span class="font-mono text-[11px]">
                                        {{ rec.parsed.priority }}/{{ rec.parsed.weight }} :{{ rec.parsed.port }}
                                    </span>
                                </UiBadge>

                                <UiBadge v-else-if="rrset.type === 'CAA' && rec.parsed?.tag" variant="neutral">
                                    <span class="font-mono text-[11px]">
                                        flag={{ rec.parsed.flags }} {{ rec.parsed.tag }}
                                    </span>
                                </UiBadge>

                                <span
                                    class="font-mono text-xs break-all"
                                    :class="rec.disabled ? 'line-through text-theme-text-muted' : 'text-theme-text-primary'"
                                >
                                    {{ rec.content }}
                                </span>

                                <UiBadge
                                    v-if="rrset.type === 'TXT' && rec.parsed?.chunk_count > 1"
                                    variant="info"
                                >
                                    <span class="font-mono text-[10px]">{{ rec.parsed.chunk_count }}×255B chunks</span>
                                </UiBadge>
                            </div>

                            <!-- Per-record status + one-click Enable/Disable & Prune controls -->
                            <div class="flex items-center gap-1.5 shrink-0">
                                <button
                                    v-if="!isReadOnly && rrset.type !== 'SOA'"
                                    type="button"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-theme-sm text-[11px] font-medium border transition-colors cursor-pointer"
                                    :class="
                                        rec.disabled
                                            ? 'border-theme-warning/40 bg-theme-warning/10 text-theme-warning hover:bg-theme-warning/20'
                                            : 'border-theme-border bg-theme-bg-card text-theme-text-secondary hover:text-theme-text-primary'
                                    "
                                    :title="rec.disabled ? __('Click to Enable record') : __('Click to Disable record')"
                                    :disabled="patchSubmitting"
                                    @click="toggleRecordDisabledNow(rrset, recIdx)"
                                >
                                    <PauseCircle v-if="rec.disabled" class="w-3 h-3" />
                                    <CheckCircle2 v-else class="w-3 h-3 text-theme-success" />
                                    <span>{{ rec.disabled ? __('Disabled') : __('Enabled') }}</span>
                                </button>
                                <UiBadge v-else-if="rec.disabled" variant="warning">
                                    {{ __('Disabled') }}
                                </UiBadge>

                                <!-- One-click PRUNE when RRSet has multiple records -->
                                <button
                                    v-if="!isReadOnly && rrset.type !== 'SOA' && rrset.records.length > 1"
                                    type="button"
                                    class="p-1 rounded-theme-sm text-theme-text-muted hover:text-theme-danger hover:bg-theme-bg-card transition-colors cursor-pointer"
                                    :title="__('Prune this single record from RRSet (PRUNE)')"
                                    :disabled="patchSubmitting"
                                    @click="pruneSingleRecordNow(rrset, rec)"
                                >
                                    <Scissors class="w-3.5 h-3.5" />
                                </button>
                            </div>
                        </div>
                    </div>
                </td>

                <!-- RRSet Comment -->
                <td class="py-3.5 px-4 text-xs text-theme-text-secondary max-w-xs">
                    <div v-if="rrset.primary_comment" class="flex items-start gap-1.5">
                        <MessageSquare class="w-3.5 h-3.5 text-theme-text-muted shrink-0 mt-0.5" />
                        <span class="break-words">{{ rrset.primary_comment }}</span>
                    </div>
                    <span v-else class="text-theme-text-muted">—</span>
                </td>

                <!-- Actions -->
                <td class="py-3.5 px-4 text-right whitespace-nowrap">
                    <div v-if="!isReadOnly" class="inline-flex items-center justify-end gap-1.5">
                        <UiButton
                            v-if="rrset.type === 'SOA'"
                            type="button"
                            variant="secondary"
                            size="sm"
                            @click="showSoaDrawer = true"
                        >
                            <SlidersHorizontal class="w-3.5 h-3.5" />
                            <span>{{ __('Edit SOA') }}</span>
                        </UiButton>

                        <template v-else>
                            <UiButton
                                type="button"
                                variant="secondary"
                                size="sm"
                                @click="openEditModal(rrset)"
                            >
                                <Edit3 class="w-3.5 h-3.5" />
                                <span>{{ __('Edit') }}</span>
                            </UiButton>

                            <UiButton
                                type="button"
                                variant="secondary"
                                size="sm"
                                :title="__('Queue RRSet deletion in Bulk Draft')"
                                @click="stageDeleteRrset(rrset)"
                            >
                                <Layers class="w-3.5 h-3.5" />
                            </UiButton>

                            <UiButton
                                type="button"
                                variant="danger"
                                size="sm"
                                :disabled="patchSubmitting"
                                :title="__('Delete entire RRSet immediately (DELETE)')"
                                @click="deleteRrsetNow(rrset)"
                            >
                                <Trash2 class="w-3.5 h-3.5" />
                            </UiButton>
                        </template>
                    </div>
                    <span v-else class="text-xs text-theme-text-muted font-mono">
                        {{ __('Read-only') }}
                    </span>
                </td>
            </tr>
        </UiDataTable>
        </template>

        <!-- Type-Aware Add / Edit RRSet Modal -->
        <PdnsRrsetModal
            :show="showRrsetModal"
            :zone="zone"
            :editing-rrset="editingRrset"
            :initial-changetype="modalInitialChangetype"
            :editable-record-types="editableRecordTypes"
            :ttl-presets="ttlPresets"
            :submitting="patchSubmitting"
            @close="showRrsetModal = false"
            @save-now="handleSaveSingleNow"
            @stage-operation="stageOperation"
        />

        <!-- Dedicated Zone SOA Settings Drawer / Modal -->
        <PdnsSoaSettingsDrawer
            :show="showSoaDrawer"
            :zone="zone"
            :ttl-presets="ttlPresets"
            :is-read-only="isReadOnly"
            @close="showSoaDrawer = false"
        />
    </div>
</template>
