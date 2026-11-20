<script setup lang="ts">
import { shallowRef, ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Search, FolderKey, X } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import FtpConnectionBanner from '@domains/WebServer/Components/Ftp/FtpConnectionBanner.vue';
import FtpCreateCard from '@domains/WebServer/Components/Ftp/FtpCreateCard.vue';
import FtpAccountCard from '@domains/WebServer/Components/Ftp/FtpAccountCard.vue';
import FtpEditModal from '@domains/WebServer/Components/Ftp/FtpEditModal.vue';
import FtpPasswordModal from '@domains/WebServer/Components/Ftp/FtpPasswordModal.vue';

const props = defineProps({
    ftpUsers: {
        type: Object,
        required: true,
    },
    domains: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({ domain_id: null, search: '' }),
    },
    connectionInfo: {
        type: Object,
        default: () => ({ host: '127.0.0.1', port: 21 }),
    },
});

const domainFilter = ref(props.filters?.domain_id ? String(props.filters.domain_id) : '');
const searchQuery = ref(props.filters?.search || '');
let searchDebounce: ReturnType<typeof setTimeout> | null = null;

const selectedAccount = shallowRef<any>(null);
const showEditModal = shallowRef(false);
const showPasswordModal = shallowRef(false);

const domainFilterOptions = computed(() => [
    { value: '', label: 'All Domains' },
    ...(props.domains || []).map((d: any) => ({
        value: String(d.id),
        label: d.domain,
    })),
]);

const hasActiveFilters = computed(() => Boolean(domainFilter.value || searchQuery.value.trim()));

const applyFilters = () => {
    const query: Record<string, string> = {};
    if (domainFilter.value) query.domain_id = domainFilter.value;
    if (searchQuery.value.trim()) query.search = searchQuery.value.trim();

    router.get('/ftp', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

watch(domainFilter, () => {
    applyFilters();
});

watch(searchQuery, () => {
    if (searchDebounce) clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        applyFilters();
    }, 300);
});

const clearFilters = () => {
    domainFilter.value = '';
    searchQuery.value = '';
    applyFilters();
};

const openEditModal = (account: any) => {
    selectedAccount.value = account;
    showEditModal.value = true;
};

const openPasswordModal = (account: any) => {
    selectedAccount.value = account;
    showPasswordModal.value = true;
};

const handleDelete = async (account: any) => {
    const confirmed = await confirmDialog({
        title: 'Delete FTP Account',
        message: `Are you sure you want to delete FTP account '${account.username}'? This removes Pure-FTPd access immediately.`,
        confirmText: 'Delete',
        variant: 'danger',
    });

    if (confirmed) {
        router.delete(`/ftp/${account.id}`, {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head title="FTP Accounts - LiteControl" />

    <div class="space-y-6">
        <!-- Section A: Header & Connection Info Bar -->
        <UiPageHeader
            title="FTP Accounts"
            subtitle="Pure-FTPd Virtual User Management"
        />

        <FtpConnectionBanner :connection-info="connectionInfo" />

        <!-- Section B: Create FTP User Card -->
        <FtpCreateCard :domains="domains" />

        <!-- Section C: Filter Bar -->
        <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-4 flex flex-col sm:flex-row items-stretch sm:items-end gap-3">
            <div class="w-full sm:w-64">
                <UiSelect
                    id="filter_ftp_domain"
                    v-model="domainFilter"
                    label="Filter by Domain"
                    :options="domainFilterOptions"
                />
            </div>

            <div class="flex-1">
                <UiInput
                    id="filter_ftp_search"
                    v-model="searchQuery"
                    type="text"
                    label="Search Username"
                    placeholder="Type username to filter..."
                    @keydown.enter.prevent="applyFilters"
                />
            </div>

            <div v-if="hasActiveFilters" class="shrink-0">
                <UiButton type="button" variant="secondary" @click="clearFilters">
                    <X class="w-3.5 h-3.5 mr-1 inline" />
                    Reset
                </UiButton>
            </div>
        </div>

        <!-- Section D: FTP Users Card Grid & Pagination -->
        <div class="space-y-4">
            <div class="flex items-center justify-between text-xs text-theme-text-muted px-1">
                <span class="uppercase tracking-wider font-semibold text-theme-text-secondary">
                    FTP Accounts ({{ ftpUsers.total ?? ftpUsers.data?.length ?? 0 }})
                </span>
                <span v-if="ftpUsers.from && ftpUsers.to">
                    Showing {{ ftpUsers.from }}–{{ ftpUsers.to }} of {{ ftpUsers.total }}
                </span>
            </div>

            <div
                v-if="ftpUsers.data && ftpUsers.data.length > 0"
                class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4"
            >
                <FtpAccountCard
                    v-for="account in ftpUsers.data"
                    :key="account.id"
                    :account="account"
                    @change-password="openPasswordModal"
                    @edit="openEditModal"
                    @delete="handleDelete"
                />
            </div>

            <!-- Empty State -->
            <div
                v-else
                class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-10 text-center space-y-2"
            >
                <FolderKey class="w-8 h-8 text-theme-text-muted mx-auto" />
                <p class="text-sm font-semibold text-theme-text-primary">
                    {{ hasActiveFilters ? 'No matching FTP accounts found' : 'No FTP accounts provisioned yet' }}
                </p>
                <p class="text-xs text-theme-text-muted">
                    {{
                        hasActiveFilters
                            ? 'Try clearing your domain or username search filters.'
                            : 'Use the Create FTP Account card above to provision your first Pure-FTPd user.'
                    }}
                </p>
            </div>

            <!-- Pagination -->
            <div
                v-if="ftpUsers.links && ftpUsers.links.length > 3"
                class="flex flex-wrap items-center justify-center gap-1.5 pt-2"
            >
                <template v-for="(link, idx) in ftpUsers.links" :key="idx">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        preserve-state
                        class="px-3 py-1.5 rounded-theme-md text-xs border transition-colors"
                        :class="
                            link.active
                                ? 'bg-theme-accent text-white border-theme-accent font-semibold'
                                : 'bg-theme-bg-card text-theme-text-secondary border-theme-border hover:bg-theme-bg-surface hover:text-theme-text-primary'
                        "
                        v-html="link.label"
                    />
                    <span
                        v-else
                        class="px-3 py-1.5 rounded-theme-md text-xs border border-theme-border text-theme-text-muted opacity-50 cursor-not-allowed"
                        v-html="link.label"
                    />
                </template>
            </div>
        </div>

        <!-- Modals -->
        <FtpEditModal
            :show="showEditModal"
            :account="selectedAccount"
            @close="showEditModal = false"
        />

        <FtpPasswordModal
            :show="showPasswordModal"
            :account="selectedAccount"
            @close="showPasswordModal = false"
        />
    </div>
</template>
