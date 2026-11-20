<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    Download,
    CheckCircle2,
    Bug,
    ExternalLink,
    RefreshCw,
    Trash2,
    Terminal,
    AlertTriangle,
    KeyRound,
} from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';

const props = defineProps({
    installed: Boolean,
    status: {
        type: String,
        default: 'not_installed',
    },
    installing: {
        type: Boolean,
        default: false,
    },
    ui_url: {
        type: String,
        default: '/imunifyav/',
    },
    pam_service: {
        type: String,
        default: 'common-auth',
    },
    install_log: {
        type: String,
        default: null,
    },
    error: {
        type: String,
        default: null,
    },
});

const isPolling = ref<boolean>(props.installing);
const liveLog = ref<string>(props.install_log || '');
const liveError = ref<string | null>(props.error || null);
const showCliHelp = ref<boolean>(false);
let pollTimer: ReturnType<typeof setTimeout> | null = null;
let isFetching = false;
let isMounted = false;

watch(() => props.install_log, (val) => {
    if (val) liveLog.value = val;
});
watch(() => props.error, (val) => {
    liveError.value = val || null;
});
watch(() => props.installing, (val) => {
    if (val && !isPolling.value) {
        startPolling();
    }
});

const isActive = computed(() => props.status === 'active');

const clearPollTimer = () => {
    if (pollTimer) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
};

const stopPolling = () => {
    isPolling.value = false;
    clearPollTimer();
};

const scheduleNextPoll = () => {
    clearPollTimer();
    if (isMounted && isPolling.value) {
        pollTimer = setTimeout(pollInstallStatus, 1500);
    }
};

const pollInstallStatus = async () => {
    if (isFetching || !isPolling.value) return;
    isFetching = true;
    clearPollTimer();

    try {
        const res = await fetch('/modules/install-status', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!res.ok) return;
        const data = await res.json();

        if (data.log) {
            liveLog.value = data.log;
        }

        if (data.module === 'imunifyav') {
            if (data.status === 'installed') {
                stopPolling();
                router.reload();
            } else if (data.status === 'failed') {
                liveError.value = data.error || 'ImunifyAV installation failed.';
                stopPolling();
                router.reload();
            }
        } else if (!data.module) {
            stopPolling();
            router.reload();
        }
    } catch {
        // Keep polling on transient network error
    } finally {
        isFetching = false;
        scheduleNextPoll();
    }
};

const startPolling = () => {
    isPolling.value = true;
    if (!isFetching) {
        scheduleNextPoll();
    }
};

const installOrReinstallImunify = () => {
    liveError.value = null;
    liveLog.value = 'Queueing ImunifyAV installation worker...';
    isPolling.value = true;

    router.post('/modules/imunifyav/install', {}, {
        preserveScroll: true,
        onSuccess: () => {
            startPolling();
        },
        onError: () => {
            stopPolling();
        },
    });
};

const uninstallImunify = async () => {
    if (await confirmDialog({
        title: 'Uninstall ImunifyAV',
        message: 'Are you sure you want to uninstall ImunifyAV and remove its integration contract?',
        confirmText: 'Uninstall',
        variant: 'danger',
    })) {
        router.post('/modules/imunifyav/remove', {}, {
            preserveScroll: true,
        });
    }
};

onMounted(() => {
    isMounted = true;
    if (props.installing) {
        startPolling();
    }
});

onUnmounted(() => {
    isMounted = false;
    stopPolling();
});
</script>

<template>
    <Head :title="__('ImunifyAV Antivirus')" />

    <div class="max-w-4xl mx-auto space-y-6">
        <UiPageHeader
            :title="__('ImunifyAV Antivirus & Malware Scanner')"
            subtitle="Automated malicious file detection, webshell scanning, and standalone CloudLinux UI handoff for hosted domains."
        >
            <template #icon>
                <Bug class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Error Alert Banner -->
        <UiCard
            v-if="liveError"
            class="border-theme-danger-border bg-theme-danger-bg/30 p-4"
        >
            <div class="flex items-start gap-3">
                <AlertTriangle class="w-5 h-5 text-theme-danger-text shrink-0 mt-0.5" />
                <div class="space-y-1">
                    <h3 class="text-xs font-bold text-theme-danger-text uppercase tracking-wider">
                        {{ __('Installation Error') }}
                    </h3>
                    <p class="text-xs text-theme-text-primary font-mono">{{ liveError }}</p>
                </div>
            </div>
        </UiCard>

        <!-- State B: Installed & Handoff Launcher -->
        <template v-if="installed && status !== 'not_installed'">
            <UiCard class="space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div
                            class="p-3 rounded-theme-lg border"
                            :class="isActive
                                ? 'bg-theme-success-bg text-theme-success-text border-theme-success-border'
                                : 'bg-theme-warning-bg text-theme-warning-text border-theme-warning-border'"
                        >
                            <CheckCircle2 v-if="isActive" class="w-7 h-7" />
                            <AlertTriangle v-else class="w-7 h-7" />
                        </div>
                        <div>
                            <div class="flex items-center gap-2.5">
                                <h2 class="text-lg font-bold text-theme-text-primary">
                                    {{ isActive ? __('ImunifyAV Scanner Active') : __('ImunifyAV Scanner Inactive') }}
                                </h2>
                                <UiBadge :variant="isActive ? 'success' : 'warning'">
                                    <span class="uppercase">{{ status }}</span>
                                </UiBadge>
                            </div>
                            <p class="text-xs text-theme-text-secondary mt-0.5">
                                {{ __('Standalone ImunifyAV agent and integration contract are configured on this server.') }}
                            </p>
                        </div>
                    </div>

                    <a
                        :href="ui_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-theme-md text-xs font-semibold bg-theme-accent text-white hover:opacity-90 transition-opacity shrink-0"
                    >
                        <span>{{ __('Access ImunifyAV UI') }}</span>
                        <ExternalLink class="w-4 h-4" />
                    </a>
                </div>

                <!-- PAM Credentials Notice -->
                <div class="p-3.5 rounded-theme-md bg-theme-bg-surface border border-theme-border flex items-start gap-3">
                    <KeyRound class="w-4 h-4 text-theme-accent shrink-0 mt-0.5" />
                    <div class="text-xs text-theme-text-secondary space-y-1">
                        <p class="font-semibold text-theme-text-primary">
                            {{ __('Authentication Handoff (OS PAM)') }}
                        </p>
                        <p>
                            {{ __('ImunifyAV runs its own standalone security interface at') }}
                            <code class="px-1.5 py-0.5 rounded bg-theme-bg-base text-theme-accent-text font-mono">{{ ui_url }}</code>
                            {{ __('and authenticates via system PAM') }}
                            (<code class="font-mono text-theme-text-primary">{{ pam_service }}</code>).
                            {{ __('Sign in using your server') }} <strong class="text-theme-text-primary">root</strong> {{ __('credentials.') }}
                        </p>
                    </div>
                </div>

                <!-- Technical Details -->
                <div class="p-4 rounded-theme-md bg-theme-bg-surface border border-theme-border text-xs text-theme-text-secondary font-mono space-y-1">
                    <p>
                        {{ __('Status:') }}
                        <span :class="isActive ? 'text-theme-success-text' : 'text-theme-warning-text'" class="font-bold uppercase">
                            {{ status }}
                        </span>
                    </p>
                    <p>{{ __('Service: imunify-antivirus') }} ({{ status }})</p>
                    <p>{{ __('Contract: /etc/sysconfig/imunify360/integration.conf') }}</p>
                    <p>{{ __('UI Path: /usr/local/litecontrol/imunifyav') }}</p>
                </div>
            </UiCard>

            <!-- Maintenance / Repair Card ("Not working?") -->
            <UiCard class="space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h3 class="text-sm font-bold text-theme-text-primary">
                            {{ __('Maintenance & Repair') }}
                        </h3>
                        <p class="text-xs text-theme-text-secondary mt-0.5">
                            {{ __('If the ImunifyAV UI or daemon is not responding, regenerate the integration contract and re-run the official installer.') }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <UiButton
                            variant="secondary"
                            size="sm"
                            :loading="isPolling"
                            :disabled="isPolling"
                            @click="installOrReinstallImunify"
                        >
                            <RefreshCw v-if="!isPolling" class="w-3.5 h-3.5" />
                            <span>{{ isPolling ? __('Reinstalling...') : __('Repair / Reinstall') }}</span>
                        </UiButton>

                        <UiButton
                            variant="danger-soft"
                            size="sm"
                            :disabled="isPolling"
                            @click="uninstallImunify"
                        >
                            <Trash2 class="w-3.5 h-3.5" />
                            <span>{{ __('Uninstall') }}</span>
                        </UiButton>
                    </div>
                </div>

                <div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 text-xs text-theme-accent-text hover:underline"
                        @click="showCliHelp = !showCliHelp"
                    >
                        <Terminal class="w-3.5 h-3.5" />
                        <span>{{ showCliHelp ? __('Hide manual CLI recovery commands') : __('Show manual CLI recovery commands') }}</span>
                    </button>

                    <div
                        v-if="showCliHelp"
                        class="mt-3 p-3.5 rounded-theme-md bg-theme-bg-base border border-theme-border text-xs font-mono text-theme-text-secondary space-y-1"
                    >
                        <p class="text-theme-text-muted"># Re-run the full ImunifyAV integration &amp; installer from terminal:</p>
                        <p class="text-theme-text-primary">lc-cli module install imunifyav</p>
                        <p class="text-theme-text-muted mt-2"># Check daemon &amp; installer log:</p>
                        <p class="text-theme-text-primary">systemctl status imunify-antivirus</p>
                        <p class="text-theme-text-primary">tail -n 50 /var/log/litecontrol/imunifyav-install.log</p>
                    </div>
                </div>
            </UiCard>
        </template>

        <!-- State A: Not Installed -->
        <UiCard v-else class="p-8 text-center space-y-6">
            <div class="w-16 h-16 rounded-theme-lg bg-theme-accent-subtle border border-theme-accent-border text-theme-accent-text flex items-center justify-center mx-auto">
                <Bug class="w-8 h-8" />
            </div>
            <div class="max-w-lg mx-auto space-y-2">
                <h2 class="text-lg font-bold text-theme-text-primary">{{ __('ImunifyAV is Not Installed') }}</h2>
                <p class="text-xs text-theme-text-secondary leading-relaxed">
                    {{ __('ImunifyAV is an on-demand security module in LiteControl. Activating it publishes the CloudLinux integration contract (/etc/sysconfig/imunify360/integration.conf), installs the ImunifyAV agent, and exposes the standalone web scanner UI at') }}
                    <code class="font-mono text-theme-accent-text">{{ ui_url }}</code>.
                </p>
            </div>
            <div>
                <UiButton
                    variant="primary"
                    :loading="isPolling"
                    :disabled="isPolling"
                    @click="installOrReinstallImunify"
                >
                    <Download v-if="!isPolling" class="w-4 h-4" />
                    <span>{{ isPolling ? __('Installing ImunifyAV...') : __('Install ImunifyAV Module') }}</span>
                </UiButton>
            </div>
        </UiCard>

        <!-- Live Installer Transcript / Log Output -->
        <UiCard v-if="isPolling || liveLog" class="space-y-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <Terminal class="w-4 h-4 text-theme-accent" />
                    <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary">
                        {{ isPolling ? __('Live Installation Progress') : __('Last Installation Log') }}
                    </h3>
                </div>
                <span v-if="isPolling" class="text-[11px] font-mono text-theme-accent-text animate-pulse">
                    {{ __('Streaming /var/log/litecontrol/imunifyav-install.log...') }}
                </span>
            </div>

            <pre class="p-4 rounded-theme-md bg-theme-bg-base border border-theme-border text-[11px] font-mono text-theme-text-secondary overflow-x-auto max-h-72 overflow-y-auto whitespace-pre-wrap">{{ liveLog }}</pre>
        </UiCard>
    </div>
</template>
