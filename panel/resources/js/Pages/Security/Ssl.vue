<script setup lang="ts">
import { shallowRef } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ShieldCheck, ShieldAlert, Lock, RefreshCw, Key } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiDataTable from '@shared/Ui/UiDataTable.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiModal from '@shared/Ui/UiModal.vue';
import UiTextarea from '@shared/Ui/UiTextarea.vue';

const props = defineProps({
    domains: Array,
});

const customModalOpen = shallowRef(false);
const selectedDomain = shallowRef(null);

const issueForm = useForm({
    domain_id: null,
});

const customForm = useForm({
    domain_id: null,
    certificate: '',
    private_key: '',
});

const issueSsl = async (domain) => {
    if (await confirmDialog({
        title: 'Issue SSL Certificate',
        message: `Issue Let's Encrypt SSL certificate for ${domain.domain}?`,
        confirmText: 'Issue SSL',
    })) {
        issueForm.domain_id = domain.id;
        issueForm.post('/security/ssl/issue');
    }
};

const renewSsl = (domain) => {
    router.post(`/security/ssl/${domain.id}/renew`);
};

const openCustomModal = (domain) => {
    selectedDomain.value = domain;
    customForm.domain_id = domain.id;
    customForm.certificate = '';
    customForm.private_key = '';
    customModalOpen.value = true;
};

const submitCustomSsl = () => {
    customForm.post('/security/ssl/custom', {
        onSuccess: () => {
            customModalOpen.value = false;
        },
    });
};
</script>

<template>
    <Head :title="__('SSL Certificates')" />

    <div class="space-y-6">
        <UiPageHeader
            :title="__('SSL Certificates')"
            subtitle="Issue free automated Let's Encrypt TLS/SSL certificates or install custom enterprise SSL certificates."
        >
            <template #icon>
                <Lock class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Domains Table Card -->
        <UiDataTable
            :headers="[
                __('Domain'),
                __('User'),
                __('SSL Status'),
                __('Expires'),
                { label: __('Actions'), class: 'text-right' }
            ]"
            :items-count="domains?.length || 0"
            empty-message="No domains created yet. Add a domain to manage SSL certificates."
        >
            <tr v-for="d in domains" :key="d.id" class="hover:bg-theme-bg-surface/40 transition-colors text-xs">
                <td class="px-4 py-3.5 font-semibold text-theme-text-primary font-mono text-sm">
                    {{ d.domain }}
                </td>
                <td class="px-4 py-3.5 text-theme-text-secondary">
                    {{ d.user?.username || 'admin' }}
                </td>
                <td class="px-4 py-3.5">
                    <UiBadge :variant="d.ssl_enabled ? 'success' : 'warning'">
                        <ShieldCheck v-if="d.ssl_enabled" class="w-3.5 h-3.5" />
                        <ShieldAlert v-else class="w-3.5 h-3.5" />
                        <span>{{ d.ssl_enabled ? 'Active SSL' : 'Self-Signed / None' }}</span>
                    </UiBadge>
                </td>
                <td class="px-4 py-3.5 text-theme-text-secondary font-mono">
                    {{ d.ssl_expires_at ? new Date(d.ssl_expires_at).toLocaleDateString() : 'N/A' }}
                </td>
                <td class="px-4 py-3.5 text-right">
                    <div class="flex items-center justify-end gap-2">
                        <UiButton
                            v-if="!d.ssl_enabled"
                            size="xs"
                            variant="primary"
                            @click="issueSsl(d)"
                            :loading="issueForm.processing"
                        >
                            Issue Let's Encrypt
                        </UiButton>
                        <UiButton
                            v-else
                            size="xs"
                            variant="secondary"
                            @click="renewSsl(d)"
                        >
                            <RefreshCw class="w-3 h-3 text-theme-success-text" />
                            <span>{{ __('Renew') }}</span>
                        </UiButton>
                        <UiButton
                            size="xs"
                            variant="secondary"
                            @click="openCustomModal(d)"
                        >
                            <Key class="w-3.5 h-3.5 text-theme-accent" />
                            <span>{{ __('Custom SSL') }}</span>
                        </UiButton>
                    </div>
                </td>
            </tr>
        </UiDataTable>

        <!-- Custom SSL Modal -->
        <UiModal
            :show="customModalOpen"
            :title="`Install Custom SSL: ${selectedDomain?.domain || ''}`"
            max-width="xl"
            @close="customModalOpen = false"
        >
            <form @submit.prevent="submitCustomSsl" class="space-y-4">
                <UiTextarea
                    v-model="customForm.certificate"
                    :label="__('Certificate (CRT/PEM)')"
                    :rows="5"
                    required
                    :placeholder="__('-----BEGIN CERTIFICATE-----')"
                    mono
                />

                <UiTextarea
                    v-model="customForm.private_key"
                    :label="__('Private Key (KEY)')"
                    :rows="5"
                    required
                    :placeholder="__('-----BEGIN PRIVATE KEY-----')"
                    mono
                />

                <div class="pt-4 border-t border-theme-border-subtle flex items-center justify-end gap-3">
                    <UiButton
                        type="button"
                        variant="secondary"
                        @click="customModalOpen = false"
                    >
                        Cancel
                    </UiButton>
                    <UiButton
                        type="submit"
                        variant="primary"
                        :loading="customForm.processing"
                    >
                        {{ customForm.processing ? 'Installing...' : 'Install Certificate' }}
                    </UiButton>
                </div>
            </form>
        </UiModal>
    </div>
</template>
