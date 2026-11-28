<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
import DomainSelector from '@domains/Mail/Components/DomainSelector.vue';
import StatusBadge from '@domains/Mail/Components/StatusBadge.vue';
import CopyableCodeBlock from '@domains/Mail/Components/CopyableCodeBlock.vue';
import GenerateDkimKeyModal from '@domains/Mail/Components/GenerateDkimKeyModal.vue';

const props = defineProps({
    domains: {
        type: Array,
        default: () => [],
    },
    selected_domain: {
        type: Object,
        default: null,
    },
    dkim_keys: {
        type: Array,
        default: () => [],
    },
    dns_records: {
        type: Array,
        default: () => [],
    },
    powerdns_active: {
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

const showGenerateModal = ref(false);
const dkimDisplayMode = ref('single');

const selectedDomainName = computed(() => props.selected_domain?.domain || '');

const formatTimestamp = (iso) => {
    if (!iso) {
        return '—';
    }
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
};

const formatAlgorithm = (algorithm) => {
    const val = String(algorithm || '').toLowerCase();
    if (val === 'rsa2048') {
        return 'RSA-2048';
    }
    if (val === 'ed25519') {
        return 'Ed25519';
    }
    return algorithm || 'Unknown';
};

const formatChunkedTxt = (chunks) => {
    if (!Array.isArray(chunks) || chunks.length === 0) {
        return '';
    }
    return chunks.map((chunk) => `"${chunk}"`).join(' ');
};

const recordTypeBadgeVariant = (type) => {
    const normalized = String(type || '').toUpperCase();
    if (normalized === 'TXT') {
        return 'info';
    }
    if (normalized === 'MX') {
        return 'success';
    }
    if (normalized === 'A' || normalized === 'AAAA') {
        return 'neutral';
    }
    return 'warning';
};

const isDkimRecord = (record) =>
    record.purpose === 'DKIM' || (Array.isArray(record.chunks) && record.chunks.length > 0);
</script>

<template>
    <Head title="DKIM Keys & DNS Records" />

    <div class="space-y-6">
        <UiPageHeader
            title="DKIM Keys & DNS Records"
            subtitle="Manage DKIM signing keys and configure required DNS records for reliable mail deliverability."
        >
            <template #actions>
                <span :title="domains.length === 0 ? 'Enable a mail domain first before generating DKIM keys.' : ''">
                    <UiButton
                        variant="primary"
                        :disabled="domains.length === 0 || !selected_domain"
                        @click="showGenerateModal = true"
                    >
                        Generate / Rotate Key
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
                before signing outbound mail.
            </UiAlert>
        </div>

        <div v-if="domains.length === 0">
            <UiCard>
                <div class="py-10 text-center space-y-3">
                    <p class="text-sm font-medium text-theme-text-primary">
                        No mail domains enabled yet.
                    </p>
                    <p class="text-xs text-theme-text-muted max-w-md mx-auto">
                        Enable a domain in Mail Domains to generate DKIM keys and inspect required DNS records.
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
            </UiCard>
        </div>

        <template v-else>
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                <div class="w-full sm:max-w-xs">
                    <DomainSelector
                        :domains="domains"
                        :model-value="selectedDomainName"
                        route-url="/email/dkim"
                        label="Mail Domain"
                    />
                </div>
            </div>

            <UiAlert v-if="powerdns_active" variant="info">
                <strong>Local PowerDNS is active.</strong>
                Required mail DNS records (including DKIM keys) sync automatically to your local PowerDNS zone.
            </UiAlert>
            <UiAlert v-else variant="warning">
                Copy the required DNS records below into your DNS provider (e.g., Cloudflare, or PowerDNS if DNS is managed on this server) to pass SPF, DKIM, and DMARC checks.
            </UiAlert>

            <UiCard>
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-theme-border-subtle">
                    <div>
                        <h2 class="text-sm font-semibold text-theme-text-primary">
                            DKIM Signing Keys
                        </h2>
                        <p class="text-xs text-theme-text-muted mt-0.5">
                            Active and historical DKIM selectors for
                            <span class="font-mono text-theme-text-secondary">{{ selected_domain?.domain }}</span>.
                        </p>
                    </div>
                    <UiButton
                        size="sm"
                        variant="secondary"
                        @click="showGenerateModal = true"
                    >
                        Generate / Rotate Key
                    </UiButton>
                </div>

                <div v-if="dkim_keys.length === 0" class="py-8 text-center space-y-3">
                    <p class="text-sm font-medium text-theme-text-primary">
                        No DKIM keys generated for {{ selected_domain?.domain }} yet.
                    </p>
                    <p class="text-xs text-theme-text-muted max-w-md mx-auto">
                        Generate an RSA-2048 or Ed25519 signing key to enable DKIM authentication for outbound messages.
                    </p>
                    <div class="pt-1">
                        <UiButton variant="primary" size="sm" @click="showGenerateModal = true">
                            Generate DKIM Key
                        </UiButton>
                    </div>
                </div>

                <div v-else class="overflow-x-auto mt-2">
                    <table class="w-full text-left border-collapse text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-theme-border-subtle text-theme-text-muted text-[11px] uppercase tracking-wider">
                                <th class="py-3 px-4 font-semibold">Selector</th>
                                <th class="py-3 px-4 font-semibold">Algorithm</th>
                                <th class="py-3 px-4 font-semibold">Status</th>
                                <th class="py-3 px-4 font-semibold">DNS Verification</th>
                                <th class="py-3 px-4 font-semibold">Created</th>
                                <th class="py-3 px-4 font-semibold">Rotated</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-theme-border-subtle">
                            <tr
                                v-for="key in dkim_keys"
                                :key="key.id"
                                class="hover:bg-theme-bg-elevated/40 transition-colors"
                            >
                                <td class="py-3 px-4 font-mono font-medium text-theme-text-primary">
                                    {{ key.selector }}
                                </td>
                                <td class="py-3 px-4">
                                    <UiBadge variant="neutral">
                                        {{ formatAlgorithm(key.algorithm) }}
                                    </UiBadge>
                                </td>
                                <td class="py-3 px-4">
                                    <StatusBadge :status="key.status" />
                                </td>
                                <td class="py-3 px-4 text-xs text-theme-text-secondary">
                                    <span v-if="key.dns_verified_at">
                                        {{ formatTimestamp(key.dns_verified_at) }}
                                    </span>
                                    <UiBadge v-else variant="warning">
                                        Unverified
                                    </UiBadge>
                                </td>
                                <td class="py-3 px-4 text-xs text-theme-text-secondary">
                                    {{ formatTimestamp(key.created_at) }}
                                </td>
                                <td class="py-3 px-4 text-xs text-theme-text-secondary">
                                    {{ formatTimestamp(key.rotated_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </UiCard>

            <UiCard>
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-theme-border-subtle">
                    <div>
                        <h2 class="text-sm font-semibold text-theme-text-primary">
                            Required DNS Records
                        </h2>
                        <p class="text-xs text-theme-text-muted mt-0.5">
                            Publish these records in DNS for
                            <span class="font-mono text-theme-text-secondary">{{ selected_domain?.domain }}</span>
                            (A/AAAA, MX, SPF, DKIM, DMARC, Autoconfig/Autodiscover, MTA-STS, and TLS-RPT).
                        </p>
                    </div>
                </div>

                <div class="divide-y divide-theme-border-subtle">
                    <div
                        v-for="(record, idx) in dns_records"
                        :key="`${record.purpose}-${record.host}-${idx}`"
                        class="py-4 space-y-2.5"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <UiBadge :variant="recordTypeBadgeVariant(record.type)">
                                    {{ record.type }}
                                </UiBadge>
                                <span class="text-xs font-semibold text-theme-text-primary">
                                    {{ record.purpose }}
                                </span>
                                <UiBadge v-if="record.priority !== null && record.priority !== undefined" variant="neutral">
                                    Priority {{ record.priority }}
                                </UiBadge>
                                <span class="text-[11px] text-theme-text-muted">
                                    TTL {{ record.ttl ?? 3600 }}s
                                </span>
                            </div>

                            <div
                                v-if="isDkimRecord(record) && record.chunks && record.chunks.length > 0"
                                role="group"
                                aria-label="DKIM TXT record format"
                                class="inline-flex items-center rounded-theme-md border border-theme-border-subtle bg-theme-bg-surface p-0.5 gap-0.5"
                            >
                                <button
                                    type="button"
                                    class="px-2.5 py-1 text-[11px] font-medium rounded-theme-sm transition-colors"
                                    :class="
                                        dkimDisplayMode === 'single'
                                            ? 'bg-theme-accent text-white'
                                            : 'text-theme-text-secondary hover:text-theme-text-primary'
                                    "
                                    @click="dkimDisplayMode = 'single'"
                                >
                                    Single String
                                </button>
                                <button
                                    type="button"
                                    class="px-2.5 py-1 text-[11px] font-medium rounded-theme-sm transition-colors"
                                    :class="
                                        dkimDisplayMode === 'chunks'
                                            ? 'bg-theme-accent text-white'
                                            : 'text-theme-text-secondary hover:text-theme-text-primary'
                                    "
                                    @click="dkimDisplayMode = 'chunks'"
                                >
                                    255-char Quoted Chunks
                                </button>
                            </div>
                        </div>

                        <div class="text-xs text-theme-text-secondary">
                            <span class="text-theme-text-muted">Host:</span>
                            <code class="ml-1.5 font-mono text-theme-text-primary break-all">{{ record.host }}</code>
                        </div>

                        <CopyableCodeBlock
                            v-if="isDkimRecord(record) && dkimDisplayMode === 'chunks' && record.chunks && record.chunks.length > 0"
                            :value="formatChunkedTxt(record.chunks)"
                            :label="`${record.purpose} Value (255-char Quoted Chunks)`"
                        />
                        <CopyableCodeBlock
                            v-else
                            :value="record.value"
                            :label="`${record.purpose} Value`"
                        />
                    </div>
                </div>
            </UiCard>
        </template>
    </div>

    <GenerateDkimKeyModal
        :show="showGenerateModal"
        :selected-domain="selected_domain"
        :existing-keys="dkim_keys"
        @close="showGenerateModal = false"
    />
</template>
