<script setup lang="ts">
import { shallowRef, computed, onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiToggle from '@shared/Ui/UiToggle.vue';
import {
    Globe,
    Folder,
    ShieldCheck,
    ShieldAlert,
    PauseCircle,
    PlayCircle,
    Trash2,
    ExternalLink,
    ChevronLeft
} from '@lucide/vue';

import ManageTabNav from '@domains/WebServer/Components/Manage/ManageTabNav.vue';
import ManageActionGrid from '@domains/WebServer/Components/Manage/ManageActionGrid.vue';
import OverviewSection from '@domains/WebServer/Components/Manage/OverviewSection.vue';

import DomainAliasPanel from '@domains/WebServer/Components/Manage/Panels/DomainAliasPanel.vue';
import LogViewerPanel from '@domains/WebServer/Components/Manage/Panels/LogViewerPanel.vue';
import VhostConfPanel from '@domains/WebServer/Components/Manage/Panels/VhostConfPanel.vue';
import RewriteRulesPanel from '@domains/WebServer/Components/Manage/Panels/RewriteRulesPanel.vue';
import ChangePhpPanel from '@domains/WebServer/Components/Manage/Panels/ChangePhpPanel.vue';
import IssueSslPanel from '@domains/WebServer/Components/Manage/Panels/IssueSslPanel.vue';
import CustomSslPanel from '@domains/WebServer/Components/Manage/Panels/CustomSslPanel.vue';
import CreateFtpPanel from '@domains/WebServer/Components/Manage/Panels/CreateFtpPanel.vue';
import DeleteFtpPanel from '@domains/WebServer/Components/Manage/Panels/DeleteFtpPanel.vue';

import { WEBSITE_MANAGE_TABS, WEBSITE_MANAGE_ACTIONS } from '@domains/WebServer/Config/websiteManageConfig';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
    databasesCount: {
        type: Number,
        default: 0,
    },
    totalSizeBytes: {
        type: Number,
        default: 0,
    },
    totalSizeHuman: {
        type: String,
        default: '0 B',
    },
    installedPhpVersions: {
        type: Array,
        default: () => [],
    },
    activeTab: {
        type: String,
        default: 'overview',
    },
});

const currentTab = shallowRef(props.activeTab || 'overview');
const activeAction = shallowRef(null);

const panelComponentMap = {
    DomainAliasPanel,
    LogViewerPanel,
    VhostConfPanel,
    RewriteRulesPanel,
    ChangePhpPanel,
    IssueSslPanel,
    CustomSslPanel,
    CreateFtpPanel,
    DeleteFtpPanel,
};

const tabActions = computed(() => {
    return WEBSITE_MANAGE_ACTIONS.filter(a => a.tabId === currentTab.value);
});

const selectTab = (tabId) => {
    currentTab.value = tabId;
    activeAction.value = null;

    // Update browser URL query without reload
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tabId);
    window.history.replaceState({}, '', url.toString());
};

const handleTriggerAction = async (action) => {
    if (action.type === 'action' && action.actionKey === 'fixPermissions') {
        if (await confirmDialog({
            title: 'Repair Permissions',
            message: `Repair permissions for website '${props.domain.domain}'?\n\nThis will restore standard directory (755) and file (644) permissions and ensure panel and web server access.`,
            confirmText: 'Repair',
            variant: 'warning',
        })) {
            router.post(`/domains/${props.domain.id}/fix-permissions`, {}, {
                preserveScroll: true,
            });
        }
        return;
    }

    if (activeAction.value?.id === action.id) {
        activeAction.value = null; // Toggle close
    } else {
        activeAction.value = action;
    }
};

const closePanel = () => {
    activeAction.value = null;
};

const toggleSuspend = async () => {
    const isSuspended = props.domain.status === 'suspended';
    const action = isSuspended ? 'unsuspend' : 'suspend';
    if (await confirmDialog({
        title: isSuspended ? 'Unsuspend Website' : 'Suspend Website',
        message: `Are you sure you want to ${action} website '${props.domain.domain}'?`,
        confirmText: isSuspended ? 'Unsuspend' : 'Suspend',
        variant: isSuspended ? 'primary' : 'warning',
    })) {
        router.post(`/domains/${props.domain.id}/${action}`);
    }
};

const deleteDomain = async () => {
    if (await confirmDialog({
        title: 'Terminate Domain',
        message: `Terminate domain '${props.domain.domain}'? This removes VirtualHost configuration and website files.`,
        confirmText: 'Terminate',
        variant: 'danger',
    })) {
        router.delete(`/domains/${props.domain.id}`);
    }
};

const toggleOpenBasedir = () => {
    router.post(`/domains/${props.domain.id}/open-basedir`, {
        enabled: !props.domain.open_basedir_enabled,
    }, { preserveScroll: true });
};

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    const tabParam = params.get('tab');
    if (tabParam && WEBSITE_MANAGE_TABS.some(t => t.id === tabParam)) {
        currentTab.value = tabParam;
    }
});
</script>

<template>
    <Head :title="`Manage Website: ${domain.domain} - LiteControl`" />

    <div class="space-y-6">
        <!-- Back Navigation & Top Bar -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a
                    href="/domains"
                    class="p-2 rounded-theme-md bg-theme-bg-surface hover:bg-theme-border text-theme-text-secondary hover:text-theme-text-primary border border-theme-border transition-colors"
                    :title="__('Return to Domains Registry')"
                >
                    <ChevronLeft class="w-4 h-4" />
                </a>

                <div>
                    <div class="flex items-center gap-2.5">
                        <h1 class="text-xl sm:text-2xl font-extrabold text-theme-text-primary tracking-tight">
                            {{ domain.domain }}
                        </h1>
                        <UiBadge
                            :variant="domain.status === 'suspended' ? 'warning' : 'success'"
                            class="uppercase tracking-wider"
                        >
                            {{ domain.status }}
                        </UiBadge>
                        <UiBadge
                            :variant="domain.ssl_enabled ? 'info' : 'neutral'"
                            class="uppercase tracking-wider"
                        >
                            <ShieldCheck v-if="domain.ssl_enabled" class="w-3 h-3" />
                            <ShieldAlert v-else class="w-3 h-3" />
                            {{ domain.ssl_enabled ? 'SECURE_TLS' : 'INSECURE' }}
                        </UiBadge>
                    </div>

                    <p class="text-xs text-theme-text-secondary mt-1 flex items-center gap-2">
                        <span>{{ __('Owner:') }} <strong class="text-theme-text-primary">{{ domain.user?.username }}</strong></span>
                        <span>•</span>
                        <span>{{ __('DocRoot:') }} <code class="font-mono text-[11px] text-theme-accent">{{ domain.document_root }}</code></span>
                        <span>•</span>
                        <span>{{ __('PHP:') }} <strong class="text-theme-text-primary">{{ domain.php_version }}</strong></span>
                    </p>
                </div>
            </div>

            <!-- Header Quick Actions -->
            <div class="flex items-center gap-2 flex-wrap">
                <a
                    :href="'http://' + domain.domain"
                    target="_blank"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-theme-md bg-theme-bg-surface hover:bg-theme-border text-theme-text-secondary hover:text-theme-text-primary text-xs font-semibold border border-theme-border transition-colors"
                >
                    <span>{{ __('Visit Site') }}</span>
                    <ExternalLink class="w-3.5 h-3.5 text-theme-text-muted" />
                </a>

                <a
                    :href="`/files?domain_id=${domain.id}`"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-theme-md bg-theme-info-bg hover:bg-theme-info/20 text-theme-info-text text-xs font-semibold border border-theme-info-border transition-colors"
                >
                    <Folder class="w-3.5 h-3.5" />
                    <span>{{ __('File Manager') }}</span>
                </a>

                <UiButton
                    size="sm"
                    variant="secondary"
                    @click="toggleSuspend"
                >
                    <PlayCircle v-if="domain.status === 'suspended'" class="w-3.5 h-3.5 text-theme-warning-text" />
                    <PauseCircle v-else class="w-3.5 h-3.5 text-theme-warning-text" />
                    <span>{{ domain.status === 'suspended' ? 'Activate' : 'Suspend' }}</span>
                </UiButton>

                <UiButton
                    size="sm"
                    variant="danger-soft"
                    @click="deleteDomain"
                >
                    <Trash2 class="w-3.5 h-3.5" />
                    <span>{{ __('Delete') }}</span>
                </UiButton>
            </div>
        </div>

        <!-- Tab Navigation (Overview, Domains, Logs, Config, SSL, Files) -->
        <ManageTabNav
            :tabs="WEBSITE_MANAGE_TABS"
            :active-tab="currentTab"
            @select="selectTab"
        />

        <!-- Active Tab Container -->
        <div class="space-y-6">
            <!-- 1. Overview Tab -->
            <div v-if="currentTab === 'overview'">
                <OverviewSection
                    :domain="domain"
                    :databases-count="databasesCount"
                    :total-size-human="totalSizeHuman"
                />
            </div>

            <!-- Other Tabs: Action Buttons Grid -->
            <div v-else class="space-y-6">
                <!-- open_basedir Protection Toggle (Files Tab) -->
                <div v-if="currentTab === 'files'" class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-4">
                    <UiToggle
                        :model-value="!!domain.open_basedir_enabled"
                        @update:model-value="toggleOpenBasedir"
                        :label="__('PHP open_basedir Protection')"
                        description="Restrict PHP file access to this domain's root directory and /tmp. Default: OFF."
                    />
                </div>

                <ManageActionGrid
                    :actions="tabActions"
                    :active-action="activeAction"
                    :domain="domain"
                    @trigger="handleTriggerAction"
                />

                <!-- Dynamic Action Panel Container -->
                <transition
                    enter-active-class="transition duration-200 ease-out"
                    enter-from-class="transform opacity-0 -translate-y-2"
                    enter-to-class="transform opacity-100 translate-y-0"
                    leave-active-class="transition duration-150 ease-in"
                    leave-from-class="transform opacity-100 translate-y-0"
                    leave-to-class="transform opacity-0 -translate-y-2"
                >
                    <div v-if="activeAction && activeAction.type === 'panel' && panelComponentMap[activeAction.panelKey]">
                        <component
                            :is="panelComponentMap[activeAction.panelKey]"
                            :domain="domain"
                            :action-props="activeAction.props || {}"
                            :installed-php-versions="installedPhpVersions"
                            @close="closePanel"
                        />
                    </div>
                </transition>
            </div>
        </div>
    </div>
</template>

