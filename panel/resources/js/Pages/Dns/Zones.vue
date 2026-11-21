<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Globe,
    Plus,
    Search,
    Download,
    Trash2,
    ShieldCheck,
    Network,
    Settings,
    FileCode2,
    RefreshCw,
    AlertTriangle,
    ListFilter,
} from '@lucide/vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiTabs from '@shared/Ui/UiTabs.vue';
import UiDataTable from '@shared/Ui/UiDataTable.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
import PdnsCreateZoneModal from '@domains/Dns/Components/PdnsCreateZoneModal.vue';
import PdnsDeleteZoneModal from '@domains/Dns/Components/PdnsDeleteZoneModal.vue';
import PdnsSearchResultsCard from '@domains/Dns/Components/PdnsSearchResultsCard.vue';

const props = defineProps({
    zones: {
        type: Array as () => Array<Record<string, any>>,
        default: () => [],
    },
    searchQuery: {
        type: String,
        default: '',
    },
    searchResults: {
        type: Array as () => Array<Record<string, any>>,
        default: () => [],
    },
    apiError: {
        type: String,
        default: null,
    },
    defaultNameservers: {
        type: Array as () => string[],
        default: () => [],
    },
    defaultNameserverDetails: {
        type: Array as () => Array<{
            hostname: string;
            ipv4: string;
            ipv6: string;
            base_domain?: string;
        }>,
        default: () => [],
    },
    zoneTemplates: {
        type: Array as () => Array<Record<string, any>>,
        default: () => [],
    },
    capabilities: {
        type: Object,
        default: () => ({
            has_api_key: false,
            primary_enabled: true,
            secondary_enabled: false,
            autosecondary_enabled: false,
        }),
    },
});

const activeTab = ref('all');
const localFilter = ref('');
const globalSearchInput = ref(props.searchQuery || '');
const searchingGlobal = ref(false);
const showCreateModal = ref(false);
const zoneToDelete = ref<Record<string, any> | null>(null);

const filterTabs = computed(() => {
    const allCount = props.zones.length;
    const forwardCount = props.zones.filter((z) => !z.is_reverse).length;
    const reverseCount = props.zones.filter((z) => z.is_reverse).length;
    const primaryCount = props.zones.filter((z) => z.kind === 'Primary').length;
    const secondaryCount = props.zones.filter((z) => z.kind === 'Secondary').length;
    const nativeCount = props.zones.filter((z) => z.kind === 'Native').length;

    return [
        { id: 'all', label: `All (${allCount})` },
        { id: 'forward', label: `Forward (${forwardCount})` },
        { id: 'reverse', label: `Reverse (${reverseCount})` },
        { id: 'Primary', label: `Primary (${primaryCount})` },
        { id: 'Secondary', label: `Secondary (${secondaryCount})` },
        { id: 'Native', label: `Native (${nativeCount})` },
    ];
});

const filteredZones = computed(() => {
    const query = localFilter.value.trim().toLowerCase();

    return props.zones.filter((zone) => {
        if (activeTab.value === 'forward' && zone.is_reverse) {
            return false;
        }
        if (activeTab.value === 'reverse' && !zone.is_reverse) {
            return false;
        }
        if (
            ['Primary', 'Secondary', 'Native'].includes(activeTab.value) &&
            zone.kind !== activeTab.value
        ) {
            return false;
        }

        if (!query) {
            return true;
        }

        const nameMatch = String(zone.name || '').toLowerCase().includes(query);
        const displayMatch = String(zone.display_name || '').toLowerCase().includes(query);
        const serialMatch = String(zone.serial || '').includes(query);

        return nameMatch || displayMatch || serialMatch;
    });
});

const tableHeaders = [
    { label: 'Zone Name (FQDN)' },
    { label: 'Category' },
    { label: 'Mode (Kind)' },
    { label: 'SOA Serial' },
    { label: 'DNSSEC' },
    { label: 'Rectify / SOA-EDIT' },
    { label: 'Actions', class: 'text-right' },
];

const kindBadgeVariant = (kind: string) => {
    switch (kind) {
        case 'Primary':
            return 'primary';
        case 'Secondary':
            return 'warning';
        default:
            return 'info';
    }
};

const runGlobalSearch = () => {
    const trimmed = globalSearchInput.value.trim();
    searchingGlobal.value = true;
    router.get(
        '/dns/zones',
        trimmed ? { q: trimmed } : {},
        {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => {
                searchingGlobal.value = false;
            },
        }
    );
};

const clearGlobalSearch = () => {
    globalSearchInput.value = '';
    runGlobalSearch();
};

const zoneRecordsUrl = (zone: Record<string, any>) => {
    const encodedId = encodeURIComponent(String(zone.id || zone.name));
    return `/dns/zones/${encodedId}/records`;
};

const exportZoneUrl = (zone: Record<string, any>) => {
    const encodedId = encodeURIComponent(String(zone.id || zone.name));
    return `/dns/zones/${encodedId}/export`;
};

const openDeleteModal = (zone: Record<string, any>) => {
    zoneToDelete.value = zone;
};

const closeDeleteModal = () => {
    zoneToDelete.value = null;
};
</script>

<template>
    <Head :title="__('PowerDNS Zone Manager')" />

    <div class="max-w-6xl mx-auto space-y-6">
        <UiPageHeader
            :title="__('Authoritative DNS Zone Manager')"
            :subtitle="__('Manage forward domains, IPv4/IPv6 reverse PTR zones, cross-zone record search, and BIND zone exports.')"
        >
            <template #icon>
                <Globe class="w-6 h-6 text-theme-accent" />
            </template>
            <template #actions>
                <div class="flex flex-wrap items-center gap-2.5">
                    <Link
                        href="/dns/templates"
                        class="inline-flex items-center gap-2 px-3.5 py-2 rounded-theme-md text-xs font-semibold border border-theme-border bg-theme-bg-card text-theme-text-secondary hover:text-theme-text-primary hover:bg-theme-bg-surface transition-colors"
                    >
                        <FileCode2 class="w-4 h-4" />
                        <span>{{ __('Zone Templates & NS') }}</span>
                    </Link>

                    <Link
                        href="/dns/settings"
                        class="inline-flex items-center gap-2 px-3.5 py-2 rounded-theme-md text-xs font-semibold border border-theme-border bg-theme-bg-card text-theme-text-secondary hover:text-theme-text-primary hover:bg-theme-bg-surface transition-colors"
                    >
                        <Settings class="w-4 h-4" />
                        <span>{{ __('Daemon & API Settings') }}</span>
                    </Link>

                    <UiButton
                        type="button"
                        variant="primary"
                        @click="showCreateModal = true"
                    >
                        <Plus class="w-4 h-4" />
                        <span>{{ __('Create DNS Zone') }}</span>
                    </UiButton>
                </div>
            </template>
        </UiPageHeader>

        <!-- API Connection Error Alert -->
        <UiAlert v-if="apiError" variant="warning">
            <div class="flex items-start justify-between gap-4">
                <div class="space-y-1">
                    <div class="font-semibold flex items-center gap-2">
                        <AlertTriangle class="w-4 h-4" />
                        <span>{{ __('PowerDNS HTTP API Unreachable or Unconfigured') }}</span>
                    </div>
                    <p class="text-xs break-all">
                        {{ apiError }}
                    </p>
                </div>
                <Link
                    href="/dns/settings"
                    class="shrink-0 px-3 py-1.5 rounded-theme-sm text-xs font-semibold bg-theme-bg-card border border-theme-border text-theme-text-primary hover:bg-theme-bg-surface"
                >
                    {{ __('Configure API') }}
                </Link>
            </div>
        </UiAlert>

        <!-- Cross-Zone PowerDNS /search-data Bar -->
        <div class="p-4 rounded-theme-lg bg-theme-bg-card border border-theme-border shadow-xs">
            <form @submit.prevent="runGlobalSearch" class="flex flex-col sm:flex-row items-stretch sm:items-end gap-3">
                <div class="flex-1">
                    <UiInput
                        id="pdns-global-search"
                        v-model="globalSearchInput"
                        :label="__('Global Cross-Zone Record & Comment Search (/search-data)')"
                        :placeholder="__('Search IP address, hostname, TXT token, or comment across all zones...')"
                    />
                </div>
                <div class="flex items-center gap-2">
                    <UiButton
                        type="submit"
                        variant="primary"
                        :loading="searchingGlobal"
                        :disabled="searchingGlobal"
                    >
                        <Search v-if="!searchingGlobal" class="w-4 h-4" />
                        <span>{{ searchingGlobal ? __('Searching...') : __('Search Records') }}</span>
                    </UiButton>
                    <UiButton
                        v-if="searchQuery"
                        type="button"
                        variant="secondary"
                        @click="clearGlobalSearch"
                    >
                        {{ __('Reset') }}
                    </UiButton>
                </div>
            </form>
        </div>

        <!-- Cross-Zone Search Results Card -->
        <PdnsSearchResultsCard
            v-if="searchQuery"
            :query="searchQuery"
            :results="searchResults"
            @clear="clearGlobalSearch"
        />

        <!-- Filter Tabs (All, Forward, Reverse, Primary, Secondary, Native) -->
        <UiTabs
            v-model="activeTab"
            :tabs="filterTabs"
        />

        <!-- Zones Table -->
        <UiDataTable
            :headers="tableHeaders"
            :items-count="filteredZones.length"
            :empty-message="__('No authoritative DNS zones found matching the selected filter.')"
        >
            <template #toolbar>
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 w-full">
                    <div class="text-xs font-semibold text-theme-text-secondary">
                        {{ __('Showing') }} {{ filteredZones.length }} {{ __('of') }} {{ zones.length }} {{ __('zones') }}
                    </div>
                    <div class="w-full sm:w-72">
                        <UiInput
                            id="pdns-zone-local-filter"
                            v-model="localFilter"
                            :placeholder="__('Filter listed zones by name or serial...')"
                        />
                    </div>
                </div>
            </template>

            <tr
                v-for="zone in filteredZones"
                :key="zone.id"
                class="hover:bg-theme-bg-surface/40 transition-colors"
            >
                <!-- Zone Name + IDN badge -->
                <td class="py-3.5 px-4">
                    <div class="flex flex-col">
                        <div class="flex items-center gap-2">
                            <Link
                                :href="zoneRecordsUrl(zone)"
                                class="font-mono font-semibold text-theme-text-primary hover:text-theme-accent hover:underline transition-colors"
                            >
                                {{ zone.name }}
                            </Link>
                            <UiBadge v-if="zone.is_idn" variant="info">
                                IDN
                            </UiBadge>
                        </div>
                        <span
                            v-if="zone.is_idn"
                            class="text-xs text-theme-text-secondary mt-0.5"
                        >
                            {{ zone.display_name }}
                        </span>
                        <span
                            v-if="zone.kind === 'Secondary' && zone.masters?.length"
                            class="text-[11px] text-theme-text-muted font-mono mt-0.5"
                        >
                            {{ __('Masters:') }} {{ zone.masters.join(', ') }}
                        </span>
                    </div>
                </td>

                <!-- Category (Forward vs Reverse IPv4/IPv6) -->
                <td class="py-3.5 px-4">
                    <UiBadge v-if="zone.is_reverse" variant="neutral">
                        <Network class="w-3.5 h-3.5" />
                        <span>
                            {{ zone.reverse_type === 'ipv6' ? __('Reverse IPv6 (ip6.arpa)') : __('Reverse IPv4 (in-addr.arpa)') }}
                        </span>
                    </UiBadge>
                    <UiBadge v-else variant="neutral">
                        <Globe class="w-3.5 h-3.5" />
                        <span>{{ __('Forward') }}</span>
                    </UiBadge>
                </td>

                <!-- Mode (Kind) -->
                <td class="py-3.5 px-4">
                    <UiBadge :variant="kindBadgeVariant(zone.kind)">
                        <span>{{ zone.kind }}</span>
                    </UiBadge>
                </td>

                <!-- Serial -->
                <td class="py-3.5 px-4 font-mono text-xs text-theme-text-primary">
                    {{ zone.serial || '—' }}
                </td>

                <!-- DNSSEC Status -->
                <td class="py-3.5 px-4">
                    <UiBadge :variant="zone.dnssec ? 'success' : 'neutral'">
                        <ShieldCheck class="w-3.5 h-3.5" />
                        <span>{{ zone.dnssec ? __('Signed') : __('Unsigned') }}</span>
                    </UiBadge>
                </td>

                <!-- SOA-EDIT-API & Rectify metadata -->
                <td class="py-3.5 px-4">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <UiBadge variant="neutral">
                            <span class="font-mono text-[11px]">SOA: {{ zone.soa_edit_api || 'DEFAULT' }}</span>
                        </UiBadge>
                        <UiBadge :variant="zone.api_rectify ? 'success' : 'neutral'">
                            <RefreshCw class="w-3 h-3" />
                            <span>{{ zone.api_rectify ? __('Rectify ON') : __('Rectify OFF') }}</span>
                        </UiBadge>
                    </div>
                </td>

                <!-- Actions: Manage Records, Export .zone & Delete -->
                <td class="py-3.5 px-4 text-right">
                    <div class="inline-flex items-center justify-end gap-2">
                        <Link
                            :href="zoneRecordsUrl(zone)"
                            class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-theme-md text-xs font-semibold border border-theme-accent/40 bg-theme-accent/10 text-theme-accent hover:bg-theme-accent/20 transition-colors"
                            :title="__('Open DNS Record Editor & SOA Settings')"
                        >
                            <ListFilter class="w-3.5 h-3.5" />
                            <span>{{ __('Records') }}</span>
                        </Link>

                        <a
                            :href="exportZoneUrl(zone)"
                            class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-theme-md text-xs font-medium border border-theme-border bg-theme-bg-surface text-theme-text-primary hover:bg-theme-bg-elevated transition-colors"
                            :title="__('Export BIND .zone file')"
                        >
                            <Download class="w-3.5 h-3.5" />
                            <span>{{ __('Export .zone') }}</span>
                        </a>

                        <UiButton
                            type="button"
                            variant="danger"
                            size="sm"
                            @click="openDeleteModal(zone)"
                        >
                            <Trash2 class="w-3.5 h-3.5" />
                            <span>{{ __('Delete') }}</span>
                        </UiButton>
                    </div>
                </td>
            </tr>
        </UiDataTable>

        <!-- Create Zone Modal (Forward + Reverse IPv4/IPv6 Helper + Zone Template Selector) -->
        <PdnsCreateZoneModal
            :show="showCreateModal"
            :default-nameservers="defaultNameservers"
            :default-nameserver-details="defaultNameserverDetails"
            :zone-templates="zoneTemplates"
            :capabilities="capabilities"
            @close="showCreateModal = false"
        />

        <!-- Delete Zone Confirmation Modal -->
        <PdnsDeleteZoneModal
            :show="Boolean(zoneToDelete)"
            :zone="zoneToDelete"
            @close="closeDeleteModal"
        />
    </div>
</template>
