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
    const targetDomain = form.domain.trim().toLowerCase();

    form.post('/security/panel-ssl', {
        onSuccess: () => {
            if (targetDomain && (window.location.hostname.toLowerCase() !== targetDomain || window.location.port !== String(props.panelPort))) {
                window.location.href = `https://${targetDomain}:${props.panelPort}/security/panel-ssl`;
            }
        },
    });
};
</script>

<template>
    <Head :title="__('Panel SSL')" />

    <div class="max-w-3xl mx-auto space-y-6">
        <UiPageHeader
            :title="__('Panel SSL Certificate')"
            subtitle="Bind a domain to LiteControl and OpenLiteSpeed WebAdmin with shared HTTPS SSL while keeping the panel isolated on its dedicated Nginx port."
        >
            <template #icon>
                <Lock class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Active Panel Domain Banner -->
        <UiCard v-if="configuredDomain" class="border-theme-accent/30">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="space-y-2">
                    <p class="text-xs font-medium uppercase tracking-wider text-theme-muted">
                        {{ __('Active Panel URLs (Shared SSL)') }}
                    </p>
                    <div class="flex flex-wrap items-center gap-4">
                        <a
                            :href="`https://${configuredDomain}:${panelPort}`"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1.5 font-mono text-sm font-semibold text-theme-accent hover:underline"
                        >
                            <span>https://{{ configuredDomain }}:{{ panelPort }}</span>
                            <ExternalLink class="w-3.5 h-3.5" />
                        </a>
                        <a
                            :href="`https://${configuredDomain}:7080`"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1.5 font-mono text-sm font-medium text-theme-secondary hover:text-theme-accent hover:underline"
                        >
                            <span>OLS WebAdmin: https://{{ configuredDomain }}:7080</span>
                            <ExternalLink class="w-3.5 h-3.5" />
                        </a>
                    </div>
                    <p class="text-xs text-theme-muted">
                        {{ __('Direct IP access on port') }} <span class="font-mono">{{ panelPort }}</span> {{ __('is restricted to your configured hostname. Visiting') }} <span class="font-mono">https://{{ configuredDomain }}</span> {{ __('redirects automatically to port') }} <span class="font-mono">{{ panelPort }}</span>. {{ __('To re-enable direct IP access via SSH, run:') }}
                        <span class="font-mono">lc-cli security panel-ip-access enable</span>
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
                        :hint="`Point an A record to this server IP first. LiteControl will issue a Let's Encrypt certificate via port 80, serve the panel directly on https://your-domain:${panelPort}, share the certificate with OLS WebAdmin (:7080), and redirect port 80/443 to :${panelPort}.`"
                        class="font-mono"
                    />
                </div>

                <div v-else class="space-y-4">
                    <UiInput
                        v-model="form.domain"
                        type="text"
                        :label="__('Panel Domain (Optional — configures redirect & hostname enforcement)')"
                        :placeholder="__('panel.yourdomain.com')"
                        :error="form.errors.domain"
                        hint="Leave blank to install the certificate on the panel port and OLS WebAdmin (:7080) without hostname enforcement."
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
