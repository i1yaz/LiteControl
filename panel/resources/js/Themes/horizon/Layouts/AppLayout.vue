<script setup>
import { shallowRef, ref, computed, onMounted, useTemplateRef } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import { useClickOutside } from '@/Composables/useClickOutside';
import { useNavigation } from '@/Composables/useNavigation';
import { useTheme } from '@/Composables/useTheme';
import {
    LayoutDashboard, Users, Globe, Database, FolderTree,
    FileCode, Sliders, Server, Shield, Mail, Layers,
    UserCircle, LogOut, ChevronDown, Menu, X, ExternalLink,
    CheckCircle2, AlertCircle, Palette, Radio, RefreshCw
} from 'lucide-vue-next';

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
    } catch (err) {
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
    <div class="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans selection:bg-sky-500 selection:text-white">
        <!-- Impersonation Notice Bar -->
        <div v-if="page.props.auth?.is_impersonating" class="bg-amber-500/15 border-b border-amber-500/30 px-5 py-2 text-xs flex items-center justify-between text-amber-200">
            <div class="flex items-center gap-2.5">
                <AlertCircle class="w-4 h-4 text-amber-400" />
                <span>Impersonating user: <strong class="text-white underline">{{ user.username }}</strong></span>
            </div>
            <form @submit.prevent="router.post('/impersonation/revert')">
                <button type="submit" class="px-3 py-1 rounded-md bg-amber-500 hover:bg-amber-400 text-slate-950 text-xs font-semibold tracking-wide transition-colors">
                    Return to Admin
                </button>
            </form>
        </div>

        <!-- Top Header -->
        <header class="h-16 border-b border-slate-800 bg-slate-900/90 backdrop-blur-md sticky top-0 z-40 flex items-center justify-between px-4 sm:px-6 lg:px-8">
            <div class="flex items-center gap-4">
                <button
                    @click="sidebarOpen = !sidebarOpen"
                    class="lg:hidden p-2 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
                    aria-label="Toggle Navigation"
                >
                    <Menu v-if="!sidebarOpen" class="w-5 h-5" />
                    <X v-else class="w-5 h-5" />
                </button>

                <Link href="/dashboard" class="flex items-center gap-3">
                    <div class="h-9 w-9 rounded-xl bg-gradient-to-tr from-sky-600 to-indigo-600 flex items-center justify-center text-white shadow-md shadow-sky-500/20">
                        <Globe class="w-5 h-5" />
                    </div>
                    <div class="flex flex-col">
                        <div class="flex items-center gap-2">
                            <span class="text-base font-bold tracking-tight text-white">
                                Lite<span class="text-sky-400">Control</span>
                            </span>
                            <span v-if="isAdmin" class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-sky-500/10 text-sky-400 border border-sky-500/20">
                                Admin
                            </span>
                        </div>
                    </div>
                </Link>
            </div>

            <!-- Right Controls: Status, Theme Selector & User Profile -->
            <div class="flex items-center gap-3">
                <!-- Daemon Heartbeat & Interactive Status Checker -->
                <button
                    v-if="isAdmin"
                    @click="fetchOlsStatus"
                    :disabled="checkingOls"
                    :title="checkingOls ? 'Checking OLS Daemon status...' : 'Click to re-check OLS Daemon status'"
                    class="hidden md:flex items-center gap-2 px-3 py-1.5 rounded-lg bg-slate-800 border border-slate-700/60 hover:border-slate-600 text-xs transition-colors group cursor-pointer focus:outline-none focus:ring-1 focus:ring-sky-500/50"
                >
                    <RefreshCw v-if="checkingOls" class="w-3.5 h-3.5 text-sky-400 animate-spin" />
                    <Radio
                        v-else
                        class="w-3.5 h-3.5 transition-colors"
                        :class="olsStatus === 'online' ? 'text-emerald-400 animate-pulse' : (olsStatus === 'offline' ? 'text-rose-400' : 'text-amber-400')"
                    />

                    <span class="text-slate-400 font-medium text-[11px]">OLS:</span>

                    <span
                        class="font-semibold text-[11px] transition-colors"
                        :class="{
                            'text-emerald-400': olsStatus === 'online',
                            'text-rose-400': olsStatus === 'offline',
                            'text-amber-400': olsStatus === 'checking'
                        }"
                    >
                        {{ olsStatus.toUpperCase() }}
                    </span>

                    <RefreshCw
                        class="w-3 h-3 text-slate-500 group-hover:text-slate-300 transition-colors ml-0.5"
                        :class="{ 'animate-spin text-sky-400': checkingOls }"
                    />
                </button>

                <!-- Theme Switcher Dropdown -->
                <div class="relative" ref="themeDropdownRef">
                    <button
                        @click="themeDropdownOpen = !themeDropdownOpen"
                        title="Switch Interface Theme"
                        class="p-2 rounded-lg bg-slate-800 border border-slate-700/60 text-slate-300 hover:text-white hover:bg-slate-700 transition-all flex items-center gap-1.5 text-xs font-medium cursor-pointer"
                    >
                        <Palette class="w-4 h-4 text-sky-400" />
                        <span class="hidden sm:inline capitalize">{{ activeThemeId }}</span>
                        <ChevronDown class="w-3 h-3 text-slate-400" />
                    </button>
                    <div
                        v-if="themeDropdownOpen"
                        class="absolute right-0 mt-2 w-48 rounded-xl bg-slate-900 border border-slate-800 shadow-xl py-1 z-50 text-xs"
                    >
                        <div class="px-3 py-1.5 text-[10px] text-slate-400 font-semibold uppercase tracking-wider border-b border-slate-800">
                            Interface Theme
                        </div>
                        <button
                            v-for="t in availableThemes"
                            :key="t.id"
                            @click="switchTheme(t.id); themeDropdownOpen = false"
                            class="w-full flex items-center justify-between px-3 py-2 text-left hover:bg-slate-800 transition-colors cursor-pointer"
                            :class="activeThemeId === t.id ? 'text-sky-400 font-bold bg-sky-500/10' : 'text-slate-300'"
                        >
                            <span>{{ t.name }}</span>
                            <span v-if="activeThemeId === t.id" class="w-1.5 h-1.5 bg-sky-400 rounded-full"></span>
                        </button>
                    </div>
                </div>

                <!-- User Profile Menu -->
                <div class="relative" ref="userDropdownRef">
                    <button
                        @click="userDropdownOpen = !userDropdownOpen"
                        class="flex items-center gap-2.5 p-1.5 rounded-lg hover:bg-slate-800 transition-all focus:outline-none cursor-pointer"
                    >
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-sky-500 to-indigo-600 flex items-center justify-center text-xs font-bold text-white shadow-sm">
                            {{ user.username ? user.username.substring(0, 2).toUpperCase() : 'U' }}
                        </div>
                        <div class="hidden sm:flex flex-col text-left">
                            <span class="text-xs font-medium text-slate-200">{{ user.name || user.username }}</span>
                            <span class="text-[10px] text-slate-400 capitalize">{{ user.role || 'user' }}</span>
                        </div>
                        <ChevronDown class="w-3.5 h-3.5 text-slate-400" />
                    </button>

                    <div
                        v-if="userDropdownOpen"
                        class="absolute right-0 mt-2 w-52 rounded-xl bg-slate-900 border border-slate-800 shadow-xl py-1.5 z-50 text-sm"
                    >
                        <div class="px-4 py-2 border-b border-slate-800">
                            <p class="text-xs font-semibold text-white truncate">{{ user.username }}</p>
                            <p class="text-[11px] text-slate-400 truncate">{{ user.email }}</p>
                        </div>
                        <Link
                            href="/security/password"
                            class="flex items-center gap-2.5 px-4 py-2 text-xs text-slate-300 hover:bg-slate-800 hover:text-white transition-colors"
                        >
                            <UserCircle class="w-4 h-4 text-slate-400" />
                            <span>Account Settings</span>
                        </Link>
                        <button
                            @click="logout"
                            class="w-full flex items-center gap-2.5 px-4 py-2 text-xs text-rose-400 hover:bg-rose-500/10 text-left transition-colors cursor-pointer"
                        >
                            <LogOut class="w-4 h-4" />
                            <span>Sign Out</span>
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <div class="flex-1 flex overflow-hidden">
            <!-- Sidebar Navigation (Left Side) -->
            <aside
                :class="[
                    'fixed inset-y-0 left-0 z-30 w-64 bg-slate-900 border-r border-slate-800 transform transition-transform duration-200 ease-in-out lg:translate-x-0 lg:static flex flex-col',
                    sidebarOpen ? 'translate-x-0' : '-translate-x-full'
                ]"
            >
                <div class="flex-1 overflow-y-auto px-4 py-5 space-y-1.5 scrollbar-thin">
                    <template v-for="item in filteredNavigation" :key="item.id">
                        <!-- Direct Link without children -->
                        <div v-if="!item.children" class="pt-0.5">
                            <Link
                                :href="item.route"
                                class="flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-medium transition-all"
                                :class="isItemActive(item)
                                    ? 'bg-sky-500/10 text-sky-400 font-semibold shadow-sm'
                                    : 'text-slate-300 hover:bg-slate-800/80 hover:text-white'"
                            >
                                <div class="flex items-center gap-3">
                                    <component :is="getIcon(item.icon)" class="w-4 h-4" :class="isItemActive(item) ? 'text-sky-400' : 'text-slate-400'" />
                                    <span>{{ item.label }}</span>
                                </div>
                                <span v-if="isItemActive(item)" class="w-1.5 h-1.5 bg-sky-400 rounded-full"></span>
                            </Link>
                        </div>

                        <!-- Menu with sub-routes -->
                        <div v-else class="pt-1">
                            <button
                                @click="toggleSubmenu(item.id)"
                                class="w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-medium transition-all cursor-pointer"
                                :class="isItemActive(item) ? 'text-white bg-slate-800/50' : 'text-slate-300 hover:bg-slate-800/80 hover:text-white'"
                            >
                                <div class="flex items-center gap-3">
                                    <component :is="getIcon(item.icon)" class="w-4 h-4" :class="isItemActive(item) ? 'text-sky-400' : 'text-slate-400'" />
                                    <span>{{ item.label }}</span>
                                </div>
                                <ChevronDown
                                    class="w-3.5 h-3.5 text-slate-400 transition-transform duration-200"
                                    :class="{'rotate-180': openSubmenus[item.id]}"
                                />
                            </button>
                            <div v-if="openSubmenus[item.id]" class="mt-1 pl-7 pr-2 space-y-1 border-l border-slate-800 ml-5">
                                <template v-for="child in item.children" :key="child.id">
                                    <a
                                        v-if="child.external"
                                        :href="child.route"
                                        target="_blank"
                                        class="flex items-center justify-between px-3 py-2 rounded-lg text-xs text-slate-400 hover:text-white hover:bg-slate-800/60 transition-colors"
                                    >
                                        <span>{{ child.label }}</span>
                                        <ExternalLink class="w-3.5 h-3.5 text-slate-500" />
                                    </a>
                                    <Link
                                        v-else
                                        :href="child.route"
                                        class="block px-3 py-2 rounded-lg text-xs transition-colors"
                                        :class="isRouteActive(child.route)
                                            ? 'text-sky-400 bg-sky-500/10 font-medium'
                                            : 'text-slate-400 hover:text-white hover:bg-slate-800/60'"
                                    >
                                        {{ child.label }}
                                    </Link>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Footer Baseline in Sidebar -->
                <div class="p-4 border-t border-slate-800 text-xs text-slate-400 flex items-center justify-between">
                    <span>LiteControl v1.0.0</span>
                    <span v-if="page.props.panel?.is_mock_mode" class="px-2 py-0.5 rounded-md bg-amber-500/10 text-amber-400 border border-amber-500/20 text-[10px]">
                        MOCK
                    </span>
                    <span v-else class="text-emerald-400 font-medium text-[11px]">Active</span>
                </div>
            </aside>

            <!-- Main Viewport -->
            <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
                <div class="max-w-7xl mx-auto">
                    <!-- Flash Alerts -->
                    <div v-if="flash.success" class="mb-5 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 text-sm flex items-center gap-3">
                        <CheckCircle2 class="w-5 h-5 text-emerald-400 flex-shrink-0" />
                        <span>{{ flash.success }}</span>
                    </div>
                    <div v-if="flash.error" class="mb-5 p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-300 text-sm flex items-center gap-3">
                        <AlertCircle class="w-5 h-5 text-rose-400 flex-shrink-0" />
                        <span>{{ flash.error }}</span>
                    </div>

                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
