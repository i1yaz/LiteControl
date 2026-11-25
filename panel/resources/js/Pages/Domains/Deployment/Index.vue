<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PanelLayout from '@shared/Layouts/PanelLayout.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import ManualGitModeForm from '@domains/Deployment/Components/ManualGitModeForm.vue';
import ProviderSourceForm from '@domains/Deployment/Components/ProviderSourceForm.vue';
import SwitchSourceModeModal from '@domains/Deployment/Components/SwitchSourceModeModal.vue';
import DeploymentHooksPipeline from './Partials/DeploymentHooksPipeline.vue';
import DeploymentHistoryTable from './Partials/DeploymentHistoryTable.vue';
import SharedEnvEditorModal from './Partials/SharedEnvEditorModal.vue';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
    config: {
        type: Object,
        required: true,
    },
    hooks: {
        type: Array,
        default: () => [],
    },
    deployments: {
        type: Array,
        default: () => [],
    },
    presets: {
        type: Object,
        required: true,
    },
    sourceControl: {
        type: Object,
        default: () => ({
            gate: { allowed: false, code: 'not_https', message: '' },
            panel_ssl_url: '/security/panel-ssl',
            providers: [],
            source_mode: 'manual',
            is_configured: false,
            has_manual_credentials: false,
            has_api_token: false,
            has_deploy_key: false,
        }),
    },
});

const domainName = computed(() => props.domain.domain ?? props.domain.name ?? `Domain #${props.domain.id}`);
const isAtomic = computed(() => props.config.mode === 'atomic');
const isProviderBound = computed(() =>
    props.config.source_mode !== 'provider'
    || (Boolean(props.config.git_provider_id) && Boolean(props.config.remote_repo_id)),
);
const canDeploy = computed(() => isAtomic.value && isProviderBound.value);

const activeProviders = computed(() =>
    (props.sourceControl?.providers ?? []).filter((p) => p.status === 'active'),
);

const hasActiveProviders = computed(() => activeProviders.value.length > 0);
const gateAllowed = computed(() => Boolean(props.sourceControl?.gate?.allowed));

/**
 * 3 Clean States:
 * - 'unconfigured_no_providers': Unconfigured & user has no active connected providers
 * - 'provider': Connected Provider Mode (explicit provider mode, OR unconfigured with >=1 active provider)
 * - 'manual': Manual Git Mode (existing manual domains & client repos)
 */
const activeUiState = computed(() => {
    const mode = props.sourceControl?.source_mode ?? props.config.source_mode ?? 'manual';
    if (mode === 'provider') {
        return 'provider';
    }
    if (!props.sourceControl?.is_configured && hasActiveProviders.value) {
        return 'provider';
    }
    if (!props.sourceControl?.is_configured && !hasActiveProviders.value) {
        return 'unconfigured_no_providers';
    }

    return 'manual';
});

const showEnvModal = ref(false);
const isDeploying = ref(false);
const showManualAccordion = ref(false);
const connectingGitHub = ref(false);
const connectError = ref('');

const switchModalShow = ref(false);
const switchModalTarget = ref('manual');

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

async function connectGitHubFromDeploy() {
    if (!gateAllowed.value || connectingGitHub.value) {
        return;
    }

    connectingGitHub.value = true;
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
                return_to: `/domains/${props.domain.id}/deploy`,
                owner_user_id: props.domain.user_id,
            }),
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const firstError = payload.errors ? Object.values(payload.errors).flat()[0] : null;
            connectError.value = firstError || payload.message || 'Unable to start GitHub connection.';
            return;
        }

        submitManifestToGitHub(payload.action_url, payload.manifest);
    } catch {
        connectError.value = 'Network error while preparing GitHub App connection.';
    } finally {
        connectingGitHub.value = false;
    }
}

function openSwitchModal(targetMode) {
    switchModalTarget.value = targetMode;
    switchModalShow.value = true;
}

function triggerDeploy() {
    if (!canDeploy.value || isDeploying.value) {
        return;
    }

    isDeploying.value = true;
    router.post(
        `/domains/${props.domain.id}/deploy/run`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                isDeploying.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`Zero-Downtime Deployment — ${domainName}`" />

    <PanelLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <h2 class="text-xl font-semibold leading-tight text-theme-text-primary">
                            Deployment — {{ domainName }}
                        </h2>
                        <UiBadge :variant="isAtomic ? 'success' : 'neutral'">
                            {{ isAtomic ? 'Atomic Mode' : 'Simple Mode' }}
                        </UiBadge>
                        <UiBadge :variant="activeUiState === 'provider' ? 'primary' : 'neutral'">
                            {{ activeUiState === 'provider' ? 'Connected Provider' : 'Manual Git' }}
                        </UiBadge>
                    </div>

                    <div class="flex items-center gap-3">
                        <UiButton
                            v-if="isAtomic"
                            variant="secondary"
                            @click="showEnvModal = true"
                        >
                            Shared .env &amp; Paths
                        </UiButton>
                        <UiButton
                            variant="primary"
                            :disabled="!canDeploy || isDeploying"
                            @click="triggerDeploy"
                        >
                            {{ isDeploying ? 'Queueing...' : 'Deploy Now' }}
                        </UiButton>
                    </div>
                </div>

                <UiAlert v-if="connectError" variant="danger">
                    {{ connectError }}
                </UiAlert>

                <!-- State 1: Unconfigured & User Has No Connected Providers -->
                <template v-if="activeUiState === 'unconfigured_no_providers'">
                    <UiCard
                        title="Connect GitHub for Zero-Key Deployments"
                        subtitle="Select repositories directly from your GitHub account or organization with automatic push-to-deploy webhooks."
                    >
                        <div class="space-y-4">
                            <UiAlert v-if="!gateAllowed" variant="warning">
                                <div class="space-y-2">
                                    <p class="font-medium">
                                        Source control integration requires your panel to be served over HTTPS on a domain name.
                                    </p>
                                    <p v-if="sourceControl.gate?.message && sourceControl.gate?.code !== 'cert_missing'" class="text-xs opacity-90">
                                        {{ sourceControl.gate.message }}
                                    </p>
                                    <div class="pt-1">
                                        <Link :href="sourceControl.panel_ssl_url">
                                            <UiButton size="sm" variant="secondary">
                                                Configure Panel URL &amp; SSL
                                            </UiButton>
                                        </Link>
                                    </div>
                                </div>
                            </UiAlert>

                            <div class="flex flex-wrap items-center justify-between gap-4">
                                <p class="text-xs text-theme-text-secondary">
                                    GitHub App setup requires Admin access on the GitHub account or organization. For external client repositories, use Manual Git Setup below.
                                </p>
                                <UiButton
                                    variant="primary"
                                    :disabled="!gateAllowed"
                                    :loading="connectingGitHub"
                                    @click="connectGitHubFromDeploy"
                                >
                                    Connect GitHub
                                </UiButton>
                            </div>
                        </div>
                    </UiCard>

                    <UiCard>
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-3 text-left"
                            :aria-expanded="showManualAccordion"
                            @click="showManualAccordion = !showManualAccordion"
                        >
                            <div>
                                <h3 class="text-sm font-semibold text-theme-text-primary">
                                    Advanced: Manual Git Setup (Client Repo / Custom SSH / PAT)
                                </h3>
                                <p class="mt-0.5 text-xs text-theme-text-secondary">
                                    Configure a custom SSH deploy key, Personal Access Token, or manual webhook URL.
                                </p>
                            </div>
                            <span class="text-xs font-medium text-theme-text-muted">
                                {{ showManualAccordion ? 'Hide' : 'Expand' }}
                            </span>
                        </button>

                        <div v-if="showManualAccordion" class="mt-5 border-t border-theme-border-subtle pt-5">
                            <ManualGitModeForm
                                :domain="{ id: domain.id, name: domainName, php_version: domain.php_version }"
                                :config="config"
                                :presets="presets"
                                :show-manual-banner="false"
                                :has-active-providers="false"
                                @connect-github="connectGitHubFromDeploy"
                            />
                        </div>
                    </UiCard>
                </template>

                <!-- State 2: Connected Provider Mode -->
                <ProviderSourceForm
                    v-else-if="activeUiState === 'provider'"
                    :domain="{ id: domain.id, name: domainName, php_version: domain.php_version }"
                    :config="config"
                    :presets="presets"
                    :providers="sourceControl.providers"
                    :has-manual-credentials="Boolean(sourceControl.has_manual_credentials)"
                    @switch-to-manual="openSwitchModal('manual')"
                    @connect-another="connectGitHubFromDeploy"
                />

                <!-- State 3: Manual Git Mode -->
                <ManualGitModeForm
                    v-else
                    :domain="{ id: domain.id, name: domainName, php_version: domain.php_version }"
                    :config="config"
                    :presets="presets"
                    :show-manual-banner="true"
                    :has-active-providers="hasActiveProviders"
                    @switch-to-provider="openSwitchModal('provider')"
                    @connect-github="connectGitHubFromDeploy"
                />

                <DeploymentHooksPipeline
                    v-if="isAtomic"
                    :domain="{ id: domain.id, name: domainName }"
                    :hooks="hooks"
                />

                <DeploymentHistoryTable
                    v-if="isAtomic"
                    :domain="{ id: domain.id, name: domainName }"
                    :deployments="deployments"
                    :keep-releases="config.keep_releases ?? 5"
                />
            </div>
        </div>

        <SharedEnvEditorModal
            :show="showEnvModal"
            :domain="{ id: domain.id, name: domainName }"
            :config="config"
            @close="showEnvModal = false"
        />

        <SwitchSourceModeModal
            :show="switchModalShow"
            :domain-id="domain.id"
            :target-mode="switchModalTarget"
            :has-manual-credentials="Boolean(sourceControl.has_manual_credentials)"
            @close="switchModalShow = false"
        />
    </PanelLayout>
</template>
