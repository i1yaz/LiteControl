<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PanelLayout from '@/Shared/Layouts/PanelLayout.vue';
import UiBadge from '@/Shared/Ui/UiBadge.vue';
import UiButton from '@/Shared/Ui/UiButton.vue';
import DeploymentConfigForm, {
    type DeploymentConfig,
    type DeploymentPreset,
} from './Partials/DeploymentConfigForm.vue';
import DeploymentHooksPipeline, { type DeploymentHook } from './Partials/DeploymentHooksPipeline.vue';
import DeploymentHistoryTable, { type DeploymentRecord } from './Partials/DeploymentHistoryTable.vue';
import SharedEnvEditorModal from './Partials/SharedEnvEditorModal.vue';

const props = defineProps<{
    domain: {
        id: number;
        domain?: string;
        name?: string;
        php_version?: string | null;
    };
    config: DeploymentConfig;
    hooks: DeploymentHook[];
    deployments: DeploymentRecord[];
    presets: Record<string, DeploymentPreset>;
}>();

const domainName = computed(() => props.domain.domain ?? props.domain.name ?? `Domain #${props.domain.id}`);
const isAtomic = computed(() => props.config.mode === 'atomic');

const showEnvModal = ref(false);
const isDeploying = ref(false);

const triggerDeploy = () => {
    if (!isAtomic.value || isDeploying.value) {
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
};
</script>

<template>
    <Head :title="`Zero-Downtime Deployment — ${domainName}`" />

    <PanelLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                        Deployment — {{ domainName }}
                    </h2>
                    <UiBadge :variant="isAtomic ? 'success' : 'neutral'">
                        {{ isAtomic ? 'Atomic Mode' : 'Simple Mode' }}
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
                        :disabled="!isAtomic || isDeploying"
                        @click="triggerDeploy"
                    >
                        {{ isDeploying ? 'Queueing...' : 'Deploy Now' }}
                    </UiButton>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <DeploymentConfigForm
                    :domain="{ id: domain.id, name: domainName, php_version: domain.php_version }"
                    :config="config"
                    :presets="presets"
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
    </PanelLayout>
</template>
