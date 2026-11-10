<script setup>
import { shallowRef, computed, onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
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
} from 'lucide-vue-next';

import ManageTabNav from '@/Components/Domains/Manage/ManageTabNav.vue';
import ManageActionGrid from '@/Components/Domains/Manage/ManageActionGrid.vue';
import OverviewSection from '@/Components/Domains/Manage/OverviewSection.vue';

import DomainAliasPanel from '@/Components/Domains/Manage/Panels/DomainAliasPanel.vue';
import LogViewerPanel from '@/Components/Domains/Manage/Panels/LogViewerPanel.vue';
import VhostConfPanel from '@/Components/Domains/Manage/Panels/VhostConfPanel.vue';
import RewriteRulesPanel from '@/Components/Domains/Manage/Panels/RewriteRulesPanel.vue';
import ChangePhpPanel from '@/Components/Domains/Manage/Panels/ChangePhpPanel.vue';
import IssueSslPanel from '@/Components/Domains/Manage/Panels/IssueSslPanel.vue';
import CustomSslPanel from '@/Components/Domains/Manage/Panels/CustomSslPanel.vue';
import CreateFtpPanel from '@/Components/Domains/Manage/Panels/CreateFtpPanel.vue';
import DeleteFtpPanel from '@/Components/Domains/Manage/Panels/DeleteFtpPanel.vue';

import { WEBSITE_MANAGE_TABS, WEBSITE_MANAGE_ACTIONS } from '@/Config/websiteManageConfig';

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

const handleTriggerAction = (action) => {
    if (action.type === 'action' && action.actionKey === 'fixPermissions') {
        if (confirm(`Repair permissions for website '${props.domain.domain}'?\n\nThis will restore standard directory (755) and file (644) permissions and ensure panel and web server access.`)) {
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

const toggleSuspend = () => {
    const isSuspended = props.domain.status === 'suspended';
    const action = isSuspended ? 'unsuspend' : 'suspend';
    if (confirm(`Are you sure you want to ${action} website '${props.domain.domain}'?`)) {
        router.post(`/domains/${props.domain.id}/${action}`);
    }
};

const deleteDomain = () => {
    if (confirm(`Terminate domain '${props.domain.domain}'? This removes VirtualHost configuration and website files.`)) {
        router.delete(`/domains/${props.domain.id}`);
    }
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
                    class="p-2 rounded-lg bg-theme-bg-surface hover:bg-theme-border text-theme-text-secondary hover:text-theme-text-primary border border-theme-border transition-colors"
                    title="Return to Domains Registry"
                >
                    <ChevronLeft class="w-4 h-4" />
                </a>

                <div>
                    <div class="flex items-center gap-2.5">
                        <h1 class="text-xl sm:text-2xl font-extrabold text-theme-text-primary tracking-tight">
                            {{ domain.domain }}
                        </h1>
                        <span
                            :class="[
                                'px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider border',
                                domain.status === 'suspended'
                                    ? 'bg-amber-500/10 text-amber-300 border-amber-500/30'
                                    : 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30'
                            ]"
                        >
                            {{ domain.status }}
                        </span>
                        <span
                            :class="[
                                'px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider border flex items-center gap-1',
                                domain.ssl_enabled
                                    ? 'bg-blue-500/10 text-blue-400 border-blue-500/30'
                                    : 'bg-slate-500/10 text-slate-400 border-slate-500/30'
                            ]"
                        >
                            <ShieldCheck v-if="domain.ssl_enabled" class="w-3 h-3 text-blue-400" />
                            <ShieldAlert v-else class="w-3 h-3 text-slate-400" />
                            {{ domain.ssl_enabled ? 'SECURE_TLS' : 'INSECURE' }}
                        </span>
                    </div>

                    <p class="text-xs text-theme-text-secondary mt-1 flex items-center gap-2">
                        <span>Owner: <strong class="text-theme-text-primary">{{ domain.user?.username }}</strong></span>
                        <span>•</span>
                        <span>DocRoot: <code class="font-mono text-[11px] text-theme-accent">{{ domain.document_root }}</code></span>
                        <span>•</span>
                        <span>PHP: <strong class="text-theme-text-primary">{{ domain.php_version }}</strong></span>
                    </p>
                </div>
            </div>

            <!-- Header Quick Actions -->
            <div class="flex items-center gap-2 flex-wrap">
                <a
                    :href="'http://' + domain.domain"
                    target="_blank"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-theme-bg-surface hover:bg-theme-border text-theme-text-secondary hover:text-theme-text-primary text-xs font-semibold border border-theme-border transition-colors"
                >
                    <span>Visit Site</span>
                    <ExternalLink class="w-3.5 h-3.5 text-theme-text-muted" />
                </a>

                <a
                    :href="`/files?domain_id=${domain.id}`"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-cyan-600/10 hover:bg-cyan-600/20 text-cyan-400 text-xs font-semibold border border-cyan-500/30 transition-colors"
                >
                    <Folder class="w-3.5 h-3.5" />
                    <span>File Manager</span>
                </a>

                <button
                    @click="toggleSuspend"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-theme-bg-surface hover:bg-theme-border text-theme-text-secondary hover:text-theme-text-primary text-xs font-semibold border border-theme-border transition-colors"
                >
                    <PlayCircle v-if="domain.status === 'suspended'" class="w-3.5 h-3.5 text-amber-400" />
                    <PauseCircle v-else class="w-3.5 h-3.5 text-amber-400" />
                    <span>{{ domain.status === 'suspended' ? 'Activate' : 'Suspend' }}</span>
                </button>

                <button
                    @click="deleteDomain"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 text-xs font-semibold border border-rose-500/30 transition-colors"
                >
                    <Trash2 class="w-3.5 h-3.5" />
                    <span>Delete</span>
                </button>
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

