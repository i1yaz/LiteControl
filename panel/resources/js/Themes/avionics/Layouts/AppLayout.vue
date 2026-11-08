<script setup>
import { shallowRef, computed, useTemplateRef } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { useClickOutside } from '@/Composables/useClickOutside';
import { useNavigation } from '@/Composables/useNavigation';
import { useTheme } from '@/Composables/useTheme';
import {
    LayoutDashboard, Users, Globe, Database, FolderTree,
    FileCode, Sliders, Server, Shield, Mail, Layers,
    UserCircle, LogOut, ChevronDown, Menu, X, ExternalLink,
    CheckCircle2, AlertCircle, Cpu, Radio, Palette
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
    <div class="min-h-screen bg-[#090b0e] text-[#d6dce7] flex flex-col font-sans selection:bg-[#ff6b35] selection:text-black">
        <!-- Impersonation Notice Bar -->
        <div v-if="page.props.auth?.is_impersonating" class="bg-[#ff6b35]/15 border-b border-[#ff6b35]/30 px-5 py-2 text-xs flex items-center justify-between text-[#ffab85]">
            <div class="flex items-center gap-2.5 font-mono">
                <AlertCircle class="w-4 h-4 text-[#ff6b35]" />
                <span>ACTIVE_IMPERSONATION // TARGET: <strong class="text-white underline">{{ user.username }}</strong></span>
            </div>
            <form @submit.prevent="router.post('/impersonation/revert')">
                <button type="submit" class="px-3 py-1 rounded bg-[#ff6b35] hover:bg-[#ff8252] text-black text-xs font-mono font-bold uppercase tracking-wider transition-colors">
                    Revert Context
                </button>
            </form>
        </div>

        <!-- Top Flight Instrumentation Header -->
        <header class="h-16 border-b border-[#1c222c] bg-[#0d1015]/95 backdrop-blur-md sticky top-0 z-40 flex items-center justify-between px-5 sm:px-7">
            <div class="flex items-center gap-5">
                <button
                    @click="sidebarOpen = !sidebarOpen"
                    class="lg:hidden p-2 rounded border border-[#232a36] text-[#7d8b9e] hover:text-white hover:bg-[#151921] transition-all"
                    aria-label="Toggle Navigation"
                >
                    <Menu v-if="!sidebarOpen" class="w-4 h-4" />
                    <X v-else class="w-4 h-4" />
                </button>

                <!-- Brand Architecture -->
                <Link href="/dashboard" class="flex items-center gap-3 group">
                    <div class="h-9 w-9 rounded-sm bg-[#161a23] border border-[#262f3e] flex items-center justify-center text-[#ff6b35] group-hover:border-[#ff6b35]/50 transition-colors">
                        <Cpu class="w-4 h-4 transition-transform group-hover:scale-110" />
                    </div>
                    <div class="flex flex-col">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-black tracking-tight text-white font-mono uppercase">
                                Lite<span class="text-[#ff6b35]">Control</span>
                            </span>
                            <span v-if="isAdmin" class="px-1.5 py-0.2 rounded-xs text-[9px] font-mono font-bold bg-[#ff6b35]/15 text-[#ff8e62] border border-[#ff6b35]/30">
                                ROOT:WHM
                            </span>
                        </div>
                        <span class="text-[9px] font-mono text-[#586477] uppercase tracking-widest">
                            LSWS · Avionics Matrix
                        </span>
                    </div>
                </Link>
            </div>

            <!-- Global Status Bar & Profile -->
            <div class="flex items-center gap-3 sm:gap-4">
                <!-- Daemon Heartbeat -->
                <div class="hidden md:flex items-center gap-2.5 px-3 py-1 rounded-sm bg-[#11161d] border border-[#1f2633] text-xs font-mono">
                    <Radio class="w-3.5 h-3.5 text-emerald-400 animate-pulse" />
                    <span class="text-[#8e9cae]">OLS DAEMON:</span>
                    <span class="text-emerald-400 font-bold">ONLINE</span>
                </div>

                <!-- Theme Switcher Dropdown -->
                <div class="relative" ref="themeDropdownRef">
                    <button
                        @click="themeDropdownOpen = !themeDropdownOpen"
                        title="Switch Interface Theme"
                        class="p-2 rounded-sm bg-[#11161d] border border-[#1f2633] hover:border-[#2a3445] text-[#98a6b8] hover:text-white transition-all flex items-center gap-1.5 text-xs font-mono"
                    >
                        <Palette class="w-3.5 h-3.5 text-[#ff6b35]" />
                        <span class="hidden sm:inline capitalize">{{ activeThemeId }}</span>
                        <ChevronDown class="w-3 h-3 text-[#586477]" />
                    </button>
                    <div
                        v-if="themeDropdownOpen"
                        class="absolute right-0 mt-2 w-48 rounded-sm bg-[#0e1217] border border-[#242c3b] shadow-2xl py-1 z-50 font-mono text-xs"
                    >
                        <div class="px-3 py-1.5 text-[10px] text-[#586477] uppercase tracking-wider border-b border-[#1b212b]">
                            Theme Engine
                        </div>
                        <button
                            v-for="t in availableThemes"
                            :key="t.id"
                            @click="switchTheme(t.id); themeDropdownOpen = false"
                            class="w-full flex items-center justify-between px-3 py-2 text-left hover:bg-[#161c24] transition-colors"
                            :class="activeThemeId === t.id ? 'text-[#ff6b35] font-bold bg-[#141920]' : 'text-[#a0adbf]'"
                        >
                            <span>{{ t.name }}</span>
                            <span v-if="activeThemeId === t.id" class="w-1.5 h-1.5 bg-[#ff6b35] rounded-full"></span>
                        </button>
                    </div>
                </div>

                <!-- Account Instrument -->
                <div class="relative" ref="userDropdownRef">
                    <button
                        @click="userDropdownOpen = !userDropdownOpen"
                        class="flex items-center gap-2.5 p-1.5 rounded-sm hover:bg-[#151a22] border border-transparent hover:border-[#222a38] transition-all focus:outline-none"
                    >
                        <div class="w-7 h-7 rounded-sm bg-[#1b212c] border border-[#2a3445] flex items-center justify-center text-xs font-mono font-bold text-[#ff9b71]">
                            {{ user.username ? user.username.substring(0, 2).toUpperCase() : 'RT' }}
                        </div>
                        <div class="hidden sm:flex flex-col text-left">
                            <span class="text-xs font-medium text-[#f1f5f9] leading-tight">{{ user.name || user.username }}</span>
                            <span class="text-[9px] font-mono text-[#586477] uppercase">{{ user.role || 'user' }}</span>
                        </div>
                        <ChevronDown class="w-3.5 h-3.5 text-[#586477]" />
                    </button>

                    <!-- Tactical Dropdown -->
                    <div
                        v-if="userDropdownOpen"
                        class="absolute right-0 mt-2 w-52 rounded-sm bg-[#0e1217] border border-[#242c3b] shadow-2xl py-1.5 z-50 font-mono"
                    >
                        <div class="px-3 py-2 border-b border-[#1b212b]">
                            <p class="text-xs font-semibold text-white truncate">{{ user.username }}</p>
                            <p class="text-[10px] text-[#586477] truncate">{{ user.email }}</p>
                        </div>
                        <Link
                            href="/security/password"
                            class="flex items-center gap-2.5 px-3 py-2 text-xs text-[#a0adbf] hover:bg-[#161c24] hover:text-white transition-colors"
                        >
                            <UserCircle class="w-3.5 h-3.5 text-[#586477]" />
                            <span>Security Profile</span>
                        </Link>
                        <button
                            @click="logout"
                            class="w-full flex items-center gap-2.5 px-3 py-2 text-xs text-[#ff5a5a] hover:bg-[#ff5a5a]/10 hover:text-[#ff7878] text-left transition-colors"
                        >
                            <LogOut class="w-3.5 h-3.5" />
                            <span>Terminate Session</span>
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <div class="flex-1 flex overflow-hidden">
            <!-- Sidebar Navigation -->
            <aside
                :class="[
                    'fixed inset-y-0 left-0 z-30 w-64 bg-[#0a0d12] border-r border-[#191f2a] transform transition-transform duration-200 ease-in-out lg:translate-x-0 lg:static flex flex-col',
                    sidebarOpen ? 'translate-x-0' : '-translate-x-full'
                ]"
            >
                <div class="flex-1 overflow-y-auto px-3.5 py-4 space-y-1 scrollbar-thin">
                    <template v-for="item in filteredNavigation" :key="item.id">
                        <!-- Direct Link without children -->
                        <div v-if="!item.children" class="pt-0.5">
                            <Link
                                :href="item.route"
                                class="flex items-center justify-between px-3 py-2 rounded-sm text-xs font-mono font-medium transition-colors"
                                :class="isItemActive(item)
                                    ? 'bg-[#ff6b35]/10 text-[#ff8e62] border border-[#ff6b35]/25'
                                    : 'text-[#98a6b8] hover:bg-[#13171e] hover:text-white border border-transparent'"
                            >
                                <div class="flex items-center gap-2.5">
                                    <component :is="getIcon(item.icon)" class="w-3.5 h-3.5" :class="isItemActive(item) ? 'text-[#ff6b35]' : 'text-[#6c7d93]'" />
                                    <span>{{ item.label }}</span>
                                </div>
                                <span v-if="isItemActive(item)" class="w-1.5 h-1.5 bg-[#ff6b35]"></span>
                            </Link>
                        </div>

                        <!-- Menu with sub-routes -->
                        <div v-else class="pt-1">
                            <button
                                @click="toggleSubmenu(item.id)"
                                class="w-full flex items-center justify-between px-3 py-2 rounded-sm text-xs font-mono transition-colors"
                                :class="isItemActive(item) ? 'text-white' : 'text-[#98a6b8] hover:bg-[#13171e] hover:text-white'"
                            >
                                <div class="flex items-center gap-2.5">
                                    <component :is="getIcon(item.icon)" class="w-3.5 h-3.5" :class="isItemActive(item) ? 'text-[#ff6b35]' : 'text-[#6c7d93]'" />
                                    <span>{{ item.label }}</span>
                                </div>
                                <ChevronDown
                                    class="w-3 h-3 text-[#495466] transition-transform"
                                    :class="{'rotate-180': openSubmenus[item.id]}"
                                />
                            </button>
                            <div v-if="openSubmenus[item.id]" class="mt-1 pl-7 pr-2 space-y-0.5 border-l border-[#191f2a] ml-4">
                                <template v-for="child in item.children" :key="child.id">
                                    <a
                                        v-if="child.external"
                                        :href="child.route"
                                        target="_blank"
                                        class="flex items-center justify-between px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]"
                                    >
                                        <span>{{ child.label }}</span>
                                        <ExternalLink class="w-3 h-3 text-[#424c5c]" />
                                    </a>
                                    <Link
                                        v-else
                                        :href="child.route"
                                        class="block px-2.5 py-1.5 rounded-sm text-xs font-mono transition-colors"
                                        :class="isRouteActive(child.route)
                                            ? 'text-[#ff8e62] bg-[#ff6b35]/10 font-bold'
                                            : 'text-[#6c7d93] hover:text-white hover:bg-[#13171e]'"
                                    >
                                        {{ child.label }}
                                    </Link>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Footer Baseline -->
                <div class="p-3 border-t border-[#181e28] text-[10px] font-mono text-[#4e5a6c] flex items-center justify-between">
                    <span>SYS // v1.0.0</span>
                    <span v-if="page.props.panel?.is_mock_mode" class="px-1.5 py-0.5 bg-[#ff6b35]/15 text-[#ff8e62] border border-[#ff6b35]/30">
                        EMULATOR
                    </span>
                    <span v-else class="text-emerald-500 font-semibold">STANDALONE</span>
                </div>
            </aside>

            <!-- Main Telemetry Viewport -->
            <main class="flex-1 overflow-y-auto p-4 sm:p-7">
                <!-- Notifications -->
                <div v-if="flash.success" class="mb-5 p-3.5 rounded-sm bg-emerald-950/40 border border-emerald-500/30 text-emerald-300 font-mono text-xs flex items-center gap-3">
                    <CheckCircle2 class="w-4 h-4 text-emerald-400 flex-shrink-0" />
                    <span>{{ flash.success }}</span>
                </div>
                <div v-if="flash.error" class="mb-5 p-3.5 rounded-sm bg-rose-950/40 border border-rose-500/30 text-rose-300 font-mono text-xs flex items-center gap-3">
                    <AlertCircle class="w-4 h-4 text-rose-400 flex-shrink-0" />
                    <span>{{ flash.error }}</span>
                </div>

                <slot />
            </main>
        </div>
    </div>
</template>
