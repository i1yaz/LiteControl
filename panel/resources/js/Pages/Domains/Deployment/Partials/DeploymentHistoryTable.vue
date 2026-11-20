<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import UiAlert from '@/Shared/Ui/UiAlert.vue';
import UiBadge from '@/Shared/Ui/UiBadge.vue';
import UiButton from '@/Shared/Ui/UiButton.vue';
import UiCard from '@/Shared/Ui/UiCard.vue';
import UiModal from '@/Shared/Ui/UiModal.vue';

export interface DeploymentRecord {
    id: number;
    release_id: string;
    commit_sha: string | null;
    commit_message: string | null;
    commit_author: string | null;
    trigger_type: 'manual' | 'webhook' | 'rollback' | string;
    status: 'queued' | 'running' | 'active' | 'failed' | 'rolled_back' | 'superseded' | string;
    error_summary: string | null;
    started_at: string | null;
    finished_at: string | null;
    created_at?: string | null;
}

const props = defineProps<{
    domain: {
        id: number;
        name?: string;
        domain?: string;
    };
    deployments: DeploymentRecord[];
    keepReleases: number;
}>();

// Compute IDs of the most recent `keepReleases` successful (non-active) deployments eligible for rollback.
const rollbackEligibleIds = computed<Set<number>>(() => {
    const eligible = props.deployments
        .filter((d) => d.status === 'superseded' || d.status === 'rolled_back')
        .slice(0, Math.max(1, props.keepReleases));

    return new Set(eligible.map((d) => d.id));
});

const statusBadgeVariant = (status: string): 'success' | 'warning' | 'danger' | 'info' | 'neutral' => {
    switch (status) {
        case 'active':
            return 'success';
        case 'running':
        case 'queued':
            return 'info';
        case 'failed':
            return 'danger';
        case 'rolled_back':
            return 'warning';
        default:
            return 'neutral';
    }
};

const statusLabel = (status: string): string => {
    switch (status) {
        case 'active':
            return 'Active';
        case 'running':
            return 'Running';
        case 'queued':
            return 'Queued';
        case 'failed':
            return 'Failed';
        case 'rolled_back':
            return 'Rolled Back';
        case 'superseded':
            return 'Superseded';
        default:
            return status;
    }
};

const triggerLabel = (trigger: string): string => {
    switch (trigger) {
        case 'manual':
            return 'Manual';
        case 'webhook':
            return 'Webhook';
        case 'rollback':
            return 'Rollback';
        default:
            return trigger;
    }
};

const formatDuration = (deployment: DeploymentRecord): string => {
    if (!deployment.started_at) {
        return deployment.status === 'queued' ? 'Queued' : '—';
    }

    const start = new Date(deployment.started_at).getTime();
    const end = deployment.finished_at ? new Date(deployment.finished_at).getTime() : Date.now();
    const seconds = Math.max(0, Math.round((end - start) / 1000));

    if (seconds < 60) {
        return `${seconds}s`;
    }

    const mins = Math.floor(seconds / 60);
    const rem = seconds % 60;
    return `${mins}m ${rem}s`;
};

// Live Log Drawer state
const logDrawerOpen = ref(false);
const activeLogDeployment = ref<DeploymentRecord | null>(null);
const logContent = ref('');
const logStatus = ref('');
const logLoading = ref(false);
const logError = ref<string | null>(null);
let pollTimer: ReturnType<typeof setInterval> | null = null;

const stopPolling = () => {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
};

const fetchLog = async (deploymentId: number) => {
    try {
        const response = await fetch(`/domains/${props.domain.id}/deploy/logs/${deploymentId}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error(`Failed to load logs (${response.status})`);
        }

        const data = (await response.json()) as { status: string; log: string };
        logStatus.value = data.status;
        logContent.value = data.log || 'No log output yet...';
        logError.value = null;

        if (data.status !== 'running' && data.status !== 'queued') {
            stopPolling();
        }
    } catch (err) {
        logError.value = err instanceof Error ? err.message : 'Unable to fetch deployment logs.';
        stopPolling();
    } finally {
        logLoading.value = false;
    }
};

const openLogs = (deployment: DeploymentRecord) => {
    stopPolling();
    activeLogDeployment.value = deployment;
    logStatus.value = deployment.status;
    logContent.value = '';
    logError.value = null;
    logLoading.value = true;
    logDrawerOpen.value = true;

    void fetchLog(deployment.id);

    if (deployment.status === 'running' || deployment.status === 'queued') {
        pollTimer = setInterval(() => {
            void fetchLog(deployment.id);
        }, 2000);
    }
};

const closeLogs = () => {
    stopPolling();
    logDrawerOpen.value = false;
    activeLogDeployment.value = null;
};

watch(logDrawerOpen, (open) => {
    if (!open) {
        stopPolling();
    }
});

onBeforeUnmount(() => {
    stopPolling();
});

// Rollback confirmation modal state
const rollbackTarget = ref<DeploymentRecord | null>(null);
const isRollingBack = ref(false);

const confirmRollback = (deployment: DeploymentRecord) => {
    rollbackTarget.value = deployment;
};

const executeRollback = () => {
    if (!rollbackTarget.value) {
        return;
    }

    isRollingBack.value = true;
    router.post(
        `/domains/${props.domain.id}/deploy/rollback/${rollbackTarget.value.id}`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                isRollingBack.value = false;
                rollbackTarget.value = null;
            },
        },
    );
};
</script>

<template>
    <UiCard title="Releases History" subtitle="Recent atomic deployments, live logs, and 1-click rollback.">
        <div v-if="deployments.length === 0" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
            No deployments have been triggered yet. Click <strong>Deploy Now</strong> to create your first release.
        </div>

        <div v-else class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        <th class="py-3 pr-4">Release ID</th>
                        <th class="py-3 pr-4">Commit</th>
                        <th class="py-3 pr-4">Trigger</th>
                        <th class="py-3 pr-4">Status</th>
                        <th class="py-3 pr-4">Duration</th>
                        <th class="py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    <tr v-for="deployment in deployments" :key="deployment.id" class="hover:bg-gray-50/50 dark:hover:bg-gray-800/40">
                        <td class="whitespace-nowrap py-3 pr-4 font-mono text-xs font-medium text-gray-900 dark:text-gray-100">
                            {{ deployment.release_id }}
                        </td>
                        <td class="max-w-xs py-3 pr-4">
                            <div v-if="deployment.commit_sha" class="flex flex-col">
                                <div class="flex items-center gap-2">
                                    <span class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                        {{ deployment.commit_sha.slice(0, 7) }}
                                    </span>
                                    <span class="truncate font-medium text-gray-900 dark:text-gray-100">
                                        {{ deployment.commit_message || 'No commit message' }}
                                    </span>
                                </div>
                                <span v-if="deployment.commit_author" class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    by {{ deployment.commit_author }}
                                </span>
                            </div>
                            <span v-else class="text-xs text-gray-400">—</span>
                        </td>
                        <td class="whitespace-nowrap py-3 pr-4">
                            <UiBadge variant="neutral">{{ triggerLabel(deployment.trigger_type) }}</UiBadge>
                        </td>
                        <td class="whitespace-nowrap py-3 pr-4">
                            <UiBadge :variant="statusBadgeVariant(deployment.status)">
                                {{ statusLabel(deployment.status) }}
                            </UiBadge>
                        </td>
                        <td class="whitespace-nowrap py-3 pr-4 text-xs text-gray-600 dark:text-gray-400">
                            {{ formatDuration(deployment) }}
                        </td>
                        <td class="whitespace-nowrap py-3 text-right">
                            <div class="inline-flex items-center gap-2">
                                <UiButton size="sm" variant="secondary" @click="openLogs(deployment)">
                                    View Logs
                                </UiButton>
                                <UiButton
                                    v-if="rollbackEligibleIds.has(deployment.id)"
                                    size="sm"
                                    variant="danger"
                                    @click="confirmRollback(deployment)"
                                >
                                    Rollback to Here
                                </UiButton>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </UiCard>

    <!-- Live Log Drawer Modal -->
    <UiModal :show="logDrawerOpen" max-width="3xl" @close="closeLogs">
        <div class="p-6">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                        Deployment Logs — <span class="font-mono">{{ activeLogDeployment?.release_id }}</span>
                    </h3>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        <span v-if="logStatus === 'running' || logStatus === 'queued'">
                            Live streaming (polling every 2s)...
                        </span>
                        <span v-else>
                            Final status: {{ statusLabel(logStatus) }}
                        </span>
                    </p>
                </div>
                <UiBadge :variant="statusBadgeVariant(logStatus)">
                    {{ statusLabel(logStatus) }}
                </UiBadge>
            </div>

            <UiAlert v-if="logError" variant="danger" class="mb-4">
                {{ logError }}
            </UiAlert>

            <div class="max-h-96 overflow-y-auto rounded-lg bg-gray-950 p-4 font-mono text-xs leading-relaxed text-gray-100">
                <div v-if="logLoading" class="text-gray-400">Loading deployment log...</div>
                <pre v-else class="whitespace-pre-wrap break-words">{{ logContent }}</pre>
            </div>

            <div class="mt-4 flex justify-end">
                <UiButton variant="secondary" @click="closeLogs">Close</UiButton>
            </div>
        </div>
    </UiModal>

    <!-- Rollback Confirmation Modal -->
    <UiModal :show="rollbackTarget !== null" max-width="md" @close="rollbackTarget = null">
        <div class="p-6">
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                Confirm Rollback
            </h3>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                Are you sure you want to atomically switch the live symlink back to release
                <span class="font-mono font-semibold text-gray-900 dark:text-gray-100">{{ rollbackTarget?.release_id }}</span>?
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <UiButton variant="secondary" :disabled="isRollingBack" @click="rollbackTarget = null">
                    Cancel
                </UiButton>
                <UiButton variant="danger" :disabled="isRollingBack" @click="executeRollback">
                    {{ isRollingBack ? 'Rolling back...' : 'Rollback to Here' }}
                </UiButton>
            </div>
        </div>
    </UiModal>
</template>
