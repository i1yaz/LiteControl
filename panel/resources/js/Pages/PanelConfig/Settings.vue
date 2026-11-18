<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { useTheme } from '@shared/Composables/useTheme';
import { Sliders, Save, Server, Globe, ShieldCheck } from '@lucide/vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiStatCard from '@shared/Ui/UiStatCard.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';
import UiButton from '@shared/Ui/UiButton.vue';

const props = defineProps({
    settings: Object,
    stats: Object,
});

const { availableThemes, activeThemeId, switchTheme } = useTheme();

const form = useForm({
    panel_name: props.settings?.panel_name || 'LiteControl',
    admin_email: props.settings?.admin_email || '',
    timezone: props.settings?.timezone || 'UTC',
    default_php: props.settings?.default_php || '83',
});

const submit = () => {
    form.post('/configuration/settings');
};
</script>

<template>
    <Head :title="__('Panel Settings')" />

    <div class="max-w-4xl mx-auto space-y-6">
        <!-- Header -->
        <UiPageHeader
            :title="__('System & Panel Settings')"
            subtitle="Global configuration parameters for LiteControl and OpenLiteSpeed web server."
        >
            <template #icon>
                <Sliders class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Server Quick Info Bar -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <UiStatCard :label="__('Server IP')" :value="settings?.server_ip" variant="info">
                <template #icon>
                    <Server class="w-5 h-5" />
                </template>
            </UiStatCard>

            <UiStatCard :label="__('Hostname')" :value="settings?.hostname" variant="success">
                <template #icon>
                    <Globe class="w-5 h-5" />
                </template>
            </UiStatCard>

            <UiStatCard :label="__('OpenLiteSpeed')" :value="settings?.ols_version" variant="primary">
                <template #icon>
                    <ShieldCheck class="w-5 h-5" />
                </template>
            </UiStatCard>
        </div>

        <!-- Settings Form Card -->
        <UiCard
            :title="__('General Information')"
            :subtitle="__('Control panel branding and administrator contact details.')"
        >
            <form @submit.prevent="submit" class="space-y-6">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <UiInput
                        v-model="form.panel_name"
                        :label="__('Control Panel Name')"
                        type="text"
                        required
                        :error="form.errors.panel_name"
                    />

                    <UiInput
                        v-model="form.admin_email"
                        :label="__('Administrator Email')"
                        type="email"
                        required
                        :error="form.errors.admin_email"
                    />

                    <UiInput
                        v-model="form.timezone"
                        :label="__('System Timezone')"
                        type="text"
                        required
                        :error="form.errors.timezone"
                    />

                    <UiSelect
                        v-model="form.default_php"
                        :label="__('Default PHP Version')"
                        :error="form.errors.default_php"
                    >
                        <option value="74">{{ __('PHP 7.4 (Legacy)') }}</option>
                        <option value="80">{{ __('PHP 8.0') }}</option>
                        <option value="81">{{ __('PHP 8.1') }}</option>
                        <option value="82">{{ __('PHP 8.2') }}</option>
                        <option value="83">{{ __('PHP 8.3 (Recommended Core)') }}</option>
                        <option value="84">{{ __('PHP 8.4') }}</option>
                        <option value="85">{{ __('PHP 8.5 (Latest)') }}</option>
                    </UiSelect>

                    <UiSelect
                        :model-value="activeThemeId"
                        @update:model-value="switchTheme(String($event))"
                        :label="__('Default Interface Theme')"
                    >
                        <option
                            v-for="t in availableThemes"
                            :key="t.id"
                            :value="t.id"
                        >
                            {{ t.name }} ({{ t.layout_type }})
                        </option>
                    </UiSelect>
                </div>

                <div class="pt-4 border-t border-theme-border-subtle flex items-center justify-end">
                    <UiButton
                        type="submit"
                        variant="primary"
                        :disabled="form.processing"
                        :loading="form.processing"
                    >
                        <Save v-if="!form.processing" class="w-4 h-4" />
                        <span>{{ form.processing ? 'Saving...' : 'Save Settings' }}</span>
                    </UiButton>
                </div>
            </form>
        </UiCard>
    </div>
</template>


