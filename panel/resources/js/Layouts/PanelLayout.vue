<script setup>
import { ref, computed } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import {
    LayoutDashboard,
    Users,
    Globe,
    Database,
    FolderTree,
    FileCode,
    Sliders,
    Server,
    Shield,
    Mail,
    UserCircle,
    LogOut,
    ChevronDown,
    Menu,
    X,
    ExternalLink,
    Bell,
    CheckCircle2,
    AlertCircle,
    Activity
} from 'lucide-vue-next';

const page = usePage();
const user = computed(() => page.props.auth?.user || {});
const isAdmin = computed(() => user.value?.role === 'admin');
const flash = computed(() => page.props.flash || {});

const sidebarOpen = ref(false);
const userDropdownOpen = ref(false);

const openSubmenus = ref({
    users: false,
    domains: false,
    database: false,
    files: false,
    php: false,
    configuration: false,
    server: false,
    security: false,
    email: false,
});

const toggleSubmenu = (menu) => {
    openSubmenus.value[menu] = !openSubmenus.value[menu];
};

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <div class="min-h-screen bg-slate-950 text-slate-100 flex flex-col antialiased">
        <!-- Impersonation Banner -->
        <div v-if="page.props.auth?.is_impersonating" class="bg-amber-500/20 border-b border-amber-500/30 px-4 py-2 text-xs flex items-center justify-between text-amber-200">
            <div class="flex items-center gap-2">
                <AlertCircle class="w-4 h-4 text-amber-400" />
                <span>You are currently impersonating <strong>{{ user.username }}</strong>. Actions taken will reflect on their account.</span>
            </div>
            <form @submit.prevent="router.post('/impersonation/revert')">
                <button type="submit" class="px-2.5 py-1 rounded bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold transition-colors">
                    Revert to Admin
                </button>
            </form>
        </div>

        <!-- Top Navigation Bar -->
        <header class="h-16 border-b border-slate-800/80 bg-slate-900/90 backdrop-blur-md sticky top-0 z-40 flex items-center justify-between px-4 sm:px-6">
            <div class="flex items-center gap-3">
                <button
                    @click="sidebarOpen = !sidebarOpen"
                    class="lg:hidden p-2 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 focus:outline-none"
                >
                    <Menu v-if="!sidebarOpen" class="w-5 h-5" />
                    <X v-else class="w-5 h-5" />
                </button>

                <!-- Brand Logo -->
                <Link href="/dashboard" class="flex items-center gap-2.5">
                    <div class="h-9 w-9 rounded-lg bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center shadow-md shadow-indigo-500/20 text-white">
                        <Server class="w-5 h-5" />
                    </div>
                    <div class="flex flex-col">
                        <span class="text-base font-bold tracking-tight text-white flex items-center gap-1">
                            Lite<span class="text-indigo-400">Control</span>
                            <span v-if="isAdmin" class="ml-1.5 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">
                                WHM
                            </span>
                        </span>
                        <span class="text-[10px] font-medium text-slate-400 uppercase tracking-wider">OpenLiteSpeed</span>
                    </div>
                </Link>
            </div>

            <!-- Header Right Section -->
            <div class="flex items-center gap-4">
                <!-- Status Pill -->
                <div class="hidden sm:flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-medium">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span>OLS Active</span>
                </div>

                <!-- Account Dropdown (Matches nav-account.png) -->
                <div class="relative">
                    <button
                        @click="userDropdownOpen = !userDropdownOpen"
                        class="flex items-center gap-2.5 p-1.5 rounded-lg hover:bg-slate-800/70 text-slate-200 transition-colors focus:outline-none"
                    >
                        <div class="w-8 h-8 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center text-xs font-bold text-indigo-300">
                            {{ user.username ? user.username.substring(0, 2).toUpperCase() : 'LC' }}
                        </div>
                        <div class="hidden md:flex flex-col text-left">
                            <span class="text-xs font-semibold leading-tight text-slate-200">{{ user.name || user.username }}</span>
                            <span class="text-[10px] text-slate-400 capitalize">{{ user.role || 'user' }}</span>
                        </div>
                        <ChevronDown class="w-3.5 h-3.5 text-slate-400" />
                    </button>

                    <!-- Account Menu -->
                    <div
                        v-if="userDropdownOpen"
                        @click.outside="userDropdownOpen = false"
                        class="absolute right-0 mt-2 w-48 rounded-xl bg-slate-900 border border-slate-800 shadow-xl py-1 z-50 ring-1 ring-black/20"
                    >
                        <div class="px-4 py-2 border-b border-slate-800">
                            <p class="text-xs font-semibold text-white">{{ user.username }}</p>
                            <p class="text-[11px] text-slate-400 truncate">{{ user.email }}</p>
                        </div>
                        <Link
                            href="/dashboard"
                            class="flex items-center gap-2 px-4 py-2 text-xs text-slate-300 hover:bg-slate-800 hover:text-white"
                        >
                            <UserCircle class="w-4 h-4 text-slate-400" />
                            <span>Profile</span>
                        </Link>
                        <button
                            @click="logout"
                            class="w-full flex items-center gap-2 px-4 py-2 text-xs text-rose-400 hover:bg-rose-500/10 hover:text-rose-300 text-left transition-colors"
                        >
                            <LogOut class="w-4 h-4" />
                            <span>Logout</span>
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <div class="flex-1 flex overflow-hidden">
            <!-- Sidebar -->
            <aside
                :class="[
                    'fixed inset-y-0 left-0 z-30 w-64 bg-slate-900/95 border-r border-slate-800/80 transform transition-transform duration-200 ease-in-out lg:translate-x-0 lg:static flex flex-col',
                    sidebarOpen ? 'translate-x-0' : '-translate-x-full'
                ]"
            >
                <div class="flex-1 overflow-y-auto px-3 py-4 space-y-1 scrollbar-thin scrollbar-thumb-slate-800">
                    <!-- Dashboard -->
                    <Link
                        href="/dashboard"
                        class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors bg-indigo-600/15 text-indigo-400 border border-indigo-500/20"
                    >
                        <LayoutDashboard class="w-4 h-4" />
                        <span>Dashboard</span>
                    </Link>

                    <!-- Users Section (Admin Only) -->
                    <div v-if="isAdmin" class="pt-2">
                        <button
                            @click="toggleSubmenu('users')"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium text-slate-300 hover:bg-slate-800/60 hover:text-white transition-colors"
                        >
                            <div class="flex items-center gap-3">
                                <Users class="w-4 h-4 text-indigo-400" />
                                <span>Users</span>
                            </div>
                            <ChevronDown class="w-3.5 h-3.5 text-slate-400 transition-transform" :class="{'rotate-180': openSubmenus.users}" />
                        </button>
                        <div v-if="openSubmenus.users" class="mt-1 pl-9 pr-2 space-y-1">
                            <Link href="/users" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">List Users</Link>
                            <Link href="/users/create" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Add User</Link>
                            <Link href="/packages" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Packages</Link>
                        </div>
                    </div>

                    <!-- Domains Section -->
                    <div class="pt-1">
                        <button
                            @click="toggleSubmenu('domains')"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium text-slate-300 hover:bg-slate-800/60 hover:text-white transition-colors"
                        >
                            <div class="flex items-center gap-3">
                                <Globe class="w-4 h-4 text-emerald-400" />
                                <span>Domains</span>
                            </div>
                            <ChevronDown class="w-3.5 h-3.5 text-slate-400 transition-transform" :class="{'rotate-180': openSubmenus.domains}" />
                        </button>
                        <div v-if="openSubmenus.domains" class="mt-1 pl-9 pr-2 space-y-1">
                            <Link href="/domains" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Domains</Link>
                            <Link href="/domains" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Add Domain</Link>
                            <Link href="/subdomains" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Sub Domains</Link>
                            <Link href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">DNS</Link>
                        </div>
                    </div>

                    <!-- Database Section -->
                    <div class="pt-1">
                        <button
                            @click="toggleSubmenu('database')"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium text-slate-300 hover:bg-slate-800/60 hover:text-white transition-colors"
                        >
                            <div class="flex items-center gap-3">
                                <Database class="w-4 h-4 text-amber-400" />
                                <span>Database</span>
                            </div>
                            <ChevronDown class="w-3.5 h-3.5 text-slate-400 transition-transform" :class="{'rotate-180': openSubmenus.database}" />
                        </button>
                        <div v-if="openSubmenus.database" class="mt-1 pl-9 pr-2 space-y-1">
                            <Link href="/databases" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">MySQL Databases</Link>
                            <a href="/phpmyadmin/" target="_blank" class="flex items-center justify-between px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">
                                <span>phpMyAdmin</span>
                                <ExternalLink class="w-3 h-3 text-slate-500" />
                            </a>
                        </div>
                    </div>

                    <!-- Files Section -->
                    <div class="pt-1">
                        <button
                            @click="toggleSubmenu('files')"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium text-slate-300 hover:bg-slate-800/60 hover:text-white transition-colors"
                        >
                            <div class="flex items-center gap-3">
                                <FolderTree class="w-4 h-4 text-cyan-400" />
                                <span>Files</span>
                            </div>
                            <ChevronDown class="w-3.5 h-3.5 text-slate-400 transition-transform" :class="{'rotate-180': openSubmenus.files}" />
                        </button>
                        <div v-if="openSubmenus.files" class="mt-1 pl-9 pr-2 space-y-1">
                            <Link href="/files" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">File Manager</Link>
                            <Link href="/backups" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Backup & Restore</Link>
                            <Link href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">FTP</Link>
                        </div>
                    </div>

                    <!-- PHP Section (Matches nav-php.png) -->
                    <div class="pt-1">
                        <button
                            @click="toggleSubmenu('php')"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium text-slate-300 hover:bg-slate-800/60 hover:text-white transition-colors"
                        >
                            <div class="flex items-center gap-3">
                                <FileCode class="w-4 h-4 text-purple-400" />
                                <span>PHP</span>
                            </div>
                            <ChevronDown class="w-3.5 h-3.5 text-slate-400 transition-transform" :class="{'rotate-180': openSubmenus.php}" />
                        </button>
                        <div v-if="openSubmenus.php" class="mt-1 pl-9 pr-2 space-y-1">
                            <Link href="/php/ini" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">MultiPHP INI Editor</Link>
                            <Link href="/php/manager" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">MultiPHP Manager</Link>
                            <Link href="/php/extensions" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">PHP Extension</Link>
                            <Link v-if="isAdmin" href="/php/versions" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">PHP Version</Link>
                            <Link href="/php/modules" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">PHP Modules</Link>
                        </div>
                    </div>

                    <!-- Configuration Section (Matches nav-configuration.png, Admin Only) -->
                    <div v-if="isAdmin" class="pt-1">
                        <button
                            @click="toggleSubmenu('configuration')"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium text-slate-300 hover:bg-slate-800/60 hover:text-white transition-colors"
                        >
                            <div class="flex items-center gap-3">
                                <Sliders class="w-4 h-4 text-sky-400" />
                                <span>Configuration</span>
                            </div>
                            <ChevronDown class="w-3.5 h-3.5 text-slate-400 transition-transform" :class="{'rotate-180': openSubmenus.configuration}" />
                        </button>
                        <div v-if="openSubmenus.configuration" class="mt-1 pl-9 pr-2 space-y-1">
                            <Link href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Settings</Link>
                            <Link href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">LiteSpeed Tuning</Link>
                            <Link href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">PHP Limit</Link>
                            <Link href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Error Logs</Link>
                        </div>
                    </div>

                    <!-- Server Section (Matches nav-server.png, Admin Only) -->
                    <div v-if="isAdmin" class="pt-1">
                        <button
                            @click="toggleSubmenu('server')"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium text-slate-300 hover:bg-slate-800/60 hover:text-white transition-colors"
                        >
                            <div class="flex items-center gap-3">
                                <Server class="w-4 h-4 text-blue-400" />
                                <span>Server</span>
                            </div>
                            <ChevronDown class="w-3.5 h-3.5 text-slate-400 transition-transform" :class="{'rotate-180': openSubmenus.server}" />
                        </button>
                        <div v-if="openSubmenus.server" class="mt-1 pl-9 pr-2 space-y-1">
                            <Link href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Services</Link>
                            <Link href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Process Manager</Link>
                            <Link href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Time Zone</Link>
                            <Link href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Restart</Link>
                            <Link href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Change Hostname</Link>
                            <Link href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">System Statistics</Link>
                        </div>
                    </div>

                    <!-- Security Section (Matches nav-security.png) -->
                    <div class="pt-1">
                        <button
                            @click="toggleSubmenu('security')"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium text-slate-300 hover:bg-slate-800/60 hover:text-white transition-colors"
                        >
                            <div class="flex items-center gap-3">
                                <Shield class="w-4 h-4 text-rose-400" />
                                <span>Security</span>
                            </div>
                            <ChevronDown class="w-3.5 h-3.5 text-slate-400 transition-transform" :class="{'rotate-180': openSubmenus.security}" />
                        </button>
                        <div v-if="openSubmenus.security" class="mt-1 pl-9 pr-2 space-y-1">
                            <Link href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">SSL</Link>
                            <Link v-if="isAdmin" href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">CSF (Firewall)</Link>
                            <Link v-if="isAdmin" href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">UFW (Firewall)</Link>
                            <Link v-if="isAdmin" href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Port (Firewall)</Link>
                            <Link v-if="isAdmin" href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">ModSecurity</Link>
                            <Link v-if="isAdmin" href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Panel Port</Link>
                            <Link v-if="isAdmin" href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">SSH Port</Link>
                            <Link v-if="isAdmin" href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Panel SSL</Link>
                            <Link v-if="isAdmin" href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">ImunifyAV Antivirus</Link>
                            <Link href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Password</Link>
                            <Link href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Two-Factor</Link>
                        </div>
                    </div>

                    <!-- Email Section -->
                    <div class="pt-1">
                        <button
                            @click="toggleSubmenu('email')"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-sm font-medium text-slate-300 hover:bg-slate-800/60 hover:text-white transition-colors"
                        >
                            <div class="flex items-center gap-3">
                                <Mail class="w-4 h-4 text-violet-400" />
                                <span>Email</span>
                            </div>
                            <ChevronDown class="w-3.5 h-3.5 text-slate-400 transition-transform" :class="{'rotate-180': openSubmenus.email}" />
                        </button>
                        <div v-if="openSubmenus.email" class="mt-1 pl-9 pr-2 space-y-1">
                            <Link href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Email Accounts</Link>
                            <Link href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">Forwarders</Link>
                            <Link href="/dashboard" class="block px-2 py-1.5 rounded text-xs text-slate-400 hover:text-white hover:bg-slate-800/40">DKIM</Link>
                        </div>
                    </div>
                </div>

                <!-- Footer version / mock indicator -->
                <div class="p-3 border-t border-slate-800/80 text-[11px] text-slate-500 flex items-center justify-between">
                    <span>LiteControl v1.0.0</span>
                    <span v-if="page.props.panel?.is_mock_mode" class="px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-400 font-mono text-[9px] border border-amber-500/20">
                        DEV MOCK
                    </span>
                </div>
            </aside>

            <!-- Main Content Area -->
            <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
                <!-- Flash Notification Banner -->
                <div v-if="flash.success" class="mb-6 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 flex items-center gap-3 text-sm">
                    <CheckCircle2 class="w-5 h-5 text-emerald-400 flex-shrink-0" />
                    <span>{{ flash.success }}</span>
                </div>
                <div v-if="flash.error" class="mb-6 p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-300 flex items-center gap-3 text-sm">
                    <AlertCircle class="w-5 h-5 text-rose-400 flex-shrink-0" />
                    <span>{{ flash.error }}</span>
                </div>

                <slot />
            </main>
        </div>
    </div>
</template>

