<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Layers } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiAlert from '@shared/Ui/UiAlert.vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import ModuleCard from '@domains/ServerOps/Components/ModuleCard.vue';
import ModuleInstallModal from '@domains/ServerOps/Components/ModuleInstallModal.vue';
import ModuleUninstallModal from '@domains/ServerOps/Components/ModuleUninstallModal.vue';

const props = defineProps({
    modules: {
        type: Array,
        default: () => [],
    },
    server_ram_mb: {
        type: Number,
        default: 0,
    },
    installingModule: {
        type: String,
        default: null,
    },
});

const installModalModule = ref<any>(null);
const uninstallModalModule = ref<any>(null);
let pollTimer: ReturnType<typeof setInterval> | null = null;

const inFlightModules = computed(() =>
    (props.modules as any[]).filter((m) => m.status === 'pending' || m.status === 'provisioning')
);

const hasInFlight = computed(() => inFlightModules.value.length > 0 || Boolean(props.installingModule));

const stopPolling = () => {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
};

const startPolling = () => {
    if (pollTimer !== null) return;
    pollTimer = setInterval(() => {
        router.reload({ only: ['modules', 'installingModule'] });
    }, 5000);
};

watch(
    hasInFlight,
    (active) => {
        if (active) {
            startPolling();
        } else {
            stopPolling();
        }
    },
    { immediate: true }
);

onUnmounted(() => {
    stopPolling();
});

const handleInstallClick = async (mod: any) => {
    if (mod.key === 'mail_core' || mod.key === 'mail_webmail') {
        installModalModule.value = mod;
        return;
    }

    if (
        await confirmDialog({
            title: 'Install Module',
            message: `Install optional module '${mod.title || mod.key}'? This will download packages and configure system services.`,
            confirmText: 'Install',
        })
    ) {
        router.post(`/modules/${mod.key}/install`, {}, { preserveScroll: true });
    }
};

const handleUninstallClick = (mod: any) => {
    uninstallModalModule.value = mod;
};
</script>

<template>
    <Head :title="__('Module Manager')" />

    <div class="max-w-5xl mx-auto space-y-6">
        <UiPageHeader
            :title="__('On-Demand Module Manager')"
            subtitle="Extend LiteControl capabilities on-demand without bloating the core OpenLiteSpeed installation."
        >
            <template #icon>
                <Layers class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <div aria-live="polite">
            <UiAlert v-if="hasInFlight" variant="info">
                Provisioning in progress for
                <strong>{{ inFlightModules.map((m) => m.title || m.key).join(', ') || installingModule }}</strong>.
                Status refreshes automatically every 5 seconds.
            </UiAlert>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <ModuleCard
                v-for="mod in modules"
                :key="(mod as any).key || (mod as any).name"
                :module="(mod as any)"
                :all-modules="modules"
                :server-ram-mb="server_ram_mb"
                :busy="hasInFlight"
                @install="handleInstallClick"
                @uninstall="handleUninstallClick"
            />
        </div>

        <ModuleInstallModal
            :show="Boolean(installModalModule)"
            :module="installModalModule"
            @close="installModalModule = null"
        />

        <ModuleUninstallModal
            :show="Boolean(uninstallModalModule)"
            :module="uninstallModalModule"
            @close="uninstallModalModule = null"
        />
    </div>
</template>
