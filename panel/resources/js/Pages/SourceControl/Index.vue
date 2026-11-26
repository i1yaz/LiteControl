<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import PanelLayout from '@shared/Layouts/PanelLayout.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiModal from '@shared/Ui/UiModal.vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import DisconnectProviderModal from '@domains/SourceControl/Components/DisconnectProviderModal.vue';
import GitProviderCard from '@domains/SourceControl/Components/GitProviderCard.vue';
import PanelUrlGateBanner from '@domains/SourceControl/Components/PanelUrlGateBanner.vue';

const props = defineProps({
    gate: {
        type: Object,
        required: true,
    },
    sslExpiryWarning: {
        type: Object,
        default: null,
    },
    providers: {
        type: Array,
        default: () => [],
    },
    panelSslUrl: {
        type: String,
        required: true,
    },
    maxProviders: {
        type: Number,
        default: 10,
    },
    canConnect: {
        type: Boolean,
        default: false,
    },
    orphanedDeletionUrl: {
        type: String,
        default: null,
    },
});

const showConnectModal = ref(false);
const connectLabel = ref('');
const connectOrgName = ref('');
const connecting = ref(false);
const connectError = ref('');
const disconnectingProvider = ref(null);

function csrfToken() {
    if (typeof document === 'undefined') {
        return '';
    }

    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function submitManifestToGitHub(actionUrl, manifest) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = actionUrl;
    form.style.display = 'none';

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'manifest';
    input.value = JSON.stringify(manifest);

    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
}

async function startGitHubConnect() {
    if (!props.canConnect || connecting.value) {
        return;
    }

    connecting.value = true;
    connectError.value = '';

    try {
        const response = await fetch('/settings/source-control/github/manifest', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                label: connectLabel.value.trim() || null,
                org_name: connectOrgName.value.trim() || null,
                return_to: '/settings/source-control',
            }),
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const firstError = payload.errors ? Object.values(payload.errors).flat()[0] : null;
            connectError.value = firstError || payload.message || 'Unable to initiate GitHub App connection.';
            return;
        }

        submitManifestToGitHub(payload.action_url, payload.manifest);
    } catch {
        connectError.value = 'Network error while preparing GitHub App manifest.';
    } finally {
        connecting.value = false;
    }
}
</script>

<template>
    <Head title="Source Control" />

    <PanelLayout>
        <div class="mx-auto max-w-5xl space-y-6 py-6 sm:px-6 lg:px-8">
            <UiPageHeader
                title="Source Control"
                subtitle="Connect GitHub App accounts for zero-key private repository access and automatic push-to-deploy webhooks."
            >
                <template #actions>
                    <UiButton
                        variant="primary"
                        :disabled="!canConnect"
                        @click="showConnectModal = true"
                    >
                        Connect GitHub
                    </UiButton>
                </template>
            </UiPageHeader>

            <PanelUrlGateBanner
                :gate="gate"
                :panel-ssl-url="panelSslUrl"
            />

            <UiAlert v-if="sslExpiryWarning?.warning" variant="warning">
                <p class="font-medium">{{ sslExpiryWarning.message }}</p>
            </UiAlert>

            <UiAlert v-if="orphanedDeletionUrl" variant="danger">
                <p>
                    An orphaned GitHub App may have been created during a failed registration.
                    <a
                        :href="orphanedDeletionUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="font-medium underline"
                    >
                        Delete it on GitHub &#8599;
                    </a>
                </p>
            </UiAlert>

            <UiAlert variant="info">
                GitHub App setup requires Admin access on the GitHub account or organization. Collaborators deploying external client repositories should use <strong>Advanced &rarr; Manual Git Mode</strong> on the domain's Deploy page.
            </UiAlert>

            <div v-if="providers.length > 0" class="space-y-4">
                <GitProviderCard
                    v-for="provider in providers"
                    :key="provider.id"
                    :provider="provider"
                    :gate-allowed="Boolean(gate.allowed)"
                    @disconnect="disconnectingProvider = $event"
                />
            </div>

            <UiCard
                v-else
                title="No Source Control Providers Connected"
                subtitle="Connect a GitHub account or organization to select repositories directly when configuring domain deployments."
            >
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <p class="text-sm text-theme-text-secondary">
                        You can connect up to {{ maxProviders }} GitHub accounts or organizations per panel user.
                    </p>
                    <UiButton
                        variant="primary"
                        :disabled="!canConnect"
                        @click="showConnectModal = true"
                    >
                        Connect GitHub
                    </UiButton>
                </div>
            </UiCard>
        </div>

        <UiModal
            :show="showConnectModal"
            title="Connect GitHub App"
            max-width="md"
            @close="showConnectModal = false"
        >
            <div class="space-y-4">
                <p class="text-xs text-theme-text-secondary">
                    LiteControl will create a private GitHub App scoped to <code>contents:read</code> and <code>metadata:read</code>, then redirect you to choose which repositories to grant access to.
                </p>

                <UiInput
                    v-model="connectLabel"
                    label="Connection Label (optional)"
                    placeholder="e.g. Agency GitHub"
                />

                <UiInput
                    v-model="connectOrgName"
                    label="GitHub Organization (optional)"
                    placeholder="Leave blank for your personal account"
                />

                <UiAlert v-if="connectError" variant="danger">
                    {{ connectError }}
                </UiAlert>
            </div>

            <template #footer>
                <UiButton variant="secondary" @click="showConnectModal = false">
                    Cancel
                </UiButton>
                <UiButton
                    variant="primary"
                    :disabled="!canConnect"
                    :loading="connecting"
                    @click="startGitHubConnect"
                >
                    Continue to GitHub
                </UiButton>
            </template>
        </UiModal>

        <DisconnectProviderModal
            :show="Boolean(disconnectingProvider)"
            :provider="disconnectingProvider"
            @close="disconnectingProvider = null"
        />
    </PanelLayout>
</template>
