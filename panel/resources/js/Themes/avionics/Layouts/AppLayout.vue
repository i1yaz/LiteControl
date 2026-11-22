<script setup lang="ts">
import { shallowRef, ref, computed, onMounted, useTemplateRef } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import { useClickOutside } from '@shared/Composables/useClickOutside';
import { useNavigation } from '@shared/Composables/useNavigation';
import { useTheme } from '@shared/Composables/useTheme';
import LocaleSwitcher from '@shared/Components/LocaleSwitcher.vue';
import {
    LayoutDashboard, Users, Globe, Database, FolderTree,
    FileCode, Sliders, Server, Shield, Mail, Layers,
    UserCircle, LogOut, ChevronDown, Menu, X, ExternalLink,
    CheckCircle2, AlertCircle, Cpu, Radio, Palette, RefreshCw, Copy, Check
} from '@lucide/vue';

const page = usePage();
const flash = computed(() => page.props.flash || {});
const serverIp = computed(() => (page.props.panel as { server_ip?: string } | undefined)?.server_ip || '');
const ipCopied = shallowRef(false);
let ipCopyTimer: ReturnType<typeof setTimeout> | null = null;

const copyServerIp = async () => {
    if (!serverIp.value) return;
    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(serverIp.value);
        } else {
            const textarea = document.createElement('textarea');
            textarea.value = serverIp.value;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        }
        ipCopied.value = true;
        if (ipCopyTimer) clearTimeout(ipCopyTimer);
        ipCopyTimer = setTimeout(() => {
            ipCopied.value = false;
        }, 1800);
    } catch {
        // Ignore clipboard errors in restricted contexts
    }
};
const { user, isAdmin, filteredNavigation, openSubmenus, toggleSubmenu, isRouteActive, isItemActive } = useNavigation();
const { activeThemeId, availableThemes, switchTheme } = useTheme();

const sidebarOpen = shallowRef(false);
const userDropdownOpen = shallowRef(false);
const userDropdownRef = useTemplateRef('userDropdownRef');

useClickOutside(userDropdownRef, () => {
    userDropdownOpen.value = false;
});

const themeDropdownOpen = shallowRef(false);
const themeDropdownRef = useTemplateRef('themeDropdownRef');

useClickOutside(themeDropdownRef, () => {
    themeDropdownOpen.value = false;
});

// OLS Daemon Status
const olsStatus = ref('checking'); // 'checking' | 'online' | 'offline'
const checkingOls = ref(false);

const fetchOlsStatus = async () => {
    if (checkingOls.value) return;
    checkingOls.value = true;
    try {
        const response = await axios.get('/server/ols-status');
        olsStatus.value = response.data?.status === 'online' ? 'online' : 'offline';
    } catch {
        olsStatus.value = 'offline';
    } finally {
        checkingOls.value = false;
    }
};

onMounted(() => {
    if (isAdmin.value) {
        fetchOlsStatus();
    }
});

const iconMap = {
    dashboard: LayoutDashboard,
    users: Users,
    domains: Globe,
    database: Database,
    files: FolderTree,
    php: FileCode,
    configuration: Sliders,
    server: Server,
    security: Shield,
    modules: Layers,
    email: Mail,
};

const getIcon = (name) => {
    return iconMap[name] || Cpu;
};

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <div class="min-h-screen bg-theme-bg-main text-theme-text-primary flex flex-col font-sans selection:bg-theme-accent selection:text-theme-text-on-accent">
        <!-- Impersonation Notice Bar -->
        <div v-if="page.props.auth?.is_impersonating" class="bg-theme-accent-subtle border-b border-theme-accent-border px-5 py-2 text-xs flex items-center justify-between text-theme-accent-text">
            <div class="flex items-center gap-2.5 font-mono">
                <AlertCircle class="w-4 h-4 text-theme-accent" />
                <span>{{ __('ACTIVE_IMPERSONATION // TARGET:') }} <strong class="text-theme-text-primary underline">{{ user.username }}</strong></span>
            </div>
            <form @submit.prevent="router.post('/impersonation/revert')">
                <button type="submit" class="px-3 py-1 rounded-theme-sm bg-theme-accent hover:bg-theme-accent-hover text-theme-text-on-accent text-xs font-mono font-bold uppercase tracking-wider transition-colors cursor-pointer">
                    Revert Context
                </button>
            </form>
        </div>

        <!-- Top Flight Instrumentation Header -->
        <header class="h-16 border-b border-theme-border bg-theme-bg-header/95 backdrop-blur-md sticky top-0 z-40 flex items-center justify-between px-5 sm:px-7">
            <div class="flex items-center gap-5">
                <button
                    @click="sidebarOpen = !sidebarOpen"
                    class="lg:hidden p-2 rounded-theme-sm border border-theme-border text-theme-text-secondary hover:text-theme-text-primary hover:bg-theme-bg-surface transition-all"
                    :aria-label="__('Toggle Navigation')"
                >
                    <Menu v-if="!sidebarOpen" class="w-4 h-4" />
                    <X v-else class="w-4 h-4" />
                </button>

                <!-- Brand Architecture -->
                <Link href="/dashboard" class="flex items-center gap-3 group">
                    <div class="h-9 w-9 rounded-theme-sm bg-theme-bg-surface border border-theme-border-strong flex items-center justify-center text-theme-accent group-hover:border-theme-accent transition-colors">
                        <Cpu class="w-4 h-4 transition-transform group-hover:scale-110" />
                    </div>
                    <div class="flex flex-col">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-black tracking-tight text-theme-text-primary font-mono uppercase">
                                Lite<span class="text-theme-accent">{{ __('Control') }}</span>
                            </span>
                            <span v-if="isAdmin" class="px-1.5 py-0.2 rounded-theme-badge text-[9px] font-mono font-bold bg-theme-accent-subtle text-theme-accent-text border border-theme-accent-border">
                                ROOT:WHM
                            </span>
                        </div>
                        <span class="text-[9px] font-mono text-theme-text-muted uppercase tracking-widest">
                            LSWS · Avionics Matrix
                        </span>
                    </div>
                </Link>
            </div>

            <!-- Global Status Bar & Profile -->
            <div class="flex items-center gap-3 sm:gap-4">
                <!-- Server IP (Click to Copy) -->
                <button
                    v-if="serverIp"
                    type="button"
                    @click="copyServerIp"
                    :title="ipCopied ? __('IP copied to clipboard') : __('Click to copy server IP')"
                    class="hidden sm:flex items-center gap-1.5 px-3 py-1 rounded-theme-sm bg-theme-bg-surface border border-theme-border hover:border-theme-border-strong text-xs font-mono transition-colors cursor-pointer group focus:outline-none focus:ring-1 focus:ring-theme-accent/50"
                >
                    <span class="text-theme-text-secondary">{{ __('IP:') }}</span>
                    <span class="text-theme-text-primary font-semibold">{{ serverIp }}</span>
                    <Check v-if="ipCopied" class="w-3.5 h-3.5 text-theme-success-text ml-0.5" />
                    <Copy v-else class="w-3 h-3 text-theme-text-muted group-hover:text-theme-text-primary transition-colors ml-0.5" />
                </button>

                <!-- 2FA Status Quick Link -->
                <div
                    v-if="$page.props.auth?.user?.two_factor_confirmed_at"
                    :title="__('Two-Factor Authentication is enabled')"
                    aria-disabled="true"
                    class="hidden md:flex items-center gap-1.5 px-3 py-1 rounded-theme-sm bg-theme-bg-surface border border-theme-border text-xs font-mono opacity-75 cursor-default select-none"
                >
                    <span class="text-theme-text-secondary">{{ __('2FA:') }}</span>
                    <span class="font-bold text-theme-success-text">{{ __('ON') }}</span>
                </div>
                <Link
                    v-else
                    href="/security/two-factor"
                    :title="__('Click to enable Two-Factor Authentication')"
                    class="hidden md:flex items-center gap-1.5 px-3 py-1 rounded-theme-sm bg-theme-bg-surface border border-theme-border hover:border-theme-border-strong text-xs font-mono transition-colors"
                >
                    <span class="text-theme-text-secondary">{{ __('2FA:') }}</span>
                    <span class="font-bold text-theme-warning-text">{{ __('ENABLE') }}</span>
                </Link>

                <!-- Daemon Heartbeat & Interactive Status Checker -->
                <button
                    v-if="isAdmin"
                    @click="fetchOlsStatus"
                    :disabled="checkingOls"
                    :title="checkingOls ? 'Checking OLS Daemon status...' : 'Click to re-check OLS Daemon status'"
                    class="hidden md:flex items-center gap-2 px-3 py-1 rounded-theme-sm bg-theme-bg-surface border border-theme-border hover:border-theme-border-strong text-xs font-mono transition-colors group cursor-pointer focus:outline-none focus:ring-1 focus:ring-theme-accent/50"
                >
                    <RefreshCw v-if="checkingOls" class="w-3.5 h-3.5 text-theme-accent animate-spin" />
                    <Radio
                        v-else
                        class="w-3.5 h-3.5 transition-colors"
                        :class="olsStatus === 'online' ? 'text-theme-success-text animate-pulse' : (olsStatus === 'offline' ? 'text-theme-danger-text' : 'text-theme-warning-text')"
                    />

                    <span class="text-theme-text-secondary">{{ __('OLS DAEMON:') }}</span>

                    <span
                        class="font-bold transition-colors"
                        :class="{
                            'text-theme-success-text': olsStatus === 'online',
                            'text-theme-danger-text': olsStatus === 'offline',
                            'text-theme-warning-text': olsStatus === 'checking'
                        }"
                    >
                        {{ olsStatus.toUpperCase() }}
                    </span>

                    <RefreshCw
                        class="w-3 h-3 text-theme-text-muted group-hover:text-theme-text-primary transition-colors ml-0.5"
                        :class="{ 'animate-spin text-theme-accent': checkingOls }"
                    />
                </button>

                <LocaleSwitcher />

                <!-- Theme Switcher Dropdown -->
                <div class="relative" ref="themeDropdownRef">
                    <button
                        @click="themeDropdownOpen = !themeDropdownOpen"
                        :title="__('Switch Interface Theme')"
                        class="p-2 rounded-theme-sm bg-theme-bg-surface border border-theme-border hover:border-theme-border-strong text-theme-text-secondary hover:text-theme-text-primary transition-all flex items-center gap-1.5 text-xs font-mono cursor-pointer"
                    >
                        <Palette class="w-3.5 h-3.5 text-theme-accent" />
                        <span class="hidden sm:inline capitalize">{{ activeThemeId }}</span>
                        <ChevronDown class="w-3 h-3 text-theme-text-muted" />
                    </button>
                    <div
                        v-if="themeDropdownOpen"
                        class="absolute right-0 mt-2 w-48 rounded-theme-sm bg-theme-bg-elevated border border-theme-border-strong shadow-2xl py-1 z-50 font-mono text-xs"
                    >
                        <div class="px-3 py-1.5 text-[10px] text-theme-text-muted uppercase tracking-wider border-b border-theme-border-subtle">
                            Theme Engine
                        </div>
                        <button
                            v-for="t in availableThemes"
                            :key="t.id"
                            @click="switchTheme(t.id); themeDropdownOpen = false"
                            class="w-full flex items-center justify-between px-3 py-2 text-left hover:bg-theme-bg-surface transition-colors cursor-pointer"
                            :class="activeThemeId === t.id ? 'text-theme-accent font-bold bg-theme-accent-subtle' : 'text-theme-text-secondary'"
                        >
                            <span>{{ t.name }}</span>
                            <span v-if="activeThemeId === t.id" class="w-1.5 h-1.5 bg-theme-accent rounded-full"></span>
                        </button>
                    </div>
                </div>

                <!-- Account Instrument -->
                <div class="relative" ref="userDropdownRef">
                    <button
                        @click="userDropdownOpen = !userDropdownOpen"
                        class="flex items-center gap-2.5 p-1.5 rounded-theme-sm hover:bg-theme-bg-surface border border-transparent hover:border-theme-border transition-all focus:outline-none cursor-pointer"
                    >
                        <div class="w-7 h-7 rounded-theme-sm bg-theme-bg-surface border border-theme-border-strong flex items-center justify-center text-xs font-mono font-bold text-theme-accent-text">
                            {{ user.username ? user.username.substring(0, 2).toUpperCase() : 'RT' }}
                        </div>
                        <div class="hidden sm:flex flex-col text-left">
                            <span class="text-xs font-medium text-theme-text-primary leading-tight">{{ user.name || user.username }}</span>
                            <span class="text-[9px] font-mono text-theme-text-muted uppercase">{{ user.role || 'user' }}</span>
                        </div>
                        <ChevronDown class="w-3.5 h-3.5 text-theme-text-muted" />
                    </button>

                    <!-- Tactical Dropdown -->
                    <div
                        v-if="userDropdownOpen"
                        class="absolute right-0 mt-2 w-52 rounded-theme-sm bg-theme-bg-elevated border border-theme-border-strong shadow-2xl py-1.5 z-50 font-mono"
                    >
                        <div class="px-3 py-2 border-b border-theme-border-subtle">
                            <p class="text-xs font-semibold text-theme-text-primary truncate">{{ user.username }}</p>
                            <p class="text-[10px] text-theme-text-muted truncate">{{ user.email }}</p>
                        </div>
                        <Link
                            href="/security/password"
                            class="flex items-center gap-2.5 px-3 py-2 text-xs text-theme-text-secondary hover:bg-theme-bg-surface hover:text-theme-text-primary transition-colors"
                        >
                            <UserCircle class="w-3.5 h-3.5 text-theme-text-muted" />
                            <span>{{ __('Security Profile') }}</span>
                        </Link>
                        <button
                            @click="logout"
                            class="w-full flex items-center gap-2.5 px-3 py-2 text-xs text-theme-danger-text hover:bg-theme-danger-bg text-left transition-colors cursor-pointer"
                        >
                            <LogOut class="w-3.5 h-3.5" />
                            <span>{{ __('Terminate Session') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <div class="flex-1 flex overflow-hidden">
            <!-- Sidebar Navigation -->
            <aside
                :class="[
                    'fixed inset-y-0 left-0 z-30 w-64 bg-theme-bg-sidebar border-r border-theme-border-subtle transform transition-transform duration-200 ease-in-out lg:translate-x-0 lg:static flex flex-col',
                    sidebarOpen ? 'translate-x-0' : '-translate-x-full'
                ]"
            >
                <div class="flex-1 overflow-y-auto px-3.5 py-4 space-y-1 scrollbar-thin">
                    <template v-for="item in filteredNavigation" :key="item.id">
                        <!-- Direct Link without children -->
                        <div v-if="!item.children" class="pt-0.5">
                            <Link
                                :href="item.route"
                                class="flex items-center justify-between px-3 py-2 rounded-theme-sm text-xs font-mono font-medium transition-colors"
                                :class="isItemActive(item)
                                    ? 'bg-theme-accent-subtle text-theme-accent-text border border-theme-accent-border'
                                    : 'text-theme-text-secondary hover:bg-theme-bg-surface hover:text-theme-text-primary border border-transparent'"
                            >
                                <div class="flex items-center gap-2.5">
                                    <component :is="getIcon(item.icon)" class="w-3.5 h-3.5" :class="isItemActive(item) ? 'text-theme-accent' : 'text-theme-text-muted'" />
                                    <span>{{ item.label }}</span>
                                </div>
                                <span v-if="isItemActive(item)" class="w-1.5 h-1.5 bg-theme-accent"></span>
                            </Link>
                        </div>

                        <!-- Menu with sub-routes -->
                        <div v-else class="pt-1">
                            <button
                                @click="toggleSubmenu(item.id)"
                                class="w-full flex items-center justify-between px-3 py-2 rounded-theme-sm text-xs font-mono transition-colors cursor-pointer"
                                :class="isItemActive(item) ? 'text-theme-text-primary' : 'text-theme-text-secondary hover:bg-theme-bg-surface hover:text-theme-text-primary'"
                            >
                                <div class="flex items-center gap-2.5">
                                    <component :is="getIcon(item.icon)" class="w-3.5 h-3.5" :class="isItemActive(item) ? 'text-theme-accent' : 'text-theme-text-muted'" />
                                    <span>{{ item.label }}</span>
                                </div>
                                <ChevronDown
                                    class="w-3 h-3 text-theme-text-muted transition-transform"
                                    :class="{'rotate-180': openSubmenus[item.id]}"
                                />
                            </button>
                            <div v-if="openSubmenus[item.id]" class="mt-1 pl-7 pr-2 space-y-0.5 border-l border-theme-border-subtle ml-4">
                                <template v-for="child in item.children" :key="child.id">
                                    <a
                                        v-if="child.external"
                                        :href="child.route"
                                        target="_blank"
                                        class="flex items-center justify-between px-2.5 py-1.5 rounded-theme-sm text-xs font-mono text-theme-text-muted hover:text-theme-text-primary hover:bg-theme-bg-surface"
                                    >
                                        <span>{{ child.label }}</span>
                                        <ExternalLink class="w-3 h-3 text-theme-text-muted" />
                                    </a>
                                    <Link
                                        v-else
                                        :href="child.route"
                                        class="block px-2.5 py-1.5 rounded-theme-sm text-xs font-mono transition-colors"
                                        :class="isRouteActive(child.route)
                                            ? 'text-theme-accent-text bg-theme-accent-subtle font-bold'
                                            : 'text-theme-text-muted hover:text-theme-text-primary hover:bg-theme-bg-surface'"
                                    >
                                        {{ child.label }}
                                    </Link>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Footer Baseline -->
                <div class="p-3 border-t border-theme-border-subtle text-[10px] font-mono text-theme-text-muted flex items-center justify-between">
                    <span>{{ __('SYS // v1.0.0') }}</span>
                    <span v-if="page.props.panel?.is_mock_mode" class="px-1.5 py-0.5 bg-theme-accent-subtle text-theme-accent-text border border-theme-accent-border">
                        EMULATOR
                    </span>
                    <span v-else class="text-theme-success-text font-semibold">{{ __('STANDALONE') }}</span>
                </div>
            </aside>

            <!-- Main Telemetry Viewport -->
            <main class="flex-1 overflow-y-auto p-4 sm:p-7 bg-theme-bg-main">
                <!-- Notifications -->
                <div v-if="flash.success" class="mb-5 p-3.5 rounded-theme-sm bg-theme-success-bg border border-theme-success-border text-theme-success-text font-mono text-xs flex items-center gap-3">
                    <CheckCircle2 class="w-4 h-4 text-theme-success shrink-0" />
                    <span>{{ flash.success }}</span>
                </div>
                <div v-if="flash.error" class="mb-5 p-3.5 rounded-theme-sm bg-theme-danger-bg border border-theme-danger-border text-theme-danger-text font-mono text-xs flex items-center gap-3">
                    <AlertCircle class="w-4 h-4 text-theme-danger shrink-0" />
                    <span>{{ flash.error }}</span>
                </div>

                <slot />
            </main>
        </div>
    </div>
</template>
