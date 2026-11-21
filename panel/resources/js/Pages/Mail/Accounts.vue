<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Mail, Plus, Trash2, Power, ShieldAlert } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';

interface MailDomainItem {
    id: number;
    domain: string;
    active: boolean;
    ownership_source: string;
    default_quota_mb: number;
    max_accounts: number;
}

interface MailAccountItem {
    id: number;
    mail_domain_id: number;
    local_part: string;
    email: string;
    quota_mb: number;
    used_bytes: number;
    active: boolean;
    allow_imap: boolean;
    allow_pop3: boolean;
    allow_smtp: boolean;
}

const props = defineProps<{
    mailCoreActive: boolean;
    mailDomains: MailDomainItem[];
    accounts: MailAccountItem[];
    isAdmin: boolean;
}>();

const domainForm = useForm({
    domain: '',
    admin_override: false,
});

const accountForm = useForm({
    mail_domain_id: props.mailDomains[0]?.id ?? '',
    local_part: '',
    password: '',
    quota_mb: 1024,
    allow_imap: true,
    allow_pop3: true,
    allow_smtp: true,
});

const enableDomain = () => {
    domainForm.post('/email/domains', {
        preserveScroll: true,
        onSuccess: () => domainForm.reset('domain', 'admin_override'),
    });
};

const createAccount = () => {
    accountForm.post('/email/accounts', {
        preserveScroll: true,
        onSuccess: () => accountForm.reset('local_part', 'password'),
    });
};

const toggleActive = (account: MailAccountItem) => {
    router.put(
        `/email/accounts/${account.id}`,
        { active: !account.active },
        { preserveScroll: true },
    );
};

const deleteAccount = async (account: MailAccountItem) => {
    if (
        await confirmDialog({
            title: 'Delete Mail Account',
            message: `Delete mailbox '${account.email}'? Active sessions will be evicted and Maildir data will be queued for purge.`,
            confirmText: 'Delete',
        })
    ) {
        router.delete(`/email/accounts/${account.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Email Accounts" />

    <div class="space-y-6">
        <UiPageHeader
            title="Email Accounts"
            subtitle="Enable mail domains and manage mailboxes, quotas, and protocol access."
        >
            <template #actions>
                <UiBadge :variant="mailCoreActive ? 'success' : 'warning'">
                    {{ mailCoreActive ? 'Mail Core Active' : 'Mail Core Inactive' }}
                </UiBadge>
            </template>
        </UiPageHeader>

        <UiCard v-if="!mailCoreActive" class="border-amber-500/30">
            <div class="flex items-center gap-3 text-sm text-[var(--theme-text-muted)]">
                <ShieldAlert class="h-5 w-5 text-amber-500 shrink-0" />
                <span>
                    The <code>mail_core</code> module is not currently active. Install it from
                    <strong>Modules</strong> to enable live mail delivery.
                </span>
            </div>
        </UiCard>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <UiCard>
                <h2 class="text-base font-semibold mb-4 flex items-center gap-2">
                    <Plus class="h-4 w-4" /> Enable Mail Domain
                </h2>
                <form @submit.prevent="enableDomain" class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium mb-1">Domain Name</label>
                        <input
                            v-model="domainForm.domain"
                            type="text"
                            placeholder="example.org"
                            class="w-full rounded-md border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-2 text-sm"
                            required
                        />
                        <p v-if="domainForm.errors.domain" class="mt-1 text-xs text-red-500">
                            {{ domainForm.errors.domain }}
                        </p>
                    </div>
                    <label v-if="isAdmin" class="flex items-center gap-2 text-xs">
                        <input v-model="domainForm.admin_override" type="checkbox" />
                        <span>Admin override (allow external-web domain)</span>
                    </label>
                    <UiButton type="submit" :disabled="domainForm.processing">
                        Enable Mail Domain
                    </UiButton>
                </form>
            </UiCard>

            <UiCard>
                <h2 class="text-base font-semibold mb-4 flex items-center gap-2">
                    <Mail class="h-4 w-4" /> Create Mailbox
                </h2>
                <form @submit.prevent="createAccount" class="space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium mb-1">Local Part</label>
                            <input
                                v-model="accountForm.local_part"
                                type="text"
                                placeholder="info"
                                class="w-full rounded-md border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-2 text-sm"
                                required
                            />
                            <p v-if="accountForm.errors.local_part || accountForm.errors.email" class="mt-1 text-xs text-red-500">
                                {{ accountForm.errors.local_part || accountForm.errors.email }}
                            </p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1">Mail Domain</label>
                            <select
                                v-model="accountForm.mail_domain_id"
                                class="w-full rounded-md border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-2 text-sm"
                                required
                            >
                                <option v-for="d in mailDomains" :key="d.id" :value="d.id">
                                    @{{ d.domain }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium mb-1">Password</label>
                            <input
                                v-model="accountForm.password"
                                type="password"
                                minlength="8"
                                class="w-full rounded-md border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-2 text-sm"
                                required
                            />
                            <p v-if="accountForm.errors.password" class="mt-1 text-xs text-red-500">
                                {{ accountForm.errors.password }}
                            </p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1">Quota (MB)</label>
                            <input
                                v-model.number="accountForm.quota_mb"
                                type="number"
                                min="0"
                                class="w-full rounded-md border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-2 text-sm"
                                required
                            />
                            <p v-if="accountForm.errors.quota_mb" class="mt-1 text-xs text-red-500">
                                {{ accountForm.errors.quota_mb }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4 text-xs">
                        <label class="flex items-center gap-1.5">
                            <input v-model="accountForm.allow_imap" type="checkbox" /> IMAP
                        </label>
                        <label class="flex items-center gap-1.5">
                            <input v-model="accountForm.allow_pop3" type="checkbox" /> POP3
                        </label>
                        <label class="flex items-center gap-1.5">
                            <input v-model="accountForm.allow_smtp" type="checkbox" /> SMTP
                        </label>
                    </div>
                    <UiButton type="submit" :disabled="accountForm.processing || mailDomains.length === 0">
                        Create Account
                    </UiButton>
                </form>
            </UiCard>
        </div>

        <UiCard>
            <h2 class="text-base font-semibold mb-4">Mailboxes</h2>
            <div v-if="accounts.length === 0" class="text-sm text-[var(--theme-text-muted)] py-6 text-center">
                No email accounts created yet.
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead>
                        <tr class="border-b border-[var(--theme-border)] text-xs uppercase text-[var(--theme-text-muted)]">
                            <th class="py-2 pr-4">Email</th>
                            <th class="py-2 pr-4">Quota</th>
                            <th class="py-2 pr-4">Protocols</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="acc in accounts" :key="acc.id" class="border-b border-[var(--theme-border)]/50">
                            <td class="py-2.5 pr-4 font-medium">{{ acc.email }}</td>
                            <td class="py-2.5 pr-4">
                                {{ acc.quota_mb === 0 ? 'Unlimited' : `${acc.quota_mb} MB` }}
                            </td>
                            <td class="py-2.5 pr-4 text-xs space-x-1">
                                <UiBadge v-if="acc.allow_imap" variant="neutral">IMAP</UiBadge>
                                <UiBadge v-if="acc.allow_pop3" variant="neutral">POP3</UiBadge>
                                <UiBadge v-if="acc.allow_smtp" variant="neutral">SMTP</UiBadge>
                            </td>
                            <td class="py-2.5 pr-4">
                                <UiBadge :variant="acc.active ? 'success' : 'danger'">
                                    {{ acc.active ? 'Active' : 'Suspended' }}
                                </UiBadge>
                            </td>
                            <td class="py-2.5 text-right space-x-2">
                                <UiButton size="sm" variant="secondary" @click="toggleActive(acc)">
                                    <Power class="h-3.5 w-3.5 mr-1" />
                                    {{ acc.active ? 'Suspend' : 'Enable' }}
                                </UiButton>
                                <UiButton size="sm" variant="danger" @click="deleteAccount(acc)">
                                    <Trash2 class="h-3.5 w-3.5" />
                                </UiButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </UiCard>
    </div>
</template>
