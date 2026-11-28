<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
import PendingPurgeBanner from '@domains/Mail/Components/PendingPurgeBanner.vue';
import StatusBadge from '@domains/Mail/Components/StatusBadge.vue';
import EnableMailDomainModal from '@domains/Mail/Components/EnableMailDomainModal.vue';
import EditMailDomainModal from '@domains/Mail/Components/EditMailDomainModal.vue';
import DeleteMailDomainModal from '@domains/Mail/Components/DeleteMailDomainModal.vue';
import VerifyTxtModal from '@domains/Mail/Components/VerifyTxtModal.vue';

const props = defineProps({
    domains: {
        type: Array,
        default: () => [],
    },
    available_web_domains: {
        type: Array,
        default: () => [],
    },
    pending_purges: {
        type: Array,
        default: () => [],
    },
    can_admin_override: {
        type: Boolean,
        default: false,
    },
    mail_core_active: {
        type: Boolean,
        default: true,
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success || '');
const flashError = computed(() => page.props.flash?.error || '');

const showEnableModal = ref(false);
const showVerifyTxtModal = ref(false);
const editingDomain = ref(null);
const deletingDomain = ref(null);
const issuingTlsDomainId = ref(null);

const issueStandaloneTls = (item) => {
    issuingTlsDomainId.value = item.id;
    router.post(`/email/domains/${item.id}/issue-tls`, {}, {
        preserveScroll: true,
        onFinish: () => {
            issuingTlsDomainId.value = null;
        },
    });
};

const ownershipLabel = (source) => {
    const map = {
        webserver: 'WebServer',
        admin_override: 'Admin Override',
        dns_txt: 'DNS TXT',
    };

    return map[source] || source || 'Unknown';
};

const formatDate = (iso) => {
    if (!iso) {
        return '';
    }

    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
};
</script>

<template>
    <Head title="Mail Domains" />

    <div class="space-y-6">
            <UiPageHeader
                title="Mail Domains"
                subtitle="Enable domains for email hosting, configure per-domain quotas and rate limits, and inspect TLS SNI sync status."
            >
                <template #actions>
                    <div class="flex flex-wrap items-center gap-2">
                        <UiButton
                            variant="secondary"
                            @click="showVerifyTxtModal = true"
                        >
                            Verify DNS TXT
                        </UiButton>
                        <UiButton
                            variant="primary"
                            @click="showEnableModal = true"
                        >
                            Enable Mail Domain
                        </UiButton>
                    </div>
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

            <UiCard>
                <div v-if="domains.length === 0" class="py-10 text-center space-y-3">
                    <p class="text-sm font-medium text-theme-text-primary">
                        No mail domains enabled yet.
                    </p>
                    <p class="text-xs text-theme-text-muted max-w-md mx-auto">
                        Enable one of your hosted web domains or verify an external domain via DNS TXT to start creating mailboxes, forwarders, and DKIM keys.
                    </p>
                    <div class="pt-2 flex flex-wrap items-center justify-center gap-2">
                        <UiButton
                            variant="secondary"
                            @click="showVerifyTxtModal = true"
                        >
                            Verify DNS TXT
                        </UiButton>
                        <UiButton
                            variant="primary"
                            @click="showEnableModal = true"
                        >
                            Enable Mail Domain
                        </UiButton>
                    </div>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-theme-border-subtle text-theme-text-muted text-[11px] uppercase tracking-wider">
                                <th class="py-3 px-4 font-semibold">Domain</th>
                                <th class="py-3 px-4 font-semibold">Status</th>
                                <th class="py-3 px-4 font-semibold">Mailboxes</th>
                                <th class="py-3 px-4 font-semibold">Default Quota</th>
                                <th class="py-3 px-4 font-semibold">Outbound Limit</th>
                                <th class="py-3 px-4 font-semibold">TLS SNI</th>
                                <th class="py-3 px-4 font-semibold text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-theme-border-subtle">
                            <tr
                                v-for="item in domains"
                                :key="item.id"
                                class="hover:bg-theme-bg-surface/40 transition-colors"
                            >
                                <td class="py-3 px-4">
                                    <div class="font-mono font-medium text-theme-text-primary">
                                        {{ item.domain }}
                                    </div>
                                    <div class="mt-1 flex items-center gap-1.5">
                                        <UiBadge variant="neutral">
                                            {{ ownershipLabel(item.ownership_source) }}
                                        </UiBadge>
                                        <UiBadge v-if="item.dkim_enabled" variant="info">
                                            DKIM
                                        </UiBadge>
                                    </div>
                                </td>
                                <td class="py-3 px-4">
                                    <StatusBadge :status="item.active ? 'active' : 'inactive'" />
                                </td>
                                <td class="py-3 px-4 font-mono text-theme-text-secondary">
                                    {{ item.accounts_count }} / {{ item.max_accounts === 0 ? '∞' : item.max_accounts }}
                                </td>
                                <td class="py-3 px-4 font-mono text-theme-text-secondary">
                                    {{ item.default_quota_mb === 0 ? 'Unlimited' : `${item.default_quota_mb} MB` }}
                                </td>
                                <td class="py-3 px-4 font-mono text-theme-text-secondary">
                                    {{ item.outbound_hourly_limit }} / hr
                                </td>
                                <td class="py-3 px-4">
                                    <div class="space-y-1.5">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <span
                                                v-if="item.tls_sni_synced_at"
                                                class="text-xs text-theme-text-secondary"
                                                :title="item.tls_sni_synced_at"
                                            >
                                                {{ formatDate(item.tls_sni_synced_at) }}
                                            </span>
                                            <UiBadge v-else variant="warning">
                                                Pending SNI sync
                                            </UiBadge>
                                        </div>
                                        <div v-if="item.ownership_source === 'dns_txt'" class="space-y-1">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <UiBadge :variant="item.tls_sni_synced_at ? 'success' : 'neutral'">
                                                    mail.{{ item.domain }} HTTP-01 TLS: {{ item.tls_sni_synced_at ? 'Active' : 'Not issued' }}
                                                </UiBadge>
                                                <UiButton
                                                    size="xs"
                                                    variant="secondary"
                                                    :loading="issuingTlsDomainId === item.id"
                                                    :disabled="!item.active || issuingTlsDomainId !== null"
                                                    @click="issueStandaloneTls(item)"
                                                >
                                                    {{ item.tls_sni_synced_at ? 'Re-issue TLS' : 'Request TLS' }}
                                                </UiButton>
                                            </div>
                                            <p class="text-[11px] text-theme-text-muted leading-snug max-w-xs">
                                                Point <code class="font-mono">mail.{{ item.domain }}</code> A/AAAA to this server before requesting HTTP-01 ACME TLS.
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="flex flex-wrap items-center justify-end gap-1.5">
                                        <Link
                                            :href="`/email/accounts?domain=${encodeURIComponent(item.domain)}`"
                                            class="px-2 py-1 text-xs rounded-theme-sm border border-theme-border text-theme-text-secondary hover:text-theme-text-primary hover:bg-theme-bg-surface transition-colors"
                                        >
                                            Accounts
                                        </Link>
                                        <Link
                                            :href="`/email/forwarders?domain=${encodeURIComponent(item.domain)}`"
                                            class="px-2 py-1 text-xs rounded-theme-sm border border-theme-border text-theme-text-secondary hover:text-theme-text-primary hover:bg-theme-bg-surface transition-colors"
                                        >
                                            Forwarders
                                        </Link>
                                        <Link
                                            :href="`/email/dkim?domain=${encodeURIComponent(item.domain)}`"
                                            class="px-2 py-1 text-xs rounded-theme-sm border border-theme-border text-theme-text-secondary hover:text-theme-text-primary hover:bg-theme-bg-surface transition-colors"
                                        >
                                            DKIM
                                        </Link>
                                        <a
                                            v-if="item.webmail_url"
                                            :href="item.webmail_url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="px-2 py-1 text-xs rounded-theme-sm border border-theme-accent/40 text-theme-accent hover:bg-theme-accent/10 transition-colors"
                                        >
                                            Webmail
                                        </a>
                                        <UiButton
                                            size="xs"
                                            variant="secondary"
                                            @click="editingDomain = item"
                                        >
                                            Edit
                                        </UiButton>
                                        <UiButton
                                            size="xs"
                                            variant="danger-soft"
                                            @click="deletingDomain = item"
                                        >
                                            Delete
                                        </UiButton>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </UiCard>
        </div>

        <EnableMailDomainModal
            :show="showEnableModal"
            :available-web-domains="available_web_domains"
            :can-admin-override="can_admin_override"
            @close="showEnableModal = false"
        />

        <EditMailDomainModal
            :show="Boolean(editingDomain)"
            :domain="editingDomain"
            @close="editingDomain = null"
        />

        <DeleteMailDomainModal
            :show="Boolean(deletingDomain)"
            :domain="deletingDomain"
            @close="deletingDomain = null"
        />

        <VerifyTxtModal
            :show="showVerifyTxtModal"
            @close="showVerifyTxtModal = false"
        />
</template>
