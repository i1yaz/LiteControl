<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Server, Play, Square, RotateCw, ShieldCheck, ShieldAlert, ExternalLink, Lock } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiDataTable from '@shared/Ui/UiDataTable.vue';

const props = defineProps({
    services: Array,
    webAdmin: Object,
});

const serviceAction = async (service, action) => {
    if (action === 'stop' && !(await confirmDialog({
        title: 'Stop Service',
        message: `Are you sure you want to STOP ${service}? This may disrupt hosted sites.`,
        confirmText: 'Stop Service',
        variant: 'danger',
    }))) {
        return;
    }
    router.post(`/server/services/${service}/${action}`);
};

const form = useForm({
    allowed_ips: props.webAdmin?.allowed_ips || '',
});

const toggleWebAdmin = async () => {
    if (props.webAdmin?.enabled) {
        if (!(await confirmDialog({
            title: 'Disable WebAdmin',
            message: 'Disable OpenLiteSpeed WebAdmin console on port ' + (props.webAdmin?.port || 7080) + '?',
            confirmText: 'Disable',
            variant: 'warning',
        }))) {
            return;
        }
        router.post('/server/webadmin/disable');
    } else {
        form.post('/server/webadmin/enable');
    }
};

const updateWebAdminIps = () => {
    form.post('/server/webadmin/enable');
};
</script>

<template>
    <Head :title="__('Services & WebAdmin Manager')" />

    <div class="space-y-6">
        <UiPageHeader
            :title="__('Services & WebAdmin Manager')"
            subtitle="Monitor system daemons and manage OpenLiteSpeed native WebAdmin console access."
        >
            <template #icon>
                <Server class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- OLS WebAdmin Management Card -->
        <UiCard>
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                <div class="space-y-2 max-w-2xl">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-10 h-10 rounded-theme-md flex items-center justify-center border"
                            :class="webAdmin?.enabled ? 'bg-theme-success-bg text-theme-success-text border-theme-success-border' : 'bg-theme-bg-surface text-theme-text-secondary border-theme-border'"
                        >
                            <Lock class="w-5 h-5" />
                        </div>
                        <div>
                            <div class="flex items-center gap-2.5 flex-wrap">
                                <h2 class="text-lg font-bold text-theme-text-primary">{{ __('OpenLiteSpeed WebAdmin Console') }}</h2>
                                <UiBadge :variant="webAdmin?.enabled ? 'success' : 'warning'">
                                    {{ webAdmin?.enabled ? `Enabled (Port ${webAdmin?.port || 7080})` : 'Disabled (Protected)' }}
                                </UiBadge>
                            </div>
                            <p class="text-xs text-theme-text-secondary mt-0.5">
                                {{ webAdmin?.enabled
                                    ? 'Native OLS WebAdmin console is active. Firewall and port rules are synchronized.'
                                    : 'WebAdmin is locked down to prevent brute force and PHP exploit attempts.' }}
                            </p>
                        </div>
                    </div>

                    <!-- IP Whitelist Info / Form -->
                    <div v-if="webAdmin?.enabled" class="pt-2">
                        <form @submit.prevent="updateWebAdminIps" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5">
                            <div class="flex-1">
                                <UiInput
                                    v-model="form.allowed_ips"
                                    type="text"
                                    :placeholder="__('Allowed IPs (comma separated, e.g. 192.0.2.1, 10.0.0.0/24, or empty for all)')"
                                />
                            </div>
                            <UiButton
                                type="submit"
                                variant="secondary"
                                :loading="form.processing"
                            >
                                Update Allowed IPs
                            </UiButton>
                        </form>
                        <p class="text-[11px] text-theme-text-muted mt-1.5">
                            Leave blank to allow connections from any IP. If IPs are listed, clients from unlisted addresses receive a 403 Forbidden response.
                        </p>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-3">
                    <a
                        v-if="webAdmin?.enabled"
                        :href="`https://${typeof window !== 'undefined' ? window.location.hostname : 'localhost'}:${webAdmin?.port || 7080}/`"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="px-4 py-2 rounded-theme-md bg-theme-info-bg hover:bg-theme-info/20 text-theme-info-text border border-theme-info-border text-xs font-semibold flex items-center gap-2 transition-all theme-ui-control"
                    >
                        <ExternalLink class="w-4 h-4" />
                        <span>{{ __('Launch WebAdmin') }}</span>
                    </a>

                    <UiButton
                        @click="toggleWebAdmin"
                        :variant="webAdmin?.enabled ? 'danger-soft' : 'primary'"
                    >
                        <ShieldCheck v-if="!webAdmin?.enabled" class="w-4 h-4" />
                        <ShieldAlert v-else class="w-4 h-4" />
                        <span>{{ webAdmin?.enabled ? 'Disable WebAdmin' : 'Enable WebAdmin' }}</span>
                    </UiButton>
                </div>
            </div>
        </UiCard>

        <!-- Services Table Card -->
        <UiDataTable
            :headers="[
                __('Service'),
                __('Status'),
                { label: __('Actions'), class: 'text-right' }
            ]"
            :items-count="services?.length || 0"
        >
            <tr v-for="svc in services" :key="svc.service || svc.name" class="hover:bg-theme-bg-surface/40 transition-colors">
                <td class="px-4 py-3.5">
                    <div class="flex items-center gap-3">
                        <div class="w-2.5 h-2.5 rounded-full" :class="svc.status === 'active' ? 'bg-theme-success animate-pulse' : 'bg-theme-danger'"></div>
                        <div>
                            <p class="font-semibold text-theme-text-primary capitalize text-sm">{{ svc.service || svc.name }}</p>
                            <p class="text-[11px] text-theme-text-muted">{{ svc.description || 'System service' }}</p>
                        </div>
                    </div>
                </td>
                <td class="px-4 py-3.5">
                    <UiBadge :variant="svc.status === 'active' ? 'success' : 'danger'">
                        {{ svc.status === 'active' ? 'Running' : 'Stopped' }}
                    </UiBadge>
                </td>
                <td class="px-4 py-3.5 text-right">
                    <div class="flex items-center justify-end gap-1.5">
                        <UiButton
                            size="xs"
                            variant="secondary"
                            @click="serviceAction(svc.service || svc.name, 'restart')"
                            :title="__('Restart')"
                        >
                            <RotateCw class="w-3.5 h-3.5 text-theme-accent" />
                            <span>{{ __('Restart') }}</span>
                        </UiButton>
                        <UiButton
                            v-if="svc.status === 'active'"
                            size="xs"
                            variant="danger-soft"
                            @click="serviceAction(svc.service || svc.name, 'stop')"
                            :title="__('Stop Service')"
                        >
                            <Square class="w-3.5 h-3.5" />
                        </UiButton>
                        <UiButton
                            v-else
                            size="xs"
                            variant="primary"
                            @click="serviceAction(svc.service || svc.name, 'start')"
                            :title="__('Start Service')"
                        >
                            <Play class="w-3.5 h-3.5" />
                        </UiButton>
                    </div>
                </td>
            </tr>
        </UiDataTable>
    </div>
</template>
