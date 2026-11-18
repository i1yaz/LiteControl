<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { KeyRound, ShieldCheck } from '@lucide/vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiButton from '@shared/Ui/UiButton.vue';

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
    <Head :title="__('Two-Factor Challenge')" />

    <div class="min-h-screen bg-theme-bg-body flex flex-col items-center justify-center p-4">
        <UiCard class="w-full max-w-md sm:p-8 shadow-2xl space-y-6">
            <div class="flex flex-col items-center text-center space-y-2">
                <div class="p-3 rounded-theme-lg bg-theme-success-bg border border-theme-success-border text-theme-success">
                    <ShieldCheck class="w-7 h-7" />
                </div>
                <h1 class="text-xl font-bold text-theme-text-primary">{{ __('Two-Factor Authentication') }}</h1>
                <p class="text-xs sm:text-sm text-theme-text-secondary">
                    {{
                        useRecoveryCode
                            ? 'Enter one of your emergency recovery codes to access your account.'
                            : 'Enter the 6-digit verification code from your authenticator app.'
                    }}
                </p>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div v-if="!useRecoveryCode">
                    <UiInput
                        id="code"
                        v-model="form.code"
                        type="text"
                        :label="__('Authentication Code')"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        placeholder="000000"
                        autofocus
                        :error="form.errors.code"
                        class="text-center tracking-[0.35em] font-mono text-lg"
                    />
                </div>

                <div v-else>
                    <UiInput
                        id="recovery_code"
                        v-model="form.recovery_code"
                        type="text"
                        :label="__('Recovery Code')"
                        autocomplete="off"
                        :placeholder="__('XXXX-XXXX')"
                        autofocus
                        :error="form.errors.code"
                        class="text-center tracking-widest font-mono text-sm uppercase"
                    />
                </div>

                <UiButton
                    type="submit"
                    variant="primary"
                    :disabled="form.processing"
                    :loading="form.processing"
                    class="w-full"
                >
                    {{ form.processing ? 'Verifying...' : 'Verify & Sign In' }}
                </UiButton>
            </form>

            <div class="flex items-center justify-between pt-2 border-t border-theme-border text-xs">
                <button
                    type="button"
                    @click="toggleMode"
                    class="text-theme-text-secondary hover:text-theme-text-primary transition-colors flex items-center gap-1.5"
                >
                    <KeyRound class="w-3.5 h-3.5" />
                    <span>{{ useRecoveryCode ? 'Use authenticator code' : 'Use a recovery code' }}</span>
                </button>

                <Link href="/login" class="text-theme-text-secondary hover:text-theme-text-primary transition-colors">
                    Back to login
                </Link>
            </div>
        </UiCard>
    </div>
</template>

