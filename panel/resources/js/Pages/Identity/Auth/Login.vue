<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiInput from '@shared/Ui/UiInput.vue';
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

    <form class="space-y-4" @submit.prevent="submit">
        <UiAlert v-if="form.errors.username" variant="danger">
            {{ form.errors.username }}
        </UiAlert>

        <UiInput
            id="username"
            v-model="form.username"
            :label="__('Username or Email')"
            type="text"
            required
            :placeholder="__('admin')"
            :error="form.errors.username"
        />

        <UiInput
            id="password"
            v-model="form.password"
            :label="__('Password')"
            type="password"
            required
            placeholder="••••••••••••"
            :error="form.errors.password"
        />

        <div class="flex items-center justify-between text-xs">
            <label class="flex items-center gap-2 cursor-pointer select-none text-theme-text-secondary">
                <input
                    v-model="form.remember"
                    type="checkbox"
                    class="rounded-theme-sm border-theme-border bg-theme-bg-input text-theme-accent focus:ring-0"
                />
                <span>{{ __('Remember me') }}</span>
            </label>
        </div>

        <UiButton
            type="submit"
            :loading="form.processing"
            class="w-full"
        >
            <ShieldCheck class="w-4 h-4" />
            <span>{{ __('Sign In') }}</span>
        </UiButton>
    </form>
</template>
