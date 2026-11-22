<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Forward, Trash2 } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';

interface MailDomainItem {
    id: number;
    domain: string;
}

interface MailAliasItem {
    id: number;
    mail_domain_id: number;
    source_email: string;
    destination_email: string;
    is_catch_all: boolean;
    active: boolean;
}

const props = defineProps<{
    mailCoreActive: boolean;
    mailDomains: MailDomainItem[];
    aliases: MailAliasItem[];
}>();

const form = useForm({
    mail_domain_id: props.mailDomains[0]?.id ?? '',
    source_email: '',
    destination_email: '',
});

const createForwarder = () => {
    form.post('/email/forwarders', {
        preserveScroll: true,
        onSuccess: () => form.reset('source_email', 'destination_email'),
    });
};

const deleteForwarder = async (alias: MailAliasItem) => {
    if (
        await confirmDialog({
            title: 'Delete Forwarder',
            message: `Remove forwarder ${alias.source_email} -> ${alias.destination_email}?`,
            confirmText: 'Delete',
        })
    ) {
        router.delete(`/email/forwarders/${alias.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Email Forwarders" />

    <div class="space-y-6">
        <UiPageHeader
            title="Email Forwarders"
            subtitle="Route aliases and catch-all addresses to local or external destinations."
        >
            <template #actions>
                <UiBadge :variant="mailCoreActive ? 'success' : 'warning'">
                    {{ mailCoreActive ? 'Mail Core Active' : 'Mail Core Inactive' }}
                </UiBadge>
            </template>
        </UiPageHeader>

        <UiCard>
            <h2 class="text-base font-semibold mb-4 flex items-center gap-2">
                <Forward class="h-4 w-4" /> Create Forwarder
            </h2>
            <form @submit.prevent="createForwarder" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                <div>
                    <label class="block text-xs font-medium mb-1">Mail Domain</label>
                    <select
                        v-model="form.mail_domain_id"
                        class="w-full rounded-md border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-2 text-sm"
                        required
                    >
                        <option v-for="d in mailDomains" :key="d.id" :value="d.id">
                            {{ d.domain }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Source (or @domain for catch-all)</label>
                    <input
                        v-model="form.source_email"
                        type="text"
                        placeholder="sales@example.org"
                        class="w-full rounded-md border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-2 text-sm"
                        required
                    />
                    <p v-if="form.errors.source_email" class="mt-1 text-xs text-red-500">
                        {{ form.errors.source_email }}
                    </p>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Destination Email</label>
                    <input
                        v-model="form.destination_email"
                        type="email"
                        placeholder="team@destination.org"
                        class="w-full rounded-md border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-2 text-sm"
                        required
                    />
                    <p v-if="form.errors.destination_email" class="mt-1 text-xs text-red-500">
                        {{ form.errors.destination_email }}
                    </p>
                </div>
                <div>
                    <UiButton type="submit" :disabled="form.processing || mailDomains.length === 0" class="w-full">
                        Add Forwarder
                    </UiButton>
                </div>
            </form>
        </UiCard>

        <UiCard>
            <h2 class="text-base font-semibold mb-4">Active Forwarders</h2>
            <div v-if="aliases.length === 0" class="text-sm text-[var(--theme-text-muted)] py-6 text-center">
                No email forwarders configured.
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead>
                        <tr class="border-b border-[var(--theme-border)] text-xs uppercase text-[var(--theme-text-muted)]">
                            <th class="py-2 pr-4">Source</th>
                            <th class="py-2 pr-4">Destination</th>
                            <th class="py-2 pr-4">Type</th>
                            <th class="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="alias in aliases" :key="alias.id" class="border-b border-[var(--theme-border)]/50">
                            <td class="py-2.5 pr-4 font-medium">{{ alias.source_email }}</td>
                            <td class="py-2.5 pr-4">{{ alias.destination_email }}</td>
                            <td class="py-2.5 pr-4">
                                <UiBadge :variant="alias.is_catch_all ? 'warning' : 'neutral'">
                                    {{ alias.is_catch_all ? 'Catch-All' : 'Alias' }}
                                </UiBadge>
                            </td>
                            <td class="py-2.5 text-right">
                                <UiButton size="sm" variant="danger" @click="deleteForwarder(alias)">
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
