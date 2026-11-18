<script setup lang="ts">
import { shallowRef } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Lock, Save, Key, Globe } from '@lucide/vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiTabs from '@shared/Ui/UiTabs.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiTextarea from '@shared/Ui/UiTextarea.vue';
import UiButton from '@shared/Ui/UiButton.vue';

const props = defineProps({
    panelHost: String,
    hasSsl: Boolean,
});

const mode = shallowRef('letsencrypt');

const form = useForm({
    type: 'letsencrypt',
    domain: props.panelHost || '',
    certificate: '',
    private_key: '',
});

const submit = () => {
    form.type = mode.value;
    form.post('/security/panel-ssl');
};
</script>

<template>
    <Head :title="__('Panel SSL')" />

    <div class="max-w-3xl mx-auto space-y-6">
        <UiPageHeader
            :title="__('Panel SSL Certificate')"
            subtitle="Secure the LiteControl control panel listener with an official TLS certificate."
        >
            <template #icon>
                <Lock class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Tabs -->
        <UiTabs
            v-model="mode"
            :tabs="[
                { id: 'letsencrypt', label: __('Let\'s Encrypt (Automated)'), icon: Globe },
                { id: 'custom', label: __('Custom Certificate'), icon: Key }
            ]"
        />

        <!-- Form -->
        <UiCard>
            <form @submit.prevent="submit" class="space-y-6">
                <div v-if="mode === 'letsencrypt'" class="space-y-4">
                    <UiInput
                        v-model="form.domain"
                        type="text"
                        required
                        :label="__('Hostname / Panel Domain')"
                        :placeholder="__('panel.yourdomain.com')"
                        hint="Ensure DNS points to this server IP before requesting a Let's Encrypt certificate."
                        class="font-mono"
                    />
                </div>

                <div v-else class="space-y-4">
                    <UiTextarea
                        v-model="form.certificate"
                        :label="__('Certificate (CRT/PEM)')"
                        :rows="5"
                        required
                        :placeholder="__('-----BEGIN CERTIFICATE-----')"
                        mono
                    />

                    <UiTextarea
                        v-model="form.private_key"
                        :label="__('Private Key (KEY)')"
                        :rows="5"
                        required
                        :placeholder="__('-----BEGIN PRIVATE KEY-----')"
                        mono
                    />
                </div>

                <div class="pt-4 border-t border-theme-border-subtle flex items-center justify-end">
                    <UiButton
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                    >
                        <Save class="w-4 h-4" />
                        <span>{{ form.processing ? 'Applying...' : 'Apply Panel SSL' }}</span>
                    </UiButton>
                </div>
            </form>
        </UiCard>
    </div>
</template>
