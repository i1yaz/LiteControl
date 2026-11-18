<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ShieldCheck, Download, CheckCircle2 } from '@lucide/vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiButton from '@shared/Ui/UiButton.vue';

const props = defineProps({
    installed: Boolean,
    status: String,
});

const installModSec = () => {
    router.post('/modules/modsecurity/install');
};
</script>

<template>
    <Head :title="__('ModSecurity WAF')" />

    <div class="max-w-4xl mx-auto space-y-6">
        <UiPageHeader
            :title="__('ModSecurity (WAF) & OWASP CRS')"
            subtitle="Web Application Firewall natively compiled into OpenLiteSpeed defending against SQLi, XSS, and RCE attacks."
        >
            <template #icon>
                <ShieldCheck class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Installed State -->
        <UiCard v-if="installed && status !== 'not_installed'" class="space-y-6">
            <div class="flex items-center gap-4">
                <div class="p-3 rounded-theme-lg bg-theme-success-bg text-theme-success-text border border-theme-success-border">
                    <CheckCircle2 class="w-7 h-7" />
                </div>
                <div>
                    <h2 class="text-lg font-bold text-theme-text-primary">{{ __('ModSecurity WAF Active') }}</h2>
                    <p class="text-xs text-theme-text-secondary mt-0.5">{{ __('ModSecurity engine module is enabled in /usr/local/lsws/conf/httpd_config.conf with OWASP CRS v3 ruleset.') }}</p>
                </div>
            </div>

            <div class="p-4 rounded-theme-md bg-theme-bg-surface border border-theme-border text-xs text-theme-text-secondary font-mono space-y-1">
                <p>{{ __('Status:') }} <span class="text-theme-success-text font-bold uppercase">{{ status }}</span></p>
                <p>{{ __('Ruleset: OWASP Core Rule Set (CRS 3.3.x)') }}</p>
                <p>{{ __('Mode: SecRuleEngine On (Blocking & Logging)') }}</p>
            </div>
        </UiCard>

        <!-- Not Installed State -->
        <UiCard v-else class="p-8 text-center space-y-6">
            <div class="w-16 h-16 rounded-theme-lg bg-theme-accent-subtle border border-theme-accent-border text-theme-accent-text flex items-center justify-center mx-auto">
                <ShieldCheck class="w-8 h-8" />
            </div>
            <div class="max-w-md mx-auto space-y-2">
                <h2 class="text-lg font-bold text-theme-text-primary">{{ __('ModSecurity is Not Installed') }}</h2>
                <p class="text-xs text-theme-text-secondary leading-relaxed">
                    ModSecurity is an on-demand security module in LiteControl. Installing it loads the OpenLiteSpeed mod_security external engine and downloads the OWASP Core Rule Set automatically.
                </p>
            </div>
            <div>
                <UiButton
                    variant="primary"
                    @click="installModSec"
                >
                    <Download class="w-4 h-4" />
                    <span>{{ __('Install ModSecurity Module') }}</span>
                </UiButton>
            </div>
        </UiCard>
    </div>
</template>
