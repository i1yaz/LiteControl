<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Globe, Save } from '@lucide/vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiStatCard from '@shared/Ui/UiStatCard.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiButton from '@shared/Ui/UiButton.vue';

const props = defineProps({
    currentHostname: String,
});

const form = useForm({
    hostname: props.currentHostname || '',
});

const submit = () => {
    form.post('/server/hostname');
};
</script>

<template>
    <Head :title="__('Change Hostname')" />

    <div class="max-w-3xl mx-auto space-y-6">
        <UiPageHeader
            :title="__('Change Server Hostname')"
            subtitle="Update the server Fully Qualified Domain Name (FQDN) across systemd and OpenLiteSpeed configuration."
        >
            <template #icon>
                <Globe class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Current Hostname Banner -->
        <UiStatCard
            :label="__('Current Hostname')"
            :value="currentHostname"
            variant="primary"
        >
            <template #icon>
                <Globe class="w-6 h-6" />
            </template>
        </UiStatCard>

        <!-- Hostname Form -->
        <UiCard>
            <form @submit.prevent="submit" class="space-y-6">
                <UiInput
                    v-model="form.hostname"
                    type="text"
                    required
                    :label="__('New Server Hostname (FQDN)')"
                    :placeholder="__('server1.yourdomain.com')"
                    :error="form.errors.hostname"
                    hint="Ensure the hostname has an active DNS A record pointing to this server IP address."
                    class="font-mono"
                />

                <div class="pt-4 border-t border-theme-border-subtle flex items-center justify-end">
                    <UiButton
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                    >
                        <Save class="w-4 h-4" />
                        <span>{{ form.processing ? 'Updating...' : 'Update Hostname' }}</span>
                    </UiButton>
                </div>
            </form>
        </UiCard>
    </div>
</template>
