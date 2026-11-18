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

    <form class="space-y-4 text-sm" @submit.prevent="submit">
        <UiAlert v-if="form.errors.username" variant="danger">
            {{ form.errors.username }}
        </UiAlert>

        <UiInput
            id="username"
            v-model="form.username"
            type="text"
            :label="__('Username or Email')"
            :placeholder="__('admin')"
            autocomplete="username"
            required
        />

        <UiInput
            id="password"
            v-model="form.password"
            type="password"
            :label="__('Password')"
            placeholder="••••••••••••"
            autocomplete="current-password"
            required
        />

        <div class="flex items-center justify-between text-xs">
            <label class="flex items-center gap-2 cursor-pointer select-none text-theme-text-secondary">
                <input
                    v-model="form.remember"
                    type="checkbox"
                    class="rounded-theme-sm bg-theme-bg-input border-theme-border text-theme-accent focus:ring-theme-accent/30 h-4 w-4"
                />
                <span>{{ __('Remember this device') }}</span>
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
            <span>{{ __('Sign In') }}</span>
        </UiButton>
    </form>
</template>
