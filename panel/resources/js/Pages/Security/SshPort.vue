<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Terminal, Save, ShieldCheck } from '@lucide/vue';
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
    port: props.currentPort || 22,
});

const submit = async () => {
    if (await confirmDialog({
        title: 'Change SSH Port',
        message: `Change SSH port to ${form.port}? Make sure you remember this port when opening SSH sessions.`,
        confirmText: 'Change Port',
        variant: 'warning',
    })) {
        form.post('/security/ssh-port');
    }
};
</script>

<template>
    <Head :title="__('SSH Port')" />

    <div class="max-w-2xl mx-auto space-y-6">
        <UiPageHeader
            :title="__('Change SSH Port')"
            subtitle="Hardening SSH against automated brute-force attacks by moving away from default port 22."
        >
            <template #icon>
                <Terminal class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Current SSH Port -->
        <UiStatCard
            :label="__('Current SSH Port')"
            :value="currentPort"
            variant="primary"
        >
            <template #icon>
                <ShieldCheck class="w-6 h-6" />
            </template>
        </UiStatCard>

        <!-- Warning Callout -->
        <UiAlert variant="warning">
            <p class="font-bold">{{ __('Firewall Synchronization') }}</p>
            <p class="mt-0.5 leading-relaxed">
                LiteControl automatically adds an allow rule for the new SSH port in UFW, tests sshd syntax via <code>{{ __('sshd -t') }}</code>, restarts the SSH daemon, and removes the old rule once verified.
            </p>
        </UiAlert>

        <!-- Form -->
        <UiCard>
            <form @submit.prevent="submit" class="space-y-6">
                <UiInput
                    v-model.number="form.port"
                    type="number"
                    min="1"
                    max="65535"
                    required
                    :label="__('New SSH Port (1 - 65535)')"
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
                        <span>{{ form.processing ? 'Updating...' : 'Change SSH Port' }}</span>
                    </UiButton>
                </div>
            </form>
        </UiCard>
    </div>
</template>
