<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    Radio,
    Plus,
    Trash2,
    AlertTriangle,
    Info,
    CheckCircle2,
    Server,
    Globe,
    Settings,
    UserCheck,
} from '@lucide/vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
import UiDataTable from '@shared/Ui/UiDataTable.vue';
import { useDialog } from '@shared/Composables/useDialog';

const props = defineProps({
    autoprimaries: {
        type: Array as () => Array<{
            ip: string;
            nameserver: string;
            display_nameserver?: string;
            is_idn?: boolean;
            account?: string;
        }>,
        default: () => [],
    },
    capabilities: {
        type: Object as () => Record<string, any>,
        default: () => ({
            has_api_key: false,
            primary_enabled: true,
            secondary_enabled: false,
            autosecondary_enabled: false,
        }),
    },
    showSettingsLink: {
        type: Boolean,
        default: true,
    },
    apiError: {
        type: String,
        default: null,
    },
});

const { confirm } = useDialog();

const deletingKey = ref<string | null>(null);

const isSecondaryEnabled = computed(() => Boolean(props.capabilities?.secondary_enabled));
const isAutosecondaryEnabled = computed(() => Boolean(props.capabilities?.autosecondary_enabled));
const isCapabilityReady = computed(() => isSecondaryEnabled.value && isAutosecondaryEnabled.value);

const form = useForm({
    ip: '',
    nameserver: '',
    account: '',
});

const tableHeaders = [
    { label: 'Primary Server IP' },
    { label: 'Expected Apex NS Record (Nameserver)' },
    { label: 'Account / Label' },
    { label: 'Actions', class: 'text-right' },
];

const submitCreate = () => {
    form.post('/dns/autoprimaries', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        },
    });
};

const handleDelete = async (entry: { ip: string; nameserver: string }) => {
    const confirmed = await confirm({
        title: 'Remove Autoprimary (Supermaster)',
        message: `Remove trusted autoprimary ${entry.ip} (${entry.nameserver})?\n\nExisting secondary zones already provisioned from this primary will not be deleted, but future unknown zones notified from this IP will no longer auto-provision.`,
        confirmText: 'Remove Autoprimary',
        cancelText: 'Cancel',
        variant: 'danger',
    });

    if (!confirmed) {
        return;
    }

    const rowKey = `${entry.ip}|${entry.nameserver}`;
    deletingKey.value = rowKey;

    const encodedIp = encodeURIComponent(entry.ip);
    const encodedNs = encodeURIComponent(entry.nameserver);

    router.delete(`/dns/autoprimaries/${encodedIp}/${encodedNs}`, {
        preserveScroll: true,
        onFinish: () => {
            deletingKey.value = null;
        },
    });
};
</script>

<template>
    <div class="space-y-6">
        <!-- Global Capability Guardrail Banner -->
        <UiAlert v-if="!isCapabilityReady" variant="warning">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-start gap-2.5">
                    <AlertTriangle class="w-4 h-4 shrink-0 mt-0.5" />
                    <div class="space-y-1">
                        <div class="font-semibold">
                            {{ __('Autosecondary Provisioning Requires Global Daemon Capabilities') }}
                        </div>
                        <p class="text-xs leading-relaxed">
                            {{
                                __('For PowerDNS to automatically accept DNS NOTIFY packets from trusted Autoprimaries and provision new Secondary zones, both secondary=yes and autosecondary=yes must be enabled in 99-litecontrol.conf.')
                            }}
                        </p>
                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            <UiBadge :variant="isSecondaryEnabled ? 'success' : 'danger'">
                                <span>secondary={{ isSecondaryEnabled ? 'yes' : 'no' }}</span>
                            </UiBadge>
                            <UiBadge :variant="isAutosecondaryEnabled ? 'success' : 'danger'">
                                <span>autosecondary={{ isAutosecondaryEnabled ? 'yes' : 'no' }}</span>
                            </UiBadge>
                        </div>
                    </div>
                </div>

                <Link
                    v-if="showSettingsLink"
                    href="/dns/settings"
                    class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-theme-sm text-xs font-semibold bg-theme-bg-card border border-theme-border text-theme-text-primary hover:bg-theme-bg-surface"
                >
                    <Settings class="w-3.5 h-3.5" />
                    <span>{{ __('Enable in Daemon Settings') }}</span>
                </Link>
            </div>
        </UiAlert>

        <!-- Newcomer Gotcha: Apex NS Match Guidance -->
        <UiAlert variant="info">
            <div class="flex items-start gap-2.5">
                <Info class="w-4 h-4 shrink-0 mt-0.5" />
                <div class="space-y-1 text-xs leading-relaxed">
                    <div class="font-semibold">
                        {{ __('Important Autoprimary (Supermaster) Requirement — Apex NS Match') }}
                    </div>
                    <p>
                        {{
                            __('When an upstream primary server sends a DNS NOTIFY for a new zone, PowerDNS inspects the incoming zone\'s apex NS records. The configured Nameserver FQDN below must match an NS record present inside the incoming zone\'s apex on the primary server, or PowerDNS will silently reject automatic secondary provisioning.')
                        }}
                    </p>
                </div>
            </div>
        </UiAlert>

        <!-- API Error Alert if Autoprimaries Endpoint Failed -->
        <UiAlert v-if="apiError" variant="warning">
            <div class="text-xs break-all">
                {{ apiError }}
            </div>
        </UiAlert>

        <!-- Add Autoprimary Form Card -->
        <UiCard
            :title="__('Register Trusted Autoprimary (Supermaster)')"
            :subtitle="__('Authorize an upstream primary DNS server IP to automatically provision Secondary zones via DNS NOTIFY (/api/v1/servers/localhost/autoprimaries).')"
        >
            <template #header>
                <UiBadge :variant="isCapabilityReady ? 'success' : 'warning'">
                    <CheckCircle2 v-if="isCapabilityReady" class="w-3.5 h-3.5" />
                    <AlertTriangle v-else class="w-3.5 h-3.5" />
                    <span>
                        {{
                            isCapabilityReady
                                ? __('Autosecondary Active')
                                : __('Autosecondary Inactive')
                        }}
                    </span>
                </UiBadge>
            </template>

            <form @submit.prevent="submitCreate" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <UiInput
                            id="autoprimary-ip"
                            v-model="form.ip"
                            :label="__('Upstream Primary IP Address')"
                            placeholder="203.0.113.10 or 2001:db8::53"
                            :error="form.errors.ip"
                            required
                        />
                        <p class="mt-1 text-[11px] text-theme-text-muted">
                            {{ __('Source IPv4 or IPv6 address of the upstream primary nameserver sending NOTIFY.') }}
                        </p>
                    </div>

                    <div>
                        <UiInput
                            id="autoprimary-nameserver"
                            v-model="form.nameserver"
                            :label="__('Expected Apex NS Hostname (FQDN)')"
                            placeholder="ns2.paneldomain.com."
                            :error="form.errors.nameserver"
                            required
                        />
                        <p class="mt-1 text-[11px] text-theme-text-muted">
                            {{ __('Must match an NS record inside the incoming zone on the primary server.') }}
                        </p>
                    </div>

                    <div>
                        <UiInput
                            id="autoprimary-account"
                            v-model="form.account"
                            :label="__('Account / Cluster Tag (Optional)')"
                            placeholder="primary-dc1"
                            :error="form.errors.account"
                        />
                        <p class="mt-1 text-[11px] text-theme-text-muted">
                            {{ __('Optional alphanumeric identifier assigned to auto-created secondary zones.') }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center justify-end pt-2 border-t border-theme-border-subtle">
                    <UiButton
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                        :disabled="form.processing"
                    >
                        <Plus v-if="!form.processing" class="w-4 h-4" />
                        <span>{{ form.processing ? __('Registering...') : __('Add Autoprimary') }}</span>
                    </UiButton>
                </div>
            </form>
        </UiCard>

        <!-- Configured Autoprimaries Table -->
        <UiDataTable
            :headers="tableHeaders"
            :items-count="autoprimaries.length"
            :empty-message="__('No Autoprimaries (Supermasters) configured yet.')"
        >
            <template #toolbar>
                <div class="flex items-center justify-between w-full">
                    <div class="text-xs font-semibold text-theme-text-secondary flex items-center gap-2">
                        <Radio class="w-4 h-4 text-theme-accent" />
                        <span>{{ __('Configured Autoprimaries') }} ({{ autoprimaries.length }})</span>
                    </div>
                </div>
            </template>

            <tr
                v-for="entry in autoprimaries"
                :key="`${entry.ip}|${entry.nameserver}`"
                class="hover:bg-theme-bg-surface/40 transition-colors"
            >
                <td class="py-3.5 px-4">
                    <div class="flex items-center gap-2 font-mono text-xs font-semibold text-theme-text-primary">
                        <Server class="w-3.5 h-3.5 text-theme-text-muted" />
                        <span>{{ entry.ip }}</span>
                    </div>
                </td>

                <td class="py-3.5 px-4">
                    <div class="flex flex-col">
                        <div class="flex items-center gap-2">
                            <Globe class="w-3.5 h-3.5 text-theme-text-muted" />
                            <span class="font-mono text-xs font-semibold text-theme-text-primary">
                                {{ entry.nameserver }}
                            </span>
                            <UiBadge v-if="entry.is_idn" variant="info">
                                IDN
                            </UiBadge>
                        </div>
                        <span
                            v-if="entry.is_idn && entry.display_nameserver"
                            class="text-xs text-theme-text-secondary mt-0.5 pl-5"
                        >
                            {{ entry.display_nameserver }}
                        </span>
                    </div>
                </td>

                <td class="py-3.5 px-4">
                    <UiBadge v-if="entry.account" variant="neutral">
                        <UserCheck class="w-3 h-3" />
                        <span class="font-mono text-xs">{{ entry.account }}</span>
                    </UiBadge>
                    <span v-else class="text-xs text-theme-text-muted">—</span>
                </td>

                <td class="py-3.5 px-4 text-right">
                    <UiButton
                        type="button"
                        variant="danger"
                        size="sm"
                        :loading="deletingKey === `${entry.ip}|${entry.nameserver}`"
                        :disabled="deletingKey === `${entry.ip}|${entry.nameserver}`"
                        @click="handleDelete(entry)"
                    >
                        <Trash2 class="w-3.5 h-3.5" />
                        <span>{{ __('Delete') }}</span>
                    </UiButton>
                </td>
            </tr>
        </UiDataTable>
    </div>
</template>
