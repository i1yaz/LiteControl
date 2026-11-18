<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Lock, Save } from '@lucide/vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiButton from '@shared/Ui/UiButton.vue';

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post('/security/password', {
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Head :title="__('Change Password')" />

    <div class="max-w-xl mx-auto space-y-6">
        <UiPageHeader
            :title="__('Change Account Password')"
            subtitle="Update your login credentials. Use a strong password containing at least 8 characters."
        >
            <template #icon>
                <Lock class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Form -->
        <UiCard>
            <form @submit.prevent="submit" class="space-y-5">
                <UiInput
                    v-model="form.current_password"
                    type="password"
                    required
                    :label="__('Current Password')"
                    :error="form.errors.current_password"
                />

                <UiInput
                    v-model="form.password"
                    type="password"
                    required
                    :label="__('New Password')"
                    :error="form.errors.password"
                />

                <UiInput
                    v-model="form.password_confirmation"
                    type="password"
                    required
                    :label="__('Confirm New Password')"
                />

                <div class="pt-4 border-t border-theme-border-subtle flex items-center justify-end">
                    <UiButton
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                    >
                        <Save class="w-4 h-4" />
                        <span>{{ form.processing ? 'Updating...' : 'Update Password' }}</span>
                    </UiButton>
                </div>
            </form>
        </UiCard>
    </div>
</template>
