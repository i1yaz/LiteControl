<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    FileCode2,
    Plus,
    Globe,
    Settings,
    Star,
    Edit3,
    Trash2,
    Sparkles,
} from '@lucide/vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiConfirmDialog from '@shared/Ui/UiConfirmDialog.vue';
import PdnsDefaultNameserversCard from '@domains/Dns/Components/PdnsDefaultNameserversCard.vue';
import PdnsZoneTemplateModal from '@domains/Dns/Components/PdnsZoneTemplateModal.vue';

const props = defineProps({
    templates: {
        type: Array as () => Array<Record<string, any>>,
        default: () => [],
    },
    defaultNameservers: {
        type: Array as () => Array<{
            hostname: string;
            ipv4: string;
            ipv6: string;
            base_domain?: string;
        }>,
        default: () => [],
    },
    supportedPlaceholders: {
        type: Array as () => string[],
        default: () => ['{{DOMAIN}}', '{{IPV4}}', '{{IPV6}}', '{{NS1}}', '{{NS2}}'],
    },
    allowedRecordTypes: {
        type: Array as () => string[],
        default: () => ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR'],
    },
});

const showTemplateModal = ref(false);
const editingTemplate = ref<Record<string, any> | null>(null);
const templateToDelete = ref<Record<string, any> | null>(null);
const deleting = ref(false);

const openCreateModal = () => {
    editingTemplate.value = null;
    showTemplateModal.value = true;
};

const openEditModal = (template: Record<string, any>) => {
    editingTemplate.value = template;
    showTemplateModal.value = true;
};

const setAsDefault = (template: Record<string, any>) => {
    router.post(`/dns/templates/${template.id}/default`, {}, { preserveScroll: true });
};

const confirmDeleteTemplate = () => {
    if (!templateToDelete.value) {
        return;
    }
    deleting.value = true;
    router.delete(`/dns/templates/${templateToDelete.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            templateToDelete.value = null;
        },
    });
};

const placeholderDescriptions: Record<string, string> = {
    '{{DOMAIN}}': 'Canonical domain name being created (e.g. example.com)',
    '{{IPV4}}': 'Target server IPv4 address supplied during zone creation',
    '{{IPV6}}': 'Optional target server IPv6 address (AAAA records skipped when blank)',
    '{{NS1}}': 'Primary authoritative nameserver FQDN (e.g. ns1.paneldomain.com.)',
    '{{NS2}}': 'Secondary authoritative nameserver FQDN (e.g. ns2.paneldomain.com.)',
};
</script>

<template>
    <Head :title="__('Default Nameservers & Zone Templates')" />

    <div class="max-w-6xl mx-auto space-y-6">
        <UiPageHeader
            :title="__('Default Nameservers & Zone Templates')"
            :subtitle="__('Configure default authoritative nameservers, registrar glue record guidance, and reusable DNS zone templates with deterministic placeholders.')"
        >
            <template #icon>
                <FileCode2 class="w-6 h-6 text-theme-accent" />
            </template>
            <template #actions>
                <div class="flex flex-wrap items-center gap-2.5">
                    <Link
                        href="/dns/zones"
                        class="inline-flex items-center gap-2 px-3.5 py-2 rounded-theme-md text-xs font-semibold border border-theme-border bg-theme-bg-card text-theme-text-secondary hover:text-theme-text-primary hover:bg-theme-bg-surface transition-colors"
                    >
                        <Globe class="w-4 h-4" />
                        <span>{{ __('Zone Manager') }}</span>
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
                        @click="openCreateModal"
                    >
                        <Plus class="w-4 h-4" />
                        <span>{{ __('Create Zone Template') }}</span>
                    </UiButton>
                </div>
            </template>
        </UiPageHeader>

        <!-- Default Nameservers & Glue Record Guidance Card -->
        <PdnsDefaultNameserversCard :default-nameservers="defaultNameservers" />

        <!-- Placeholder Reference Bar -->
        <UiCard>
            <div class="space-y-3">
                <div class="flex items-center gap-2">
                    <Sparkles class="w-4 h-4 text-theme-accent" />
                    <h3 class="text-sm font-bold text-theme-text-primary">
                        {{ __('Zone Template Placeholder Tokens') }}
                    </h3>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2.5">
                    <div
                        v-for="token in supportedPlaceholders"
                        :key="token"
                        class="p-2.5 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle space-y-1"
                    >
                        <span class="font-mono text-xs font-bold text-theme-accent">
                            {{ token }}
                        </span>
                        <p class="text-[11px] text-theme-text-secondary leading-snug">
                            {{ placeholderDescriptions[token] || token }}
                        </p>
                    </div>
                </div>
            </div>
        </UiCard>

        <!-- Zone Templates List -->
        <div class="space-y-4">
            <div
                v-for="tpl in templates"
                :key="tpl.id"
                class="rounded-theme-lg bg-theme-bg-card border border-theme-border shadow-xs overflow-hidden"
            >
                <div class="p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-theme-border-subtle">
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-base font-bold text-theme-text-primary">
                                {{ tpl.name }}
                            </h3>
                            <UiBadge v-if="tpl.is_default" variant="success">
                                <Star class="w-3.5 h-3.5" />
                                <span>{{ __('Default Template') }}</span>
                            </UiBadge>
                            <UiBadge variant="neutral">
                                <span>{{ (tpl.records || []).length }} {{ __('records') }}</span>
                            </UiBadge>
                        </div>
                        <p v-if="tpl.description" class="text-xs text-theme-text-secondary">
                            {{ tpl.description }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <UiButton
                            v-if="!tpl.is_default"
                            type="button"
                            variant="secondary"
                            size="sm"
                            @click="setAsDefault(tpl)"
                        >
                            <Star class="w-3.5 h-3.5" />
                            <span>{{ __('Set Default') }}</span>
                        </UiButton>

                        <UiButton
                            type="button"
                            variant="secondary"
                            size="sm"
                            @click="openEditModal(tpl)"
                        >
                            <Edit3 class="w-3.5 h-3.5" />
                            <span>{{ __('Edit Template') }}</span>
                        </UiButton>

                        <UiButton
                            type="button"
                            variant="danger"
                            size="sm"
                            @click="templateToDelete = tpl"
                        >
                            <Trash2 class="w-3.5 h-3.5" />
                            <span>{{ __('Delete') }}</span>
                        </UiButton>
                    </div>
                </div>

                <!-- Template Records Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="bg-theme-bg-surface border-b border-theme-border-subtle text-theme-text-secondary uppercase tracking-wider text-[11px]">
                                <th class="py-2.5 px-4 font-semibold">{{ __('Host / Name') }}</th>
                                <th class="py-2.5 px-4 font-semibold">{{ __('Type') }}</th>
                                <th class="py-2.5 px-4 font-semibold">{{ __('TTL') }}</th>
                                <th class="py-2.5 px-4 font-semibold">{{ __('Priority') }}</th>
                                <th class="py-2.5 px-4 font-semibold">{{ __('Content / Placeholder Value') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-theme-border-subtle">
                            <tr
                                v-for="(rec, rIdx) in tpl.records"
                                :key="rec.id || rIdx"
                                class="hover:bg-theme-bg-surface/40"
                            >
                                <td class="py-2.5 px-4 font-mono font-semibold text-theme-text-primary">
                                    {{ rec.name }}
                                </td>
                                <td class="py-2.5 px-4">
                                    <UiBadge variant="info">
                                        <span class="font-mono">{{ rec.type }}</span>
                                    </UiBadge>
                                </td>
                                <td class="py-2.5 px-4 font-mono text-theme-text-secondary">
                                    {{ rec.ttl }}s
                                </td>
                                <td class="py-2.5 px-4 font-mono text-theme-text-secondary">
                                    {{ rec.priority !== null && rec.priority !== undefined ? rec.priority : '—' }}
                                </td>
                                <td class="py-2.5 px-4 font-mono text-theme-text-primary break-all">
                                    {{ rec.content }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Create / Edit Template Modal -->
        <PdnsZoneTemplateModal
            :show="showTemplateModal"
            :template="editingTemplate"
            :allowed-record-types="allowedRecordTypes"
            :supported-placeholders="supportedPlaceholders"
            @close="showTemplateModal = false"
        />

        <!-- Delete Template Confirmation Dialog -->
        <UiConfirmDialog
            :show="Boolean(templateToDelete)"
            :title="__('Delete DNS Zone Template')"
            :message="
                templateToDelete
                    ? `Are you sure you want to delete the '${templateToDelete.name}' zone template? Existing DNS zones in PowerDNS will not be affected.`
                    : ''
            "
            :confirm-label="__('Delete Template')"
            variant="danger"
            :loading="deleting"
            @confirm="confirmDeleteTemplate"
            @cancel="templateToDelete = null"
        />
    </div>
</template>
