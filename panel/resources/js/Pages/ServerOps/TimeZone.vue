<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Clock, Save, Globe } from '@lucide/vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiStatCard from '@shared/Ui/UiStatCard.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiButton from '@shared/Ui/UiButton.vue';

const props = defineProps({
    currentTimezone: String,
    popularZones: Array,
});

const form = useForm({
    timezone: props.currentTimezone || 'UTC',
});

const submit = () => {
    form.post('/server/timezone');
};
</script>

<template>
    <Head :title="__('Server Time Zone')" />

    <div class="max-w-3xl mx-auto space-y-6">
        <UiPageHeader
            :title="__('System Time Zone')"
            subtitle="Configure Linux host timezone and synchronize cron scheduled tasks."
        >
            <template #icon>
                <Clock class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Current Timezone Banner -->
        <UiStatCard
            :label="__('Current Server Timezone')"
            :value="currentTimezone"
            variant="primary"
        >
            <template #icon>
                <Globe class="w-6 h-6" />
            </template>
        </UiStatCard>

        <!-- Form -->
        <UiCard>
            <form @submit.prevent="submit" class="space-y-6">
                <UiSelect
                    v-model="form.timezone"
                    :label="__('Select Timezone')"
                    :options="popularZones"
                />

                <UiInput
                    v-model="form.timezone"
                    type="text"
                    :label="__('Or Enter Custom Timezone Identifier')"
                    :placeholder="__('e.g. Europe/Berlin')"
                    :error="form.errors.timezone"
                />

                <div class="pt-4 border-t border-theme-border-subtle flex items-center justify-end">
                    <UiButton
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                    >
                        <Save class="w-4 h-4" />
                        <span>{{ form.processing ? 'Updating...' : 'Update Timezone' }}</span>
                    </UiButton>
                </div>
            </form>
        </UiCard>
    </div>
</template>
