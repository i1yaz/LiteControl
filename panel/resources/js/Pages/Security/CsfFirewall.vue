<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Shield, Download, CheckCircle2 } from '@lucide/vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiButton from '@shared/Ui/UiButton.vue';

const props = defineProps({
    installed: Boolean,
    status: String,
});

const installCsf = () => {
    router.post('/modules/csf/install');
};
</script>

<template>
    <Head :title="__('CSF Firewall')" />

    <div class="max-w-4xl mx-auto space-y-6">
        <UiPageHeader
            :title="__('ConfigServer Security & Firewall (CSF)')"
            subtitle="Advanced SPI firewall, Login Failure Daemon (LFD), intrusion prevention, and automated brute-force mitigation."
        >
            <template #icon>
                <Shield class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Installed State -->
        <UiCard v-if="installed" class="space-y-6">
            <div class="flex items-center gap-4">
                <div class="p-3 rounded-theme-lg bg-theme-success-bg text-theme-success-text border border-theme-success-border">
                    <CheckCircle2 class="w-7 h-7" />
                </div>
                <div>
                    <h2 class="text-lg font-bold text-theme-text-primary">{{ __('CSF & LFD Active') }}</h2>
                    <p class="text-xs text-theme-text-secondary mt-0.5">{{ __('CSF stateful packet inspection firewall is installed and synchronizing with OpenLiteSpeed listeners.') }}</p>
                </div>
            </div>

            <div class="p-4 rounded-theme-md bg-theme-bg-surface border border-theme-border text-xs text-theme-text-secondary font-mono space-y-1">
                <p>{{ __('Status:') }} <span class="text-theme-success-text font-bold uppercase">{{ status }}</span></p>
                <p>{{ __('Config file: /etc/csf/csf.conf') }}</p>
                <p>{{ __('Daemon: lfd.service (Running)') }}</p>
            </div>
        </UiCard>

        <!-- Not Installed State -->
        <UiCard v-else class="p-8 text-center space-y-6">
            <div class="w-16 h-16 rounded-theme-lg bg-theme-accent-subtle border border-theme-accent-border text-theme-accent-text flex items-center justify-center mx-auto">
                <Shield class="w-8 h-8" />
            </div>
            <div class="max-w-md mx-auto space-y-2">
                <h2 class="text-lg font-bold text-theme-text-primary">{{ __('CSF Firewall is Not Installed') }}</h2>
                <p class="text-xs text-theme-text-secondary leading-relaxed">
                    CSF is an optional module in LiteControl. It replaces or enhances default UFW rules with deeper deep packet inspection and port scanning defense.
                </p>
            </div>
            <div>
                <UiButton
                    variant="primary"
                    @click="installCsf"
                >
                    <Download class="w-4 h-4" />
                    <span>{{ __('Install CSF Module Now') }}</span>
                </UiButton>
            </div>
        </UiCard>
    </div>
</template>
