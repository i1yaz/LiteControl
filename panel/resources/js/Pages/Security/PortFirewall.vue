<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ShieldCheck, ShieldAlert, Server } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiButton from '@shared/Ui/UiButton.vue';

const props = defineProps({
    standardPorts: Array,
    activeRules: Array,
});

const isPortOpen = (port) => {
    return (props.activeRules || []).some(r => {
        const to = r.to || '';
        return to.startsWith(port + '/') || to === port;
    });
};

const togglePort = async (item) => {
    const open = isPortOpen(item.port);
    if (open) {
        if (await confirmDialog({
            title: 'Close Port',
            message: `Close port ${item.port} (${item.name})?`,
            confirmText: 'Close Port',
            variant: 'danger',
        })) {
            router.delete(`/security/ufw/rules/${item.port}`);
        }
    } else {
        router.post('/security/ufw/rules', {
            name: item.name,
            port: item.port,
            protocol: item.protocol,
            action: 'allow',
            source_ip: null,
        });
    }
};
</script>

<template>
    <Head :title="__('Port Firewall')" />

    <div class="max-w-4xl mx-auto space-y-6">
        <UiPageHeader
            :title="__('Port Firewall (Service Presets)')"
            subtitle="Quick 1-click firewall control for standard web hosting protocols and ports."
        >
            <template #icon>
                <Server class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Ports Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <UiCard
                v-for="p in standardPorts"
                :key="p.port"
            >
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3.5">
                        <div
                            :class="[
                                'p-2.5 rounded-theme-md border',
                                isPortOpen(p.port)
                                    ? 'bg-theme-success-bg text-theme-success-text border-theme-success-border'
                                    : 'bg-theme-danger-bg text-theme-danger-text border-theme-danger-border'
                            ]"
                        >
                            <ShieldCheck v-if="isPortOpen(p.port)" class="w-5 h-5" />
                            <ShieldAlert v-else class="w-5 h-5" />
                        </div>
                        <div>
                            <p class="text-sm font-bold text-theme-text-primary">{{ p.name }}</p>
                            <p class="text-xs text-theme-text-secondary font-mono">Port {{ p.port }}/{{ p.protocol }}</p>
                        </div>
                    </div>

                    <UiButton
                        size="sm"
                        :variant="isPortOpen(p.port) ? 'danger-soft' : 'primary'"
                        @click="togglePort(p)"
                    >
                        {{ isPortOpen(p.port) ? 'Close Port' : 'Open Port' }}
                    </UiButton>
                </div>
            </UiCard>
        </div>
    </div>
</template>
