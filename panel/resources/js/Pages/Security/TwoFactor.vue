<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ShieldCheck, ShieldAlert, KeyRound, QrCode } from 'lucide-vue-next';

const props = defineProps({
    enabled: Boolean,
});

const toggle = () => {
    router.post('/security/two-factor/toggle');
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
                <div class="flex items-center justify-between">
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
                                {{ enabled ? 'Your account requires an authenticator 6-digit code on every login.' : 'Add an extra layer of protection to secure your administrator privileges.' }}
                            </p>
                        </div>
                    </div>

                    <button
                        @click="toggle"
                        :class="[
                            'px-4 py-2 rounded-xl text-xs font-bold transition-all shadow-lg',
                            enabled
                                ? 'bg-rose-600/20 hover:bg-rose-600 text-rose-300 hover:text-white border border-rose-500/30'
                                : 'bg-emerald-600 hover:bg-emerald-500 text-white shadow-emerald-600/20'
                        ]"
                    >
                        {{ enabled ? 'Disable 2FA' : 'Enable 2FA' }}
                    </button>
                </div>
            </div>
        </div>
</template>

