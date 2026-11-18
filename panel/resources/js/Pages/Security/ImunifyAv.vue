<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download, CheckCircle2, Bug } from '@lucide/vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiButton from '@shared/Ui/UiButton.vue';

const props = defineProps({
    installed: Boolean,
    status: String,
});

const installImunify = () => {
    router.post('/modules/imunifyav/install');
};
</script>

<template>
    <Head :title="__('ImunifyAV Antivirus')" />

    <div class="max-w-4xl mx-auto space-y-6">
        <UiPageHeader
            :title="__('ImunifyAV Antivirus & Malware Scanner')"
            subtitle="Automated malicious file detection, webshell scanning, and antivirus database for hosted domains."
        >
            <template #icon>
                <Bug class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Installed State -->
        <UiCard v-if="installed" class="space-y-6">
            <div class="flex items-center gap-4">
                <div class="p-3 rounded-theme-lg bg-theme-success-bg text-theme-success-text border border-theme-success-border">
                    <CheckCircle2 class="w-7 h-7" />
                </div>
                <div>
                    <h2 class="text-lg font-bold text-theme-text-primary">{{ __('ImunifyAV Scanner Active') }}</h2>
                    <p class="text-xs text-theme-text-secondary mt-0.5">{{ __('Antivirus signatures and background daemon active on host system.') }}</p>
                </div>
            </div>

            <div class="p-4 rounded-theme-md bg-theme-bg-surface border border-theme-border text-xs text-theme-text-secondary font-mono space-y-1">
                <p>{{ __('Status:') }} <span class="text-theme-success-text font-bold uppercase">{{ status }}</span></p>
                <p>{{ __('Scanner: imunify-antivirus (active)') }}</p>
                <p>{{ __('Signatures: Daily cloud definitions synchronized') }}</p>
            </div>
        </UiCard>

        <!-- Not Installed State -->
        <UiCard v-else class="p-8 text-center space-y-6">
            <div class="w-16 h-16 rounded-theme-lg bg-theme-accent-subtle border border-theme-accent-border text-theme-accent-text flex items-center justify-center mx-auto">
                <Bug class="w-8 h-8" />
            </div>
            <div class="max-w-md mx-auto space-y-2">
                <h2 class="text-lg font-bold text-theme-text-primary">{{ __('ImunifyAV is Not Installed') }}</h2>
                <p class="text-xs text-theme-text-secondary leading-relaxed">
                    ImunifyAV is an optional module in LiteControl providing high-precision malware scanning across all web root directories and PHP scripts.
                </p>
            </div>
            <div>
                <UiButton
                    variant="primary"
                    @click="installImunify"
                >
                    <Download class="w-4 h-4" />
                    <span>{{ __('Install ImunifyAV Module') }}</span>
                </UiButton>
            </div>
        </UiCard>
    </div>
</template>
