<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import { ShieldCheck } from 'lucide-vue-next';
import UiButton from '@/Components/ui/UiButton.vue';
import UiInput from '@/Components/ui/UiInput.vue';
import UiCard from '@/Components/ui/UiCard.vue';
import UiAlert from '@/Components/ui/UiAlert.vue';

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
    <Head title="Sign In - LiteControl" />

    <div class="min-h-screen flex flex-col justify-center py-12 sm:px-6 lg:px-8">
        <div class="sm:mx-auto sm:w-full sm:max-w-md text-center mb-6">
            <h1 class="text-2xl font-bold tracking-tight">LiteControl</h1>
            <p class="text-xs text-slate-400 mt-1">Access Authentication</p>
        </div>

        <div class="sm:mx-auto sm:w-full sm:max-w-md px-4">
            <UiCard>
                <form class="space-y-4" @submit.prevent="submit">
                    <UiAlert v-if="form.errors.username" variant="danger">
                        {{ form.errors.username }}
                    </UiAlert>

                    <UiInput
                        id="username"
                        v-model="form.username"
                        label="Username or Email"
                        type="text"
                        required
                        placeholder="admin"
                        :error="form.errors.username"
                    />

                    <UiInput
                        id="password"
                        v-model="form.password"
                        label="Password"
                        type="password"
                        required
                        placeholder="••••••••••••"
                        :error="form.errors.password"
                    />

                    <div class="flex items-center justify-between text-xs">
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <input
                                v-model="form.remember"
                                type="checkbox"
                                class="rounded"
                            />
                            <span>Remember me</span>
                        </label>
                    </div>

                    <UiButton
                        type="submit"
                        :loading="form.processing"
                        class="w-full"
                    >
                        <ShieldCheck class="w-4 h-4" />
                        <span>Sign In</span>
                    </UiButton>
                </form>
            </UiCard>
        </div>
    </div>
</template>
