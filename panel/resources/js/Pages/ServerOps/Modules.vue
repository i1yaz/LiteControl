<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Layers, Download, Trash2 } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';

const props = defineProps({
    modules: Array,
});

const installModule = async (mod) => {
    if (await confirmDialog({
        title: 'Install Module',
        message: `Install optional module '${mod.title || mod.name}'? This will download packages and configure system services.`,
        confirmText: 'Install',
    })) {
        router.post(`/modules/${mod.name}/install`);
    }
};

const removeModule = async (mod) => {
    if (await confirmDialog({
        title: 'Uninstall Module',
        message: `Are you sure you want to uninstall module '${mod.title || mod.name}'?`,
        confirmText: 'Uninstall',
        variant: 'danger',
    })) {
        router.post(`/modules/${mod.name}/remove`);
    }
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

        <!-- Modules Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <UiCard
                v-for="mod in modules"
                :key="mod.name"
                class="flex flex-col justify-between space-y-5 hover:border-theme-border-hover transition-all"
            >
                <div class="space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-base font-bold text-theme-text-primary">{{ mod.title || mod.name }}</h2>
                            <p class="text-[11px] font-mono text-theme-accent-text">module: {{ mod.name }}</p>
                        </div>
                        <UiBadge :variant="mod.installed ? 'success' : 'neutral'">
                            <span class="w-2 h-2 rounded-full" :class="mod.installed ? 'bg-theme-success' : 'bg-theme-text-muted'"></span>
                            <span>{{ mod.installed ? 'Installed' : 'Available' }}</span>
                        </UiBadge>
                    </div>

                    <p class="text-xs text-theme-text-secondary leading-relaxed">
                        {{ mod.description }}
                    </p>
                </div>

                <div class="pt-4 border-t border-theme-border-subtle flex items-center justify-between">
                    <span class="text-[11px] text-theme-text-muted">
                        {{ mod.installed ? 'Active in lc-cli' : 'Click to install on-demand' }}
                    </span>

                    <div class="flex items-center gap-2">
                        <UiButton
                            v-if="!mod.installed"
                            variant="primary"
                            size="sm"
                            @click="installModule(mod)"
                        >
                            <Download class="w-3.5 h-3.5" />
                            <span>{{ __('Install') }}</span>
                        </UiButton>
                        <UiButton
                            v-else
                            variant="danger-soft"
                            size="sm"
                            @click="removeModule(mod)"
                        >
                            <Trash2 class="w-3.5 h-3.5" />
                            <span>{{ __('Uninstall') }}</span>
                        </UiButton>
                    </div>
                </div>
            </UiCard>
        </div>
    </div>
</template>
