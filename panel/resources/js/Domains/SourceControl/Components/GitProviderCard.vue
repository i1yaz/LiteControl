<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import UiAlert from '@shared/Ui/UiAlert.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import { getProviderStatusMeta, getWebhookHealthMeta } from '@domains/SourceControl/Config/providerStatusMeta';

const props = defineProps({
    provider: {
        type: Object,
        required: true,
    },
    gateAllowed: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['disconnect']);

const checkingHealth = ref(false);
const repairingWebhook = ref(false);
const healthResult = ref(null);
const actionError = ref('');
const actionSuccess = ref('');

const statusMeta = computed(() => getProviderStatusMeta(props.provider.status));

const webhookHealthStatus = computed(() => {
    if (healthResult.value?.status) {
        return healthResult.value.status;
    }

    return props.provider.webhook_verified_at ? 'verified' : 'none_yet';
});

const webhookHealthMeta = computed(() => getWebhookHealthMeta(webhookHealthStatus.value));

function csrfToken() {
    if (typeof document === 'undefined') {
        return '';
    }

    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function checkHealth() {
    checkingHealth.value = true;
    actionError.value = '';
    actionSuccess.value = '';

    try {
        const response = await fetch(`/settings/source-control/providers/${props.provider.id}/health`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            actionError.value = payload.message || 'Unable to check webhook health right now.';
            return;
        }

        healthResult.value = payload;
        actionSuccess.value = payload.message || 'Webhook health checked.';
        router.reload({ only: ['providers'], preserveScroll: true });
    } catch {
        actionError.value = 'Network error while checking webhook health.';
    } finally {
        checkingHealth.value = false;
    }
}

async function repairWebhook() {
    repairingWebhook.value = true;
    actionError.value = '';
    actionSuccess.value = '';

    try {
        const response = await fetch(`/settings/source-control/providers/${props.provider.id}/repair-webhook`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({}),
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const firstError = payload.errors ? Object.values(payload.errors).flat()[0] : null;
            actionError.value = firstError || payload.message || 'Unable to repair webhook URL.';
            return;
        }

        actionSuccess.value = payload.message || 'Webhook URL updated on GitHub.';
        router.reload({ only: ['providers'], preserveScroll: true });
    } catch {
        actionError.value = 'Network error while repairing webhook URL.';
    } finally {
        repairingWebhook.value = false;
    }
}
</script>

<template>
    <UiCard>
        <div class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-base font-semibold text-theme-text-primary">
                            {{ provider.label }}
                        </h3>
                        <UiBadge :variant="statusMeta.variant">
                            {{ statusMeta.label }}
                        </UiBadge>
                        <UiBadge :variant="webhookHealthMeta.variant">
                            {{ webhookHealthMeta.label }}
                        </UiBadge>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-theme-text-secondary">
                        <span v-if="provider.external_account_login">
                            Account: <strong class="text-theme-text-primary">{{ provider.external_account_login }}</strong>
                            <span v-if="provider.external_account_type"> ({{ provider.external_account_type }})</span>
                        </span>
                        <span v-if="provider.external_app_slug">
                            App: <code>{{ provider.external_app_slug }}</code>
                        </span>
                        <span>
                            Linked domains: <strong class="text-theme-text-primary">{{ provider.linked_domains_count }}</strong>
                        </span>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <a
                        v-if="provider.status === 'pending_installation' && provider.resume_installation_url"
                        :href="provider.resume_installation_url"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <UiButton size="sm" variant="primary">
                            Resume Installation
                        </UiButton>
                    </a>

                    <a
                        v-if="provider.manage_repos_url"
                        :href="provider.manage_repos_url"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <UiButton size="sm" variant="secondary">
                            Manage Repos on GitHub &#8599;
                        </UiButton>
                    </a>

                    <UiButton
                        size="sm"
                        variant="secondary"
                        :loading="checkingHealth"
                        @click="checkHealth"
                    >
                        Check Health
                    </UiButton>

                    <UiButton
                        size="sm"
                        variant="danger-soft"
                        @click="emit('disconnect', provider)"
                    >
                        Disconnect
                    </UiButton>
                </div>
            </div>

            <UiAlert v-if="provider.webhook_url_mismatch" variant="warning">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="space-y-1">
                        <p class="font-medium">
                            Webhook URL mismatch detected.
                        </p>
                        <p class="text-xs opacity-90">
                            GitHub currently points to <code>{{ provider.configured_webhook_url }}</code> instead of
                            <code>{{ provider.expected_webhook_url }}</code>.
                        </p>
                    </div>
                    <UiButton
                        size="sm"
                        variant="warning"
                        :disabled="!gateAllowed"
                        :loading="repairingWebhook"
                        @click="repairWebhook"
                    >
                        Repair Webhook
                    </UiButton>
                </div>
            </UiAlert>

            <UiAlert v-if="actionError" variant="danger">
                {{ actionError }}
            </UiAlert>

            <UiAlert v-if="actionSuccess" variant="info">
                {{ actionSuccess }}
            </UiAlert>
        </div>
    </UiCard>
</template>
