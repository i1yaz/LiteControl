<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { KeyRound, Copy, Check, ShieldAlert, Globe } from '@lucide/vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';

interface DkimKeyItem {
    id: number;
    mail_domain_id: number;
    selector: string;
    algorithm: string;
    public_key_txt: string | null;
    key_sha256: string | null;
    status: string;
    created_at: string | null;
    rotated_at: string | null;
}

interface MailDomainWithKeys {
    id: number;
    domain: string;
    dkim_enabled: boolean;
    dkim_keys: DkimKeyItem[];
}

interface DnsRecordItem {
    purpose: string;
    type: string;
    name: string;
    value: string;
    chunks?: string[];
    priority?: number;
    ttl: number;
}

const props = defineProps<{
    mailCoreActive: boolean;
    powerDnsActive: boolean;
    mailDomains: MailDomainWithKeys[];
    dnsRecordsByDomain: Record<number, DnsRecordItem[]>;
}>();

const selectedDomainId = ref<number | string>(props.mailDomains[0]?.id ?? '');
const copiedKey = ref<string | null>(null);

const form = useForm({
    mail_domain_id: props.mailDomains[0]?.id ?? '',
    selector: `lc${new Date().getFullYear()}`,
    algorithm: 'rsa2048',
});

const selectedDomain = computed(() =>
    props.mailDomains.find((d) => String(d.id) === String(selectedDomainId.value)) ?? null,
);

const currentRecords = computed<DnsRecordItem[]>(() => {
    if (!selectedDomain.value) return [];
    return props.dnsRecordsByDomain[selectedDomain.value.id] ?? [];
});

const generateKey = () => {
    form.mail_domain_id = selectedDomainId.value;
    form.post('/email/dkim', {
        preserveScroll: true,
    });
};

const copyText = async (key: string, text: string) => {
    try {
        await navigator.clipboard.writeText(text);
        copiedKey.value = key;
        setTimeout(() => {
            if (copiedKey.value === key) copiedKey.value = null;
        }, 2000);
    } catch {
        // Clipboard unavailable
    }
};
</script>

<template>
    <Head title="DKIM & Mail DNS" />

    <div class="space-y-6">
        <UiPageHeader
            title="DKIM & Mail DNS"
            subtitle="Generate or rotate DKIM signing keys and inspect required DNS records for your mail domains."
        >
            <template #actions>
                <UiBadge :variant="powerDnsActive ? 'success' : 'neutral'">
                    {{ powerDnsActive ? 'PowerDNS Auto-Sync Active' : 'External DNS (Manual)' }}
                </UiBadge>
            </template>
        </UiPageHeader>

        <UiCard v-if="!mailCoreActive" class="border-amber-500/30">
            <div class="flex items-center gap-3 text-sm text-[var(--theme-text-muted)]">
                <ShieldAlert class="h-5 w-5 text-amber-500 shrink-0" />
                <span>
                    The <code>mail_core</code> module is not currently active. Install it from
                    <strong>Modules</strong> to enable DKIM signing.
                </span>
            </div>
        </UiCard>

        <UiCard v-if="mailDomains.length === 0">
            <p class="text-sm text-[var(--theme-text-muted)]">
                No mail domains are enabled yet. Enable a mail domain under <strong>Email Accounts</strong> first.
            </p>
        </UiCard>

        <template v-else>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <UiCard>
                    <h2 class="text-base font-semibold mb-4 flex items-center gap-2">
                        <KeyRound class="h-4 w-4" /> Generate / Rotate DKIM Key
                    </h2>
                    <form @submit.prevent="generateKey" class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium mb-1">Mail Domain</label>
                            <select
                                v-model="selectedDomainId"
                                class="w-full rounded-md border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-2 text-sm"
                            >
                                <option v-for="d in mailDomains" :key="d.id" :value="d.id">
                                    {{ d.domain }}
                                </option>
                            </select>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium mb-1">Selector</label>
                                <input
                                    v-model="form.selector"
                                    type="text"
                                    placeholder="lc2026"
                                    class="w-full rounded-md border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-2 text-sm"
                                />
                                <p v-if="form.errors.selector" class="text-xs text-red-500 mt-1">
                                    {{ form.errors.selector }}
                                </p>
                            </div>

                            <div>
                                <label class="block text-xs font-medium mb-1">Algorithm</label>
                                <select
                                    v-model="form.algorithm"
                                    class="w-full rounded-md border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-2 text-sm"
                                >
                                    <option value="rsa2048">RSA-2048 (Recommended)</option>
                                    <option value="ed25519">Ed25519</option>
                                </select>
                                <p v-if="form.errors.algorithm" class="text-xs text-red-500 mt-1">
                                    {{ form.errors.algorithm }}
                                </p>
                            </div>
                        </div>

                        <UiButton type="submit" :disabled="form.processing">
                            {{ form.processing ? 'Generating…' : 'Generate DKIM Key' }}
                        </UiButton>
                    </form>
                </UiCard>

                <UiCard v-if="selectedDomain">
                    <h2 class="text-base font-semibold mb-4">
                        DKIM Keys — {{ selectedDomain.domain }}
                    </h2>
                    <div v-if="selectedDomain.dkim_keys.length === 0" class="text-sm text-[var(--theme-text-muted)]">
                        No DKIM keys generated yet for this domain.
                    </div>
                    <div v-else class="space-y-2">
                        <div
                            v-for="key in selectedDomain.dkim_keys"
                            :key="key.id"
                            class="flex items-center justify-between rounded-md border border-[var(--theme-border)] px-3 py-2 text-sm"
                        >
                            <div>
                                <div class="font-medium">
                                    <code>{{ key.selector }}._domainkey</code>
                                    <span class="ml-2 text-xs text-[var(--theme-text-muted)]">{{ key.algorithm }}</span>
                                </div>
                                <div v-if="key.key_sha256" class="text-xs text-[var(--theme-text-muted)] font-mono truncate max-w-xs">
                                    sha256: {{ key.key_sha256.slice(0, 16) }}…
                                </div>
                            </div>
                            <UiBadge :variant="key.status === 'active' ? 'success' : 'neutral'">
                                {{ key.status }}
                            </UiBadge>
                        </div>
                    </div>
                </UiCard>
            </div>

            <UiCard v-if="selectedDomain">
                <h2 class="text-base font-semibold mb-4 flex items-center gap-2">
                    <Globe class="h-4 w-4" /> Required DNS Records — {{ selectedDomain.domain }}
                </h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-[var(--theme-border)] text-xs uppercase text-[var(--theme-text-muted)]">
                                <th class="py-2 pr-3">Purpose</th>
                                <th class="py-2 pr-3">Type</th>
                                <th class="py-2 pr-3">Host / Name</th>
                                <th class="py-2 pr-3">Value</th>
                                <th class="py-2 text-right">Copy</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--theme-border)]">
                            <tr v-for="(rec, idx) in currentRecords" :key="`${rec.type}-${rec.name}-${idx}`">
                                <td class="py-2 pr-3 font-medium whitespace-nowrap">{{ rec.purpose }}</td>
                                <td class="py-2 pr-3">
                                    <UiBadge variant="neutral">{{ rec.type }}</UiBadge>
                                </td>
                                <td class="py-2 pr-3 font-mono text-xs break-all">{{ rec.name }}</td>
                                <td class="py-2 pr-3 font-mono text-xs break-all">
                                    <span v-if="rec.priority !== undefined" class="mr-1 font-semibold">
                                        [{{ rec.priority }}]
                                    </span>
                                    <template v-if="rec.chunks && rec.chunks.length > 1">
                                        <div v-for="(chunk, cIdx) in rec.chunks" :key="cIdx">
                                            "{{ chunk }}"
                                        </div>
                                    </template>
                                    <template v-else>{{ rec.value }}</template>
                                </td>
                                <td class="py-2 text-right">
                                    <UiButton
                                        size="sm"
                                        variant="secondary"
                                        @click="copyText(`${idx}`, rec.value)"
                                    >
                                        <Check v-if="copiedKey === `${idx}`" class="h-3.5 w-3.5" />
                                        <Copy v-else class="h-3.5 w-3.5" />
                                    </UiButton>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </UiCard>
        </template>
    </div>
</template>
