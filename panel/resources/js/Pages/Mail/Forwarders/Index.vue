<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
import DomainSelector from '@domains/Mail/Components/DomainSelector.vue';
import StatusBadge from '@domains/Mail/Components/StatusBadge.vue';
import ConfirmActionModal from '@domains/Mail/Components/ConfirmActionModal.vue';
import CreateMailAliasModal from '@domains/Mail/Components/CreateMailAliasModal.vue';

const props = defineProps({
    domains: {
        type: Array,
        default: () => [],
    },
    selected_domain: {
        type: Object,
        default: null,
    },
    aliases: {
        type: Object,
        default: () => ({ data: [], links: [], total: 0 }),
    },
    fanout_limit: {
        type: Number,
        default: 5,
    },
    source_counts: {
        type: Object,
        default: () => ({}),
    },
    type_filter: {
        type: String,
        default: 'all',
    },
    mail_core_active: {
        type: Boolean,
        default: true,
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success || '');
const flashError = computed(() => page.props.flash?.error || '');

const showCreateModal = ref(false);
const deletingAlias = ref(null);
const deleteProcessing = ref(false);
const togglingId = ref(null);

const selectedDomainName = computed(() => props.selected_domain?.domain || '');
const aliasRows = computed(() => props.aliases?.data ?? []);

const filterTabs = [
    { key: 'all', label: 'All' },
    { key: 'forwarders', label: 'Forwarders' },
    { key: 'catch_all', label: 'Catch-Alls' },
];

const selectTypeTab = (typeKey) => {
    const query = {};
    if (selectedDomainName.value) {
        query.domain = selectedDomainName.value;
    }
    if (typeKey !== 'all') {
        query.type = typeKey;
    }
    router.get('/email/forwarders', query, {
        preserveState: true,
        replace: true,
    });
};

const getSourceCount = (sourceEmail) => Number(props.source_counts?.[sourceEmail] ?? 1);

const toggleActive = (alias) => {
    togglingId.value = alias.id;
    router.put(
        `/email/forwarders/${alias.id}`,
        { active: !alias.active },
        {
            preserveScroll: true,
            onFinish: () => {
                togglingId.value = null;
            },
        },
    );
};

const confirmDelete = () => {
    if (!deletingAlias.value) {
        return;
    }

    deleteProcessing.value = true;
    router.delete(`/email/forwarders/${deletingAlias.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleteProcessing.value = false;
            deletingAlias.value = null;
        },
    });
};
</script>

<template>
    <Head title="Forwarders & Catch-Alls" />

    <div class="space-y-6">
        <UiPageHeader
            title="Forwarders & Catch-Alls"
            subtitle="Route address aliases and domain catch-alls to local or external destinations."
        >
            <template #actions>
                <span :title="domains.length === 0 ? 'Enable a mail domain first before creating forwarders.' : ''">
                    <UiButton
                        variant="primary"
                        :disabled="domains.length === 0"
                        @click="showCreateModal = true"
                    >
                        Create Forwarder
                    </UiButton>
                </span>
            </template>
        </UiPageHeader>

        <div aria-live="polite" class="space-y-3">
            <UiAlert v-if="flashSuccess" variant="success">
                {{ flashSuccess }}
            </UiAlert>
            <UiAlert v-if="flashError" variant="danger">
                {{ flashError }}
            </UiAlert>
            <UiAlert v-if="!mail_core_active" variant="warning">
                The Mail Core module is not active. Install and enable it in
                <Link href="/modules" class="underline font-medium">Module Manager</Link>
                before routing live mail traffic.
            </UiAlert>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
            <div class="w-full sm:max-w-xs">
                <DomainSelector
                    :domains="domains"
                    :model-value="selectedDomainName"
                    route-url="/email/forwarders"
                    label="Filter by Domain"
                    include-all-option
                    all-label="All Mail Domains"
                />
            </div>

            <div
                role="tablist"
                aria-label="Filter forwarders by type"
                class="inline-flex items-center rounded-theme-md border border-theme-border-subtle bg-theme-bg-surface p-1 gap-1"
            >
                <button
                    v-for="tab in filterTabs"
                    :key="tab.key"
                    type="button"
                    role="tab"
                    :aria-selected="type_filter === tab.key"
                    class="px-3 py-1.5 text-xs font-medium rounded-theme-sm transition-colors"
                    :class="
                        type_filter === tab.key
                            ? 'bg-theme-accent text-white'
                            : 'text-theme-text-secondary hover:text-theme-text-primary'
                    "
                    @click="selectTypeTab(tab.key)"
                >
                    {{ tab.label }}
                </button>
            </div>
        </div>

        <UiCard>
            <div v-if="domains.length === 0" class="py-10 text-center space-y-3">
                <p class="text-sm font-medium text-theme-text-primary">
                    No mail domains enabled yet.
                </p>
                <p class="text-xs text-theme-text-muted max-w-md mx-auto">
                    Enable a domain in Mail Domains before creating forwarders or catch-alls.
                </p>
                <div class="pt-2">
                    <Link
                        href="/email/domains"
                        class="inline-flex items-center px-3.5 py-2 text-xs font-medium rounded-theme-md bg-theme-accent text-white hover:opacity-90 transition-opacity"
                    >
                        Go to Mail Domains
                    </Link>
                </div>
            </div>

            <div v-else-if="aliasRows.length === 0" class="py-10 text-center space-y-3">
                <p class="text-sm font-medium text-theme-text-primary">
                    No forwarders found{{ selected_domain ? ` for ${selected_domain.domain}` : '' }}.
                </p>
                <p class="text-xs text-theme-text-muted max-w-md mx-auto">
                    Create an address forwarder or domain catch-all to route incoming email.
                </p>
                <div class="pt-2">
                    <UiButton
                        variant="primary"
                        @click="showCreateModal = true"
                    >
                        Create Forwarder
                    </UiButton>
                </div>
            </div>

            <div v-else class="space-y-4">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-theme-border-subtle text-theme-text-muted text-[11px] uppercase tracking-wider">
                                <th class="py-3 px-4 font-semibold">Source</th>
                                <th class="py-3 px-4 font-semibold">Destination</th>
                                <th class="py-3 px-4 font-semibold">Type</th>
                                <th class="py-3 px-4 font-semibold">Status</th>
                                <th class="py-3 px-4 font-semibold text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-theme-border-subtle">
                            <tr
                                v-for="alias in aliasRows"
                                :key="alias.id"
                                class="hover:bg-theme-bg-elevated/40 transition-colors"
                            >
                                <td class="py-3 px-4">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono font-medium text-theme-text-primary">
                                            {{ alias.source_email }}
                                        </span>
                                        <UiBadge
                                            v-if="getSourceCount(alias.source_email) > 1"
                                            :variant="getSourceCount(alias.source_email) >= fanout_limit ? 'warning' : 'info'"
                                            :title="`Fan-out destinations for ${alias.source_email}`"
                                        >
                                            {{ getSourceCount(alias.source_email) }} / {{ fanout_limit }}
                                        </UiBadge>
                                    </div>
                                </td>
                                <td class="py-3 px-4 font-mono text-theme-text-secondary">
                                    {{ alias.destination_email }}
                                </td>
                                <td class="py-3 px-4">
                                    <UiBadge :variant="alias.is_catch_all ? 'warning' : 'neutral'">
                                        {{ alias.is_catch_all ? 'Catch-All' : 'Forwarder' }}
                                    </UiBadge>
                                </td>
                                <td class="py-3 px-4">
                                    <StatusBadge :status="alias.active ? 'active' : 'inactive'" />
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <UiButton
                                            size="sm"
                                            variant="secondary"
                                            :disabled="togglingId === alias.id"
                                            @click="toggleActive(alias)"
                                        >
                                            {{ alias.active ? 'Disable' : 'Enable' }}
                                        </UiButton>
                                        <UiButton
                                            size="sm"
                                            variant="danger"
                                            @click="deletingAlias = alias"
                                        >
                                            Delete
                                        </UiButton>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="aliases.links && aliases.links.length > 3"
                    class="flex items-center justify-between pt-3 border-t border-theme-border-subtle text-xs text-theme-text-muted"
                >
                    <span>
                        Showing {{ aliases.from ?? 0 }}–{{ aliases.to ?? 0 }} of {{ aliases.total ?? 0 }}
                    </span>
                    <div class="flex items-center gap-1">
                        <template v-for="(link, idx) in aliases.links" :key="idx">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                preserve-scroll
                                class="px-2.5 py-1 rounded-theme-sm border transition-colors"
                                :class="
                                    link.active
                                        ? 'border-theme-accent bg-theme-accent text-white'
                                        : 'border-theme-border-subtle text-theme-text-secondary hover:text-theme-text-primary'
                                "
                                v-html="link.label"
                            />
                            <span
                                v-else
                                class="px-2.5 py-1 rounded-theme-sm border border-transparent text-theme-text-muted opacity-50"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </UiCard>
    </div>

    <CreateMailAliasModal
        :show="showCreateModal"
        :domains="domains"
        :selected-domain="selected_domain"
        :fanout-limit="fanout_limit"
        :source-counts="source_counts"
        @close="showCreateModal = false"
    />

    <ConfirmActionModal
        :show="deletingAlias !== null"
        title="Delete Forwarder"
        :message="
            deletingAlias
                ? `Remove forwarder ${deletingAlias.source_email} -> ${deletingAlias.destination_email}? Incoming mail for this rule will no longer be forwarded.`
                : ''
        "
        confirm-label="Delete Forwarder"
        variant="danger"
        :processing="deleteProcessing"
        @confirm="confirmDelete"
        @close="deletingAlias = null"
    />
</template>
