<script setup lang="ts">
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ShieldCheck, ShieldAlert, KeyRound, Copy, Check } from '@lucide/vue';

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
    <Head title="Two-Factor Authentication" />

    <div class="max-w-2xl mx-auto space-y-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                    <KeyRound class="w-6 h-6 text-rose-400" />
                    <span>Two-Factor Authentication (2FA)</span>
                </h1>
                <p class="text-xs sm:text-sm text-slate-400 mt-1">
                    Protect your account with Time-based One-Time Password (TOTP) authentication using Google Authenticator or Authy.
                </p>
            </div>
        </div>

        <!-- Status Card -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3.5">
                    <div
                        :class="[
                            'p-3 rounded-2xl border',
                            enabled
                                ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
                                : 'bg-rose-500/10 text-rose-400 border-rose-500/20'
                        ]"
                    >
                        <ShieldCheck v-if="enabled" class="w-6 h-6" />
                        <ShieldAlert v-else class="w-6 h-6" />
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-white">
                            Two-Factor Authentication is {{ enabled ? 'Enabled' : 'Disabled' }}
                        </h2>
                        <p class="text-xs text-slate-400 mt-0.5">
                            {{
                                enabled
                                    ? 'Your account requires an authenticator 6-digit code on every login.'
                                    : 'Add an extra layer of protection to secure your administrator privileges.'
                            }}
                        </p>
                    </div>
                </div>

                <button
                    v-if="enabled"
                    @click="disable"
                    class="px-4 py-2 rounded-xl text-xs font-bold transition-all shadow-lg bg-rose-600/20 hover:bg-rose-600 text-rose-300 hover:text-white border border-rose-500/30"
                >
                    Disable 2FA
                </button>
            </div>

            <!-- Setup Flow when Disabled -->
            <div v-if="!enabled && secret" class="pt-6 border-t border-slate-800 space-y-5">
                <div class="space-y-2">
                    <h3 class="text-sm font-semibold text-white">1. Add to Authenticator App</h3>
                    <p class="text-xs text-slate-400">
                        Enter this setup key manually in Google Authenticator, Authy, or 1Password:
                    </p>
                    <div class="flex items-center gap-2">
                        <code class="flex-1 px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-emerald-400 font-mono text-sm tracking-wider select-all">
                            {{ secret }}
                        </code>
                        <button
                            type="button"
                            @click="copySecret"
                            class="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-medium flex items-center gap-1.5 transition-colors"
                        >
                            <Check v-if="copied" class="w-4 h-4 text-emerald-400" />
                            <Copy v-else class="w-4 h-4" />
                            <span>{{ copied ? 'Copied' : 'Copy' }}</span>
                        </button>
                    </div>
                </div>

                <form @submit.prevent="confirmEnable" class="space-y-3">
                    <h3 class="text-sm font-semibold text-white">2. Verify &amp; Enable</h3>
                    <p class="text-xs text-slate-400">
                        Enter the 6-digit code generated by your authenticator app to confirm setup.
                    </p>
                    <div class="flex flex-col sm:flex-row gap-3">
                        <input
                            v-model="confirmForm.code"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="6"
                            placeholder="000000"
                            class="sm:w-48 px-4 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white text-center tracking-[0.3em] font-mono text-sm focus:outline-none focus:border-emerald-500"
                        />
                        <button
                            type="submit"
                            :disabled="confirmForm.processing"
                            class="px-5 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white rounded-xl text-xs font-bold transition-all shadow-lg shadow-emerald-600/20"
                        >
                            {{ confirmForm.processing ? 'Verifying...' : 'Confirm & Enable 2FA' }}
                        </button>
                    </div>
                    <p v-if="confirmForm.errors.code" class="text-xs text-rose-400">
                        {{ confirmForm.errors.code }}
                    </p>
                </form>
            </div>

            <!-- Recovery Codes (shown once after enabling) -->
            <div v-if="enabled && recoveryCodes && recoveryCodes.length" class="pt-6 border-t border-slate-800 space-y-3">
                <h3 class="text-sm font-semibold text-amber-400">Save Your Recovery Codes</h3>
                <p class="text-xs text-slate-400">
                    Store these recovery codes in a safe place. Each code can be used once if you lose access to your authenticator app.
                </p>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 p-4 bg-slate-950 border border-slate-800 rounded-xl font-mono text-xs text-slate-200">
                    <div v-for="code in recoveryCodes" :key="code" class="text-center py-1">
                        {{ code }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
