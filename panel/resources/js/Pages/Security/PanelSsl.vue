<script setup lang="ts">
import { shallowRef } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Lock, Save, Key, Globe, ExternalLink } from '@lucide/vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiTabs from '@shared/Ui/UiTabs.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiTextarea from '@shared/Ui/UiTextarea.vue';
import UiButton from '@shared/Ui/UiButton.vue';

const props = defineProps({
    panelHost: String,
    configuredDomain: String,
    panelPort: {
        type: Number,
        default: 8090,
    },
    hasSsl: Boolean,
});

const mode = shallowRef('letsencrypt');

const form = useForm({
    type: 'letsencrypt',
    domain: props.configuredDomain || props.panelHost || '',
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
            subtitle="Bind a domain to LiteControl with automated HTTPS on port 443 (with direct port fallback)."
        >
            <template #icon>
                <Lock class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Active Panel Domain Banner -->
        <UiCard v-if="configuredDomain" class="border-theme-accent/30">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="space-y-1">
                    <p class="text-xs font-medium uppercase tracking-wider text-theme-muted">
                        {{ __('Active Panel Domain') }}
                    </p>
                    <a
                        :href="`https://${configuredDomain}`"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1.5 font-mono text-sm font-semibold text-theme-accent hover:underline"
                    >
                        <span>https://{{ configuredDomain }}</span>
                        <ExternalLink class="w-3.5 h-3.5" />
                    </a>
                    <p class="text-xs text-theme-muted">
                        {{ __('Direct out-of-band fallback remains available on') }}
                        <span class="font-mono">https://{{ configuredDomain }}:{{ panelPort }}</span>
                    </p>
                </div>
            </div>
        </UiCard>

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
                        :error="form.errors.domain"
                        hint="Point an A record to this server IP first. LiteControl will configure a system HTTPS proxy on port 443 and issue a Let's Encrypt certificate."
                        class="font-mono"
                    />
                </div>

                <div v-else class="space-y-4">
                    <UiInput
                        v-model="form.domain"
                        type="text"
                        :label="__('Panel Domain (Optional — enables HTTPS on port 443)')"
                        :placeholder="__('panel.yourdomain.com')"
                        :error="form.errors.domain"
                        hint="Leave blank to install the certificate on the direct panel port only."
                        class="font-mono"
                    />

                    <UiTextarea
                        v-model="form.certificate"
                        :label="__('Certificate (CRT/PEM)')"
                        :rows="5"
                        required
                        :error="form.errors.certificate"
                        :placeholder="__('-----BEGIN CERTIFICATE-----')"
                        mono
                    />

                    <UiTextarea
                        v-model="form.private_key"
                        :label="__('Private Key (KEY)')"
                        :rows="5"
                        required
                        :error="form.errors.private_key"
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
