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
    CheckCircle2, AlertCircle, Palette, Radio, RefreshCw
} from '@lucide/vue';

const page = usePage();
const flash = computed(() => page.props.flash || {});
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
    return iconMap[name] || Globe;
};

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <div class="min-h-screen bg-theme-bg-main text-theme-text-primary flex flex-col font-sans selection:bg-theme-accent selection:text-theme-text-on-accent">
        <!-- Impersonation Notice Bar -->
        <div v-if="page.props.auth?.is_impersonating" class="bg-theme-warning-bg border-b border-theme-warning-border px-5 py-2 text-xs flex items-center justify-between text-theme-warning-text">
            <div class="flex items-center gap-2.5">
                <AlertCircle class="w-4 h-4 text-theme-warning" />
                <span>{{ __('Impersonating user:') }} <strong class="text-theme-text-primary underline">{{ user.username }}</strong></span>
            </div>
            <form @submit.prevent="router.post('/impersonation/revert')">
                <button type="submit" class="px-3 py-1 rounded-theme-md bg-theme-warning hover:bg-theme-warning-hover text-theme-text-on-accent text-xs font-semibold tracking-wide transition-colors cursor-pointer">
                    Return to Admin
                </button>
            </form>
        </div>

        <!-- Top Header -->
        <header class="h-16 border-b border-theme-border bg-theme-bg-header/90 backdrop-blur-md sticky top-0 z-40 flex items-center justify-between px-4 sm:px-6 lg:px-8">
            <div class="flex items-center gap-4">
                <button
                    @click="sidebarOpen = !sidebarOpen"
                    class="lg:hidden p-2 rounded-theme-md text-theme-text-secondary hover:text-theme-text-primary hover:bg-theme-bg-surface transition-colors"
                    :aria-label="__('Toggle Navigation')"
                >
                    <Menu v-if="!sidebarOpen" class="w-5 h-5" />
                    <X v-else class="w-5 h-5" />
                </button>

                <Link href="/dashboard" class="flex items-center gap-3">
                    <div class="h-9 w-9 rounded-theme-lg bg-theme-accent flex items-center justify-center text-theme-text-on-accent shadow-md">
                        <Globe class="w-5 h-5" />
                    </div>
                    <div class="flex flex-col">
                        <div class="flex items-center gap-2">
                            <span class="text-base font-bold tracking-tight text-theme-text-primary">
                                Lite<span class="text-theme-accent">{{ __('Control') }}</span>
                            </span>
                            <span v-if="isAdmin" class="px-2 py-0.5 rounded-theme-badge text-[10px] font-semibold bg-theme-accent-subtle text-theme-accent-text border border-theme-accent-border">
                                Admin
                            </span>
                        </div>
                    </div>
                </Link>
            </div>

            <!-- Right Controls: Status, Theme Selector & User Profile -->
            <div class="flex items-center gap-3">
                <!-- 2FA Status Quick Link -->
                <div
                    v-if="$page.props.auth?.user?.two_factor_confirmed_at"
                    :title="__('Two-Factor Authentication is enabled')"
                    aria-disabled="true"
                    class="hidden md:flex items-center gap-1.5 px-3 py-1.5 rounded-theme-md bg-theme-bg-surface border border-theme-border text-xs opacity-75 cursor-default select-none"
                >
                    <span class="text-theme-text-secondary font-medium text-[11px]">{{ __('2FA:') }}</span>
                    <span class="font-semibold text-[11px] text-theme-success-text">{{ __('ON') }}</span>
                </div>
                <Link
                    v-else
                    href="/security/two-factor"
                    :title="__('Click to enable Two-Factor Authentication')"
                    class="hidden md:flex items-center gap-1.5 px-3 py-1.5 rounded-theme-md bg-theme-bg-surface border border-theme-border hover:border-theme-border-strong text-xs transition-colors"
                >
                    <span class="text-theme-text-secondary font-medium text-[11px]">{{ __('2FA:') }}</span>
                    <span class="font-semibold text-[11px] text-theme-warning-text">{{ __('Enable') }}</span>
                </Link>

                <!-- Daemon Heartbeat & Interactive Status Checker -->
                <button
                    v-if="isAdmin"
                    @click="fetchOlsStatus"
                    :disabled="checkingOls"
                    :title="checkingOls ? 'Checking OLS Daemon status...' : 'Click to re-check OLS Daemon status'"
                    class="hidden md:flex items-center gap-2 px-3 py-1.5 rounded-theme-md bg-theme-bg-surface border border-theme-border hover:border-theme-border-strong text-xs transition-colors group cursor-pointer focus:outline-none focus:ring-1 focus:ring-theme-accent/50"
                >
                    <RefreshCw v-if="checkingOls" class="w-3.5 h-3.5 text-theme-accent animate-spin" />
                    <Radio
                        v-else
                        class="w-3.5 h-3.5 transition-colors"
                        :class="olsStatus === 'online' ? 'text-theme-success-text animate-pulse' : (olsStatus === 'offline' ? 'text-theme-danger-text' : 'text-theme-warning-text')"
                    />

                    <span class="text-theme-text-secondary font-medium text-[11px]">{{ __('OLS:') }}</span>

                    <span
                        class="font-semibold text-[11px] transition-colors"
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
                        class="p-2 rounded-theme-md bg-theme-bg-surface border border-theme-border text-theme-text-secondary hover:text-theme-text-primary hover:border-theme-border-strong transition-all flex items-center gap-1.5 text-xs font-medium cursor-pointer"
                    >
                        <Palette class="w-4 h-4 text-theme-accent" />
                        <span class="hidden sm:inline capitalize">{{ activeThemeId }}</span>
                        <ChevronDown class="w-3 h-3 text-theme-text-muted" />
                    </button>
                    <div
                        v-if="themeDropdownOpen"
                        class="absolute right-0 mt-2 w-48 rounded-theme-lg bg-theme-bg-elevated border border-theme-border shadow-xl py-1 z-50 text-xs"
                    >
                        <div class="px-3 py-1.5 text-[10px] text-theme-text-muted font-semibold uppercase tracking-wider border-b border-theme-border-subtle">
                            Interface Theme
                        </div>
                        <button
                            v-for="t in availableThemes"
                            :key="t.id"
                            @click="switchTheme(t.id); themeDropdownOpen = false"
                            class="w-full flex items-center justify-between px-3 py-2 text-left hover:bg-theme-bg-surface transition-colors cursor-pointer"
                            :class="activeThemeId === t.id ? 'text-theme-accent-text font-bold bg-theme-accent-subtle' : 'text-theme-text-secondary'"
                        >
                            <span>{{ t.name }}</span>
                            <span v-if="activeThemeId === t.id" class="w-1.5 h-1.5 bg-theme-accent rounded-full"></span>
                        </button>
                    </div>
                </div>

                <!-- User Profile Menu -->
                <div class="relative" ref="userDropdownRef">
                    <button
                        @click="userDropdownOpen = !userDropdownOpen"
                        class="flex items-center gap-2.5 p-1.5 rounded-theme-md hover:bg-theme-bg-surface transition-all focus:outline-none cursor-pointer"
                    >
                        <div class="w-8 h-8 rounded-theme-md bg-theme-accent flex items-center justify-center text-xs font-bold text-theme-text-on-accent shadow-xs">
                            {{ user.username ? user.username.substring(0, 2).toUpperCase() : 'U' }}
                        </div>
                        <div class="hidden sm:flex flex-col text-left">
                            <span class="text-xs font-medium text-theme-text-primary">{{ user.name || user.username }}</span>
                            <span class="text-[10px] text-theme-text-secondary capitalize">{{ user.role || 'user' }}</span>
                        </div>
                        <ChevronDown class="w-3.5 h-3.5 text-theme-text-muted" />
                    </button>

                    <div
                        v-if="userDropdownOpen"
                        class="absolute right-0 mt-2 w-52 rounded-theme-lg bg-theme-bg-elevated border border-theme-border shadow-xl py-1.5 z-50 text-sm"
                    >
                        <div class="px-4 py-2 border-b border-theme-border-subtle">
                            <p class="text-xs font-semibold text-theme-text-primary truncate">{{ user.username }}</p>
                            <p class="text-[11px] text-theme-text-muted truncate">{{ user.email }}</p>
                        </div>
                        <Link
                            href="/security/password"
                            class="flex items-center gap-2.5 px-4 py-2 text-xs text-theme-text-secondary hover:bg-theme-bg-surface hover:text-theme-text-primary transition-colors"
                        >
                            <UserCircle class="w-4 h-4 text-theme-text-muted" />
                            <span>{{ __('Account Settings') }}</span>
                        </Link>
                        <button
                            @click="logout"
                            class="w-full flex items-center gap-2.5 px-4 py-2 text-xs text-theme-danger-text hover:bg-theme-danger-bg text-left transition-colors cursor-pointer"
                        >
                            <LogOut class="w-4 h-4" />
                            <span>{{ __('Sign Out') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <div class="flex-1 flex overflow-hidden">
            <!-- Sidebar Navigation (Left Side) -->
            <aside
                :class="[
                    'fixed inset-y-0 left-0 z-30 w-64 bg-theme-bg-sidebar border-r border-theme-border transform transition-transform duration-200 ease-in-out lg:translate-x-0 lg:static flex flex-col',
                    sidebarOpen ? 'translate-x-0' : '-translate-x-full'
                ]"
            >
                <div class="flex-1 overflow-y-auto px-4 py-5 space-y-1.5 scrollbar-thin">
                    <template v-for="item in filteredNavigation" :key="item.id">
                        <!-- Direct Link without children -->
                        <div v-if="!item.children" class="pt-0.5">
                            <Link
                                :href="item.route"
                                class="flex items-center justify-between px-3.5 py-2.5 rounded-theme-lg text-xs font-medium transition-all"
                                :class="isItemActive(item)
                                    ? 'bg-theme-accent-subtle text-theme-accent-text font-semibold shadow-xs'
                                    : 'text-theme-text-secondary hover:bg-theme-bg-surface hover:text-theme-text-primary'"
                            >
                                <div class="flex items-center gap-3">
                                    <component :is="getIcon(item.icon)" class="w-4 h-4" :class="isItemActive(item) ? 'text-theme-accent' : 'text-theme-text-muted'" />
                                    <span>{{ item.label }}</span>
                                </div>
                                <span v-if="isItemActive(item)" class="w-1.5 h-1.5 bg-theme-accent rounded-full"></span>
                            </Link>
                        </div>

                        <!-- Menu with sub-routes -->
                        <div v-else class="pt-1">
                            <button
                                @click="toggleSubmenu(item.id)"
                                class="w-full flex items-center justify-between px-3.5 py-2.5 rounded-theme-lg text-xs font-medium transition-all cursor-pointer"
                                :class="isItemActive(item) ? 'text-theme-text-primary bg-theme-bg-surface/60' : 'text-theme-text-secondary hover:bg-theme-bg-surface hover:text-theme-text-primary'"
                            >
                                <div class="flex items-center gap-3">
                                    <component :is="getIcon(item.icon)" class="w-4 h-4" :class="isItemActive(item) ? 'text-theme-accent' : 'text-theme-text-muted'" />
                                    <span>{{ item.label }}</span>
                                </div>
                                <ChevronDown
                                    class="w-3.5 h-3.5 text-theme-text-muted transition-transform duration-200"
                                    :class="{'rotate-180': openSubmenus[item.id]}"
                                />
                            </button>
                            <div v-if="openSubmenus[item.id]" class="mt-1 pl-7 pr-2 space-y-1 border-l border-theme-border ml-5">
                                <template v-for="child in item.children" :key="child.id">
                                    <a
                                        v-if="child.external"
                                        :href="child.route"
                                        target="_blank"
                                        class="flex items-center justify-between px-3 py-2 rounded-theme-md text-xs text-theme-text-secondary hover:text-theme-text-primary hover:bg-theme-bg-surface transition-colors"
                                    >
                                        <span>{{ child.label }}</span>
                                        <ExternalLink class="w-3.5 h-3.5 text-theme-text-muted" />
                                    </a>
                                    <Link
                                        v-else
                                        :href="child.route"
                                        class="block px-3 py-2 rounded-theme-md text-xs transition-colors"
                                        :class="isRouteActive(child.route)
                                            ? 'text-theme-accent-text bg-theme-accent-subtle font-medium'
                                            : 'text-theme-text-secondary hover:text-theme-text-primary hover:bg-theme-bg-surface'"
                                    >
                                        {{ child.label }}
                                    </Link>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Footer Baseline in Sidebar -->
                <div class="p-4 border-t border-theme-border text-xs text-theme-text-secondary flex items-center justify-between">
                    <span>{{ __('LiteControl v1.0.0') }}</span>
                    <span v-if="page.props.panel?.is_mock_mode" class="px-2 py-0.5 rounded-theme-sm bg-theme-warning-bg text-theme-warning-text border border-theme-warning-border text-[10px]">
                        MOCK
                    </span>
                    <span v-else class="text-theme-success-text font-medium text-[11px]">{{ __('Active') }}</span>
                </div>
            </aside>

            <!-- Main Viewport -->
            <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 bg-theme-bg-main">
                <div class="max-w-7xl mx-auto">
                    <!-- Flash Alerts -->
                    <div v-if="flash.success" class="mb-5 p-4 rounded-theme-lg bg-theme-success-bg border border-theme-success-border text-theme-success-text text-sm flex items-center gap-3">
                        <CheckCircle2 class="w-5 h-5 text-theme-success shrink-0" />
                        <span>{{ flash.success }}</span>
                    </div>
                    <div v-if="flash.error" class="mb-5 p-4 rounded-theme-lg bg-theme-danger-bg border border-theme-danger-border text-theme-danger-text text-sm flex items-center gap-3">
                        <AlertCircle class="w-5 h-5 text-theme-danger shrink-0" />
                        <span>{{ flash.error }}</span>
                    </div>

                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
