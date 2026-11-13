<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { KeyRound, ShieldCheck } from '@lucide/vue';

const useRecoveryCode = ref(false);

const form = useForm({
    code: '',
    recovery_code: '',
});

const toggleMode = () => {
    useRecoveryCode.value = !useRecoveryCode.value;
    form.code = '';
    form.recovery_code = '';
    form.clearErrors();
};

const submit = () => {
    form.post('/two-factor-challenge', {
        onFinish: () => {
            form.reset('code', 'recovery_code');
        },
    });
};
</script>

<template>
    <Head title="Two-Factor Challenge" />

    <div class="min-h-screen bg-slate-950 flex flex-col items-center justify-center p-4">
        <div class="w-full max-w-md bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-2xl space-y-6">
            <div class="flex flex-col items-center text-center space-y-2">
                <div class="p-3 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400">
                    <ShieldCheck class="w-7 h-7" />
                </div>
                <h1 class="text-xl font-bold text-white">Two-Factor Authentication</h1>
                <p class="text-xs sm:text-sm text-slate-400">
                    {{
                        useRecoveryCode
                            ? 'Enter one of your emergency recovery codes to access your account.'
                            : 'Enter the 6-digit verification code from your authenticator app.'
                    }}
                </p>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div v-if="!useRecoveryCode" class="space-y-1.5">
                    <label for="code" class="block text-xs font-semibold text-slate-300">
                        Authentication Code
                    </label>
                    <input
                        id="code"
                        v-model="form.code"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        placeholder="000000"
                        autofocus
                        class="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-center tracking-[0.35em] font-mono text-lg focus:outline-none focus:border-emerald-500 transition-colors"
                    />
                </div>

                <div v-else class="space-y-1.5">
                    <label for="recovery_code" class="block text-xs font-semibold text-slate-300">
                        Recovery Code
                    </label>
                    <input
                        id="recovery_code"
                        v-model="form.recovery_code"
                        type="text"
                        autocomplete="off"
                        placeholder="XXXX-XXXX"
                        autofocus
                        class="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-center tracking-widest font-mono text-sm uppercase focus:outline-none focus:border-emerald-500 transition-colors"
                    />
                </div>

                <p v-if="form.errors.code" class="text-xs text-rose-400 text-center">
                    {{ form.errors.code }}
                </p>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full py-2.5 px-4 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white font-semibold text-sm rounded-xl transition-colors shadow-lg shadow-emerald-600/20"
                >
                    {{ form.processing ? 'Verifying...' : 'Verify & Sign In' }}
                </button>
            </form>

            <div class="flex items-center justify-between pt-2 border-t border-slate-800 text-xs">
                <button
                    type="button"
                    @click="toggleMode"
                    class="text-slate-400 hover:text-white transition-colors flex items-center gap-1.5"
                >
                    <KeyRound class="w-3.5 h-3.5" />
                    <span>{{ useRecoveryCode ? 'Use authenticator code' : 'Use a recovery code' }}</span>
                </button>

                <Link href="/login" class="text-slate-400 hover:text-white transition-colors">
                    Back to login
                </Link>
            </div>
        </div>
    </div>
</template>

