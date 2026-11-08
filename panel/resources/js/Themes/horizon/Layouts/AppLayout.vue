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
    CheckCircle2, AlertCircle, Palette
} from 'lucide-vue-next';

const page = usePage();
const flash = computed(() => page.props.flash || {});
const { user, isAdmin, filteredNavigation, isRouteActive, isItemActive } = useNavigation();
const { activeThemeId, availableThemes, switchTheme } = useTheme();

const mobileMenuOpen = shallowRef(false);
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

// Active dropdown state for horizontal items
const activeDropdown = shallowRef(null);
const toggleDropdown = (id) => {
    activeDropdown.value = activeDropdown.value === id ? null : id;
};

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

        <!-- Horizontal Top Navigation Header -->
        <header class="border-b border-slate-800 bg-slate-900/90 backdrop-blur-md sticky top-0 z-40">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <!-- Brand & Left Section -->
                    <div class="flex items-center gap-6">
                        <button
                            @click="mobileMenuOpen = !mobileMenuOpen"
                            class="lg:hidden p-2 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
                            aria-label="Toggle Navigation"
                        >
                            <Menu v-if="!mobileMenuOpen" class="w-5 h-5" />
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

                        <!-- Primary Desktop Horizontal Menu -->
                        <nav class="hidden lg:flex items-center gap-1 ml-4">
                            <template v-for="item in filteredNavigation" :key="item.id">
                                <!-- Leaf link -->
                                <Link
                                    v-if="!item.children"
                                    :href="item.route"
                                    class="px-3 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-2"
                                    :class="isItemActive(item)
                                        ? 'bg-sky-500/10 text-sky-400 font-semibold'
                                        : 'text-slate-300 hover:text-white hover:bg-slate-800/60'"
                                >
                                    <component :is="getIcon(item.icon)" class="w-4 h-4" />
                                    <span>{{ item.label }}</span>
                                </Link>

                                <!-- Dropdown Menu -->
                                <div v-else class="relative" @mouseenter="activeDropdown = item.id" @mouseleave="activeDropdown = null">
                                    <button
                                        @click="toggleDropdown(item.id)"
                                        class="px-3 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-1.5"
                                        :class="isItemActive(item)
                                            ? 'text-sky-400 bg-sky-500/10 font-semibold'
                                            : 'text-slate-300 hover:text-white hover:bg-slate-800/60'"
                                    >
                                        <component :is="getIcon(item.icon)" class="w-4 h-4" />
                                        <span>{{ item.label }}</span>
                                        <ChevronDown class="w-3.5 h-3.5 opacity-60 transition-transform" :class="{'rotate-180': activeDropdown === item.id}" />
                                    </button>

                                    <!-- Dropdown Flyout -->
                                    <div
                                        v-show="activeDropdown === item.id"
                                        class="absolute left-0 mt-1 w-56 rounded-xl bg-slate-900 border border-slate-800 shadow-xl py-2 z-50 backdrop-blur-lg"
                                    >
                                        <template v-for="child in item.children" :key="child.id">
                                            <a
                                                v-if="child.external"
                                                :href="child.route"
                                                target="_blank"
                                                class="flex items-center justify-between px-4 py-2 text-xs text-slate-300 hover:text-white hover:bg-slate-800/80 transition-colors"
                                            >
                                                <span>{{ child.label }}</span>
                                                <ExternalLink class="w-3.5 h-3.5 text-slate-500" />
                                            </a>
                                            <Link
                                                v-else
                                                :href="child.route"
                                                class="block px-4 py-2 text-xs transition-colors"
                                                :class="isRouteActive(child.route)
                                                    ? 'text-sky-400 bg-sky-500/10 font-medium'
                                                    : 'text-slate-300 hover:text-white hover:bg-slate-800/80'"
                                            >
                                                {{ child.label }}
                                            </Link>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </nav>
                    </div>

                    <!-- Right Section: Theme Selector & User Profile -->
                    <div class="flex items-center gap-3">
                        <!-- Theme Switcher Dropdown -->
                        <div class="relative" ref="themeDropdownRef">
                            <button
                                @click="themeDropdownOpen = !themeDropdownOpen"
                                title="Switch Interface Theme"
                                class="p-2 rounded-lg bg-slate-800 border border-slate-700/60 text-slate-300 hover:text-white hover:bg-slate-700 transition-all flex items-center gap-1.5 text-xs font-medium"
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
                                    class="w-full flex items-center justify-between px-3 py-2 text-left hover:bg-slate-800 transition-colors"
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
                                class="flex items-center gap-2.5 p-1.5 rounded-lg hover:bg-slate-800 transition-all focus:outline-none"
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
                                    class="w-full flex items-center gap-2.5 px-4 py-2 text-xs text-rose-400 hover:bg-rose-500/10 text-left transition-colors"
                                >
                                    <LogOut class="w-4 h-4" />
                                    <span>Sign Out</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mobile Drawer -->
            <div v-if="mobileMenuOpen" class="lg:hidden border-t border-slate-800 bg-slate-900 px-4 pt-3 pb-5 space-y-2">
                <template v-for="item in filteredNavigation" :key="item.id">
                    <Link
                        v-if="!item.children"
                        :href="item.route"
                        class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800"
                        :class="isItemActive(item) ? 'bg-sky-500/10 text-sky-400 font-semibold' : ''"
                    >
                        <component :is="getIcon(item.icon)" class="w-4 h-4" />
                        <span>{{ item.label }}</span>
                    </Link>
                    <div v-else class="space-y-1">
                        <div class="px-3 py-1.5 text-xs font-semibold uppercase text-slate-400 tracking-wider flex items-center gap-2">
                            <component :is="getIcon(item.icon)" class="w-3.5 h-3.5" />
                            <span>{{ item.label }}</span>
                        </div>
                        <div class="pl-6 space-y-1">
                            <template v-for="child in item.children" :key="child.id">
                                <Link
                                    :href="child.route"
                                    class="block px-3 py-1.5 rounded-md text-xs text-slate-400 hover:text-white hover:bg-slate-800"
                                    :class="isRouteActive(child.route) ? 'text-sky-400 font-medium' : ''"
                                >
                                    {{ child.label }}
                                </Link>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </header>

        <!-- Main Viewport -->
        <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
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
        </main>
    </div>
</template>
