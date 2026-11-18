<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ShieldCheck, ShieldAlert, KeyRound, Copy, Check } from '@lucide/vue';
import { generateQrSvgData } from '@shared/Utils/qrcode';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiInput from '@shared/Ui/UiInput.vue';

const props = defineProps({
    enabled: Boolean,
    secret: {
        type: String,
        default: null,
    },
    otpauthUri: {
        type: String,
        default: null,
    },
    recoveryCodes: {
        type: Array,
        default: null,
    },
});

const copied = ref(false);

const qrCode = computed(() => {
    if (!props.otpauthUri) return null;
    try {
        return generateQrSvgData(props.otpauthUri);
    } catch {
        return null;
    }
});

const confirmForm = useForm({
    code: '',
});

const confirmEnable = () => {
    confirmForm.post('/security/two-factor/confirm', {
        onSuccess: () => confirmForm.reset('code'),
    });
};

const disable = () => {
    router.post('/security/two-factor/toggle');
};

const copySecret = async () => {
    if (!props.secret) return;
    await navigator.clipboard.writeText(props.secret);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 2000);
};
</script>

<template>
    <Head :title="__('Two-Factor Authentication')" />

    <div class="max-w-2xl mx-auto space-y-6">
        <UiPageHeader
            :title="__('Two-Factor Authentication (2FA)')"
            subtitle="Protect your account with Time-based One-Time Password (TOTP) authentication using Google Authenticator or Authy."
        >
            <template #icon>
                <KeyRound class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Status Card -->
        <UiCard class="space-y-6">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3.5">
                    <div
                        :class="[
                            'p-3 rounded-theme-lg border',
                            enabled
                                ? 'bg-theme-success-bg text-theme-success-text border-theme-success-border'
                                : 'bg-theme-danger-bg text-theme-danger-text border-theme-danger-border'
                        ]"
                    >
                        <ShieldCheck v-if="enabled" class="w-6 h-6" />
                        <ShieldAlert v-else class="w-6 h-6" />
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-theme-text-primary">
                            Two-Factor Authentication is {{ enabled ? 'Enabled' : 'Disabled' }}
                        </h2>
                        <p class="text-xs text-theme-text-secondary mt-0.5">
                            {{
                                enabled
                                    ? 'Your account requires an authenticator 6-digit code on every login.'
                                    : 'Add an extra layer of protection to secure your administrator privileges.'
                            }}
                        </p>
                    </div>
                </div>

                <UiButton
                    v-if="enabled"
                    variant="danger-soft"
                    @click="disable"
                >
                    Disable 2FA
                </UiButton>
            </div>

            <!-- Setup Flow when Disabled -->
            <div v-if="!enabled && secret" class="pt-6 border-t border-theme-border-subtle space-y-5">
                <div class="space-y-3">
                    <h3 class="text-sm font-semibold text-theme-text-primary">{{ __('1. Add to Authenticator App') }}</h3>
                    <p class="text-xs text-theme-text-secondary">
                        Scan this QR code with Google Authenticator, Authy, or 1Password, or enter the setup key manually:
                    </p>
                    <div v-if="qrCode" class="inline-block p-3 bg-white rounded-theme-md border border-theme-border">
                        <svg
                            :viewBox="qrCode.viewBox"
                            class="w-40 h-40 block"
                            shape-rendering="crispEdges"
                            role="img"
                            :aria-label="__('Two-Factor Authentication QR Code')"
                        >
                            <path :d="qrCode.path" fill="#000000" />
                        </svg>
                    </div>
                    <div class="flex items-center gap-2">
                        <code class="flex-1 px-3 py-2 bg-theme-bg-surface border border-theme-border rounded-theme-md text-theme-success-text font-mono text-sm tracking-wider select-all">
                            {{ secret }}
                        </code>
                        <UiButton
                            type="button"
                            variant="secondary"
                            @click="copySecret"
                        >
                            <Check v-if="copied" class="w-4 h-4 text-theme-success-text" />
                            <Copy v-else class="w-4 h-4" />
                            <span>{{ copied ? 'Copied' : 'Copy' }}</span>
                        </UiButton>
                    </div>
                </div>

                <form @submit.prevent="confirmEnable" class="space-y-3">
                    <h3 class="text-sm font-semibold text-theme-text-primary">{{ __('2. Verify &amp; Enable') }}</h3>
                    <p class="text-xs text-theme-text-secondary">
                        Enter the 6-digit code generated by your authenticator app to confirm setup.
                    </p>
                    <div class="flex flex-col sm:flex-row sm:items-start gap-3">
                        <UiInput
                            v-model="confirmForm.code"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="6"
                            placeholder="000000"
                            :error="confirmForm.errors.code"
                            class="sm:w-48 text-center tracking-[0.3em] font-mono"
                        />
                        <UiButton
                            type="submit"
                            variant="primary"
                            :loading="confirmForm.processing"
                        >
                            {{ confirmForm.processing ? 'Verifying...' : 'Confirm & Enable 2FA' }}
                        </UiButton>
                    </div>
                </form>
            </div>

            <!-- Recovery Codes (shown once after enabling) -->
            <div v-if="enabled && recoveryCodes && recoveryCodes.length" class="pt-6 border-t border-theme-border-subtle space-y-3">
                <h3 class="text-sm font-semibold text-theme-warning-text">{{ __('Save Your Recovery Codes') }}</h3>
                <p class="text-xs text-theme-text-secondary">
                    Store these recovery codes in a safe place. Each code can be used once if you lose access to your authenticator app.
                </p>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 p-4 bg-theme-bg-surface border border-theme-border rounded-theme-md font-mono text-xs text-theme-text-primary">
                    <div v-for="code in recoveryCodes" :key="code" class="text-center py-1">
                        {{ code }}
                    </div>
                </div>
            </div>
        </UiCard>
    </div>
</template>
