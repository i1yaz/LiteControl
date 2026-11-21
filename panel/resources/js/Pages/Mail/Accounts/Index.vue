<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
import DomainSelector from '@domains/Mail/Components/DomainSelector.vue';
import QuotaBar from '@domains/Mail/Components/QuotaBar.vue';
import StatusBadge from '@domains/Mail/Components/StatusBadge.vue';
import PendingPurgeBanner from '@domains/Mail/Components/PendingPurgeBanner.vue';
import ConfirmActionModal from '@domains/Mail/Components/ConfirmActionModal.vue';
import CreateMailAccountModal from '@domains/Mail/Components/CreateMailAccountModal.vue';
import EditMailAccountModal from '@domains/Mail/Components/EditMailAccountModal.vue';

const props = defineProps({
    domains: {
        type: Array,
        default: () => [],
    },
    selected_domain: {
        type: Object,
        default: null,
    },
    accounts: {
        type: Object,
        default: () => ({ data: [], links: [], total: 0 }),
    },
    package_limits: {
        type: Object,
        default: () => ({ used_accounts: 0, max_accounts: 0, max_quota_mb: 0 }),
    },
    pending_purges: {
        type: Array,
        default: () => [],
    },
    webmail_url: {
        type: String,
        default: null,
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
const editingAccount = ref(null);
const deletingAccount = ref(null);
const deleteProcessing = ref(false);

const selectedDomainName = computed(() => props.selected_domain?.domain || '');

const packageLimitReached = computed(() => {
    const max = props.package_limits?.max_accounts ?? 0;
    const used = props.package_limits?.used_accounts ?? 0;
    return max > 0 && used >= max;
});

const createDisabled = computed(() => props.domains.length === 0 || packageLimitReached.value);

const createDisabledReason = computed(() => {
    if (props.domains.length === 0) {
        return 'Enable a mail domain first before creating mailboxes.';
    }
    if (packageLimitReached.value) {
        return `Package mailbox limit reached (${props.package_limits.used_accounts} / ${props.package_limits.max_accounts}).`;
    }
    return '';
});

const accountRows = computed(() => props.accounts?.data ?? []);

const confirmDelete = () => {
    if (!deletingAccount.value) {
        return;
    }

    deleteProcessing.value = true;
    router.delete(`/email/accounts/${deletingAccount.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleteProcessing.value = false;
            deletingAccount.value = null;
        },
    });
};
</script>

<template>
    <Head title="Mailboxes" />

    <div class="space-y-6">
        <UiPageHeader
            title="Mailboxes"
            subtitle="Create and manage email accounts, storage quotas, and IMAP/POP3/SMTP protocol access."
        >
            <template #actions>
                <span :title="createDisabledReason">
                    <UiButton
                        variant="primary"
                        :disabled="createDisabled"
                        @click="showCreateModal = true"
                    >
                        Create Mailbox
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

        <PendingPurgeBanner :purges="pending_purges" />

        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
            <div class="w-full sm:max-w-xs">
                <DomainSelector
                    :domains="domains"
                    :model-value="selectedDomainName"
                    route-url="/email/accounts"
                    label="Filter by Domain"
                    include-all-option
                    all-label="All Mail Domains"
                />
            </div>

            <div class="flex items-center gap-3 text-xs text-theme-text-secondary bg-theme-bg-surface border border-theme-border-subtle rounded-theme-md px-3.5 py-2">
                <span class="font-medium text-theme-text-muted uppercase tracking-wider text-[11px]">
                    Package Usage
                </span>
                <span class="font-mono font-semibold text-theme-text-primary">
                    {{ package_limits.used_accounts }} / {{ package_limits.max_accounts === 0 ? '∞' : package_limits.max_accounts }} mailboxes
                </span>
                <UiBadge v-if="packageLimitReached" variant="warning">
                    Limit Reached
                </UiBadge>
            </div>
        </div>

        <UiCard>
            <div v-if="domains.length === 0" class="py-10 text-center space-y-3">
                <p class="text-sm font-medium text-theme-text-primary">
                    No mail domains enabled yet.
                </p>
                <p class="text-xs text-theme-text-muted max-w-md mx-auto">
                    Enable a domain in Mail Domains before creating mailboxes.
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

            <div v-else-if="accountRows.length === 0" class="py-10 text-center space-y-3">
                <p class="text-sm font-medium text-theme-text-primary">
                    No mailboxes found{{ selected_domain ? ` for ${selected_domain.domain}` : '' }}.
                </p>
                <p class="text-xs text-theme-text-muted max-w-md mx-auto">
                    Create your first mailbox to start sending and receiving email.
                </p>
                <div class="pt-2">
                    <UiButton
                        variant="primary"
                        :disabled="createDisabled"
                        @click="showCreateModal = true"
                    >
                        Create Mailbox
                    </UiButton>
                </div>
            </div>

            <div v-else class="space-y-4">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-theme-border-subtle text-theme-text-muted text-[11px] uppercase tracking-wider">
                                <th class="py-3 px-4 font-semibold">Email</th>
                                <th class="py-3 px-4 font-semibold">Quota</th>
                                <th class="py-3 px-4 font-semibold">Protocols</th>
                                <th class="py-3 px-4 font-semibold">Status</th>
                                <th class="py-3 px-4 font-semibold text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-theme-border-subtle">
                            <tr
                                v-for="acc in accountRows"
                                :key="acc.id"
                                class="hover:bg-theme-bg-surface/40 transition-colors"
                            >
                                <td class="py-3 px-4 font-mono font-medium text-theme-text-primary">
                                    {{ acc.email }}
                                </td>
                                <td class="py-3 px-4">
                                    <QuotaBar :used-bytes="acc.used_bytes" :quota-mb="acc.quota_mb" />
                                </td>
                                <td class="py-3 px-4">
                                    <div class="flex flex-wrap items-center gap-1">
                                        <UiBadge v-if="acc.allow_imap" variant="neutral">IMAP</UiBadge>
                                        <UiBadge v-if="acc.allow_pop3" variant="neutral">POP3</UiBadge>
                                        <UiBadge v-if="acc.allow_smtp" variant="neutral">SMTP</UiBadge>
                                        <span
                                            v-if="!acc.allow_imap && !acc.allow_pop3 && !acc.allow_smtp"
                                            class="text-xs text-theme-text-muted"
                                        >
                                            None
                                        </span>
                                    </div>
                                </td>
                                <td class="py-3 px-4">
                                    <StatusBadge :status="acc.active ? 'active' : 'inactive'" />
                                </td>
                                <td class="py-3 px-4">
                                    <div class="flex flex-wrap items-center justify-end gap-1.5">
                                        <a
                                            v-if="webmail_url"
                                            :href="webmail_url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="px-2 py-1 text-xs rounded-theme-sm border border-theme-accent/40 text-theme-accent hover:bg-theme-accent/10 transition-colors"
                                        >
                                            Webmail
                                        </a>
                                        <UiButton
                                            size="sm"
                                            variant="ghost"
                                            @click="editingAccount = acc"
                                        >
                                            Edit
                                        </UiButton>
                                        <UiButton
                                            size="sm"
                                            variant="danger"
                                            @click="deletingAccount = acc"
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
                    v-if="accounts.links && accounts.links.length > 3"
                    class="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-theme-border-subtle px-4"
                >
                    <span class="text-xs text-theme-text-muted">
                        Showing {{ accounts.from ?? 0 }}–{{ accounts.to ?? 0 }} of {{ accounts.total ?? 0 }} mailboxes
                    </span>
                    <div class="flex items-center gap-1">
                        <template v-for="(link, idx) in accounts.links" :key="idx">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                preserve-state
                                class="px-2.5 py-1 text-xs rounded-theme-sm border transition-colors"
                                :class="link.active
                                    ? 'border-theme-accent bg-theme-accent text-white'
                                    : 'border-theme-border text-theme-text-secondary hover:bg-theme-bg-surface'"
                                v-html="link.label"
                            />
                            <span
                                v-else
                                class="px-2.5 py-1 text-xs text-theme-text-muted opacity-50"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </UiCard>
    </div>

    <CreateMailAccountModal
        :show="showCreateModal"
        :domains="domains"
        :selected-domain="selected_domain"
        :package-limits="package_limits"
        @close="showCreateModal = false"
    />

    <EditMailAccountModal
        :show="Boolean(editingAccount)"
        :account="editingAccount"
        :package-limits="package_limits"
        @close="editingAccount = null"
    />

    <ConfirmActionModal
        :show="Boolean(deletingAccount)"
        title="Delete Mailbox"
        :message="deletingAccount ? `Delete mailbox '${deletingAccount.email}'?\n\nActive IMAP/SMTP sessions will be immediately evicted, dependent local aliases pointing to this mailbox will be cleaned up, and the address will remain locked against re-creation until background Maildir purge completes.` : ''"
        confirm-text="Delete Mailbox"
        variant="danger"
        :processing="deleteProcessing"
        @close="deletingAccount = null"
        @confirm="confirmDelete"
    />
</template>
