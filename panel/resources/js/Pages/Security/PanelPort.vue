<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Sliders, Save, ShieldCheck } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiStatCard from '@shared/Ui/UiStatCard.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiButton from '@shared/Ui/UiButton.vue';

const props = defineProps({
    currentPort: Number,
});

const form = useForm({
    port: props.currentPort || 8090,
});

const submit = async () => {
    if (await confirmDialog({
        title: 'Change Panel Port',
        message: `Changing the panel port will reload OpenLiteSpeed and require you to access LiteControl via https://<IP>:${form.port}/. Proceed?`,
        confirmText: 'Proceed',
        variant: 'warning',
    })) {
        form.post('/security/panel-port');
    }
};
</script>

<template>
    <Head :title="__('Panel Port')" />

    <div class="max-w-2xl mx-auto space-y-6">
        <UiPageHeader
            :title="__('Change Panel Listening Port')"
            subtitle="Configure the TCP port that the LiteControl admin web panel binds to."
        >
            <template #icon>
                <Sliders class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Current Port Card -->
        <UiStatCard
            :label="__('Current Panel Port')"
            :value="currentPort"
            variant="primary"
        >
            <template #icon>
                <ShieldCheck class="w-6 h-6" />
            </template>
        </UiStatCard>

        <!-- Warning Callout -->
        <UiAlert variant="warning">
            <p class="font-bold">{{ __('Self-Lockout Protection') }}</p>
            <p class="mt-0.5 leading-relaxed">
                The new port is automatically allowed in UFW before the OpenLiteSpeed listener is updated. If OLS fails to bind to the new port, it will automatically roll back.
            </p>
        </UiAlert>

        <!-- Form -->
        <UiCard>
            <form @submit.prevent="submit" class="space-y-6">
                <UiInput
                    v-model.number="form.port"
                    type="number"
                    min="1024"
                    max="65535"
                    required
                    :label="__('New Port (1024 - 65535)')"
                    :error="form.errors.port"
                    class="font-mono"
                />

                <div class="pt-4 border-t border-theme-border-subtle flex items-center justify-end">
                    <UiButton
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                    >
                        <Save class="w-4 h-4" />
                        <span>{{ form.processing ? 'Updating...' : 'Change Port' }}</span>
                    </UiButton>
                </div>
            </form>
        </UiCard>
    </div>
</template>
