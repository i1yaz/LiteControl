<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';

defineProps({
    errors: Object,
});

const form = useForm({
    username: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head :title="__('Sign In - LiteControl')" />

    <form class="space-y-4 font-mono text-xs" @submit.prevent="submit">
        <UiAlert v-if="form.errors.username" variant="danger">
            {{ form.errors.username }}
        </UiAlert>

        <UiInput
            id="username"
            v-model="form.username"
            type="text"
            :label="__('User Identity (Username or Email)')"
            :placeholder="__('admin')"
            autocomplete="username"
            required
        />

        <UiInput
            id="password"
            v-model="form.password"
            type="password"
            :label="__('Passphrase Hash Key')"
            placeholder="••••••••••••"
            autocomplete="current-password"
            required
        />

        <div class="flex items-center justify-between text-[11px]">
            <label class="flex items-center gap-2 cursor-pointer select-none text-theme-text-secondary">
                <input
                    v-model="form.remember"
                    type="checkbox"
                    class="rounded-theme-sm bg-theme-bg-input border-theme-border text-theme-accent focus:ring-0 focus:ring-offset-0 h-3.5 w-3.5"
                />
                <span>{{ __('Persist Session Token') }}</span>
            </label>
        </div>

        <UiButton
            type="submit"
            variant="primary"
            class="w-full"
            :loading="form.processing"
            :disabled="form.processing"
        >
            <ShieldCheck class="w-4 h-4" />
            <span>{{ __('Authenticate Session') }}</span>
        </UiButton>
    </form>
</template>
