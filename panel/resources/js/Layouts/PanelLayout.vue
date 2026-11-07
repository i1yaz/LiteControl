<script setup>
import { shallowRef, reactive, computed, useTemplateRef } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { useClickOutside } from '@/Composables/useClickOutside';
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
    CheckCircle2,
    AlertCircle,
    Layers,
    Cpu,
    Radio
} from 'lucide-vue-next';

const page = usePage();
const user = computed(() => page.props.auth?.user || {});
const isAdmin = computed(() => user.value?.role === 'admin');
const flash = computed(() => page.props.flash || {});

const sidebarOpen = shallowRef(false);
const userDropdownOpen = shallowRef(false);
const userDropdownRef = useTemplateRef('userDropdownRef');

useClickOutside(userDropdownRef, () => {
    userDropdownOpen.value = false;
});

const openSubmenus = reactive({
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
    openSubmenus[menu] = !openSubmenus[menu];
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
                            LSWS · Kernel Engine
                        </span>
                    </div>
                </Link>
            </div>

            <!-- Global Status Bar & Profile -->
            <div class="flex items-center gap-4">
                <!-- Daemon Heartbeat -->
                <div class="hidden md:flex items-center gap-2.5 px-3 py-1 rounded-sm bg-[#11161d] border border-[#1f2633] text-xs font-mono">
                    <Radio class="w-3.5 h-3.5 text-emerald-400 animate-pulse" />
                    <span class="text-[#8e9cae]">OLS DAEMON:</span>
                    <span class="text-emerald-400 font-bold">ONLINE</span>
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
                            href="/dashboard"
                            class="flex items-center gap-2.5 px-3 py-2 text-xs text-[#a0adbf] hover:bg-[#161c24] hover:text-white transition-colors"
                        >
                            <UserCircle class="w-3.5 h-3.5 text-[#586477]" />
                            <span>Identity Config</span>
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
                    <!-- Dashboard Entry -->
                    <Link
                        href="/dashboard"
                        class="flex items-center justify-between px-3 py-2 rounded-sm text-xs font-mono font-medium transition-colors bg-[#ff6b35]/10 text-[#ff8e62] border border-[#ff6b35]/25"
                    >
                        <div class="flex items-center gap-2.5">
                            <LayoutDashboard class="w-3.5 h-3.5" />
                            <span>Dashboard Matrix</span>
                        </div>
                        <span class="w-1.5 h-1.5 bg-[#ff6b35]"></span>
                    </Link>

                    <!-- Administrative Sections -->
                    <div v-if="isAdmin" class="pt-3">
                        <span class="px-3 text-[9px] font-mono uppercase tracking-widest text-[#424c5c] block mb-1">Fleet Orchestration</span>
                        <button
                            @click="toggleSubmenu('users')"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-sm text-xs font-mono text-[#98a6b8] hover:bg-[#13171e] hover:text-white transition-colors"
                        >
                            <div class="flex items-center gap-2.5">
                                <Users class="w-3.5 h-3.5 text-[#6c7d93]" />
                                <span>Users & Quotas</span>
                            </div>
                            <ChevronDown class="w-3 h-3 text-[#495466] transition-transform" :class="{'rotate-180': openSubmenus.users}" />
                        </button>
                        <div v-if="openSubmenus.users" class="mt-1 pl-7 pr-2 space-y-0.5 border-l border-[#191f2a] ml-4">
                            <Link href="/users" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">User Directory</Link>
                            <Link href="/users/create" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">Provision User</Link>
                            <Link href="/packages" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">Quota Profiles</Link>
                        </div>
                    </div>

                    <!-- Domain Services -->
                    <div class="pt-2">
                        <button
                            @click="toggleSubmenu('domains')"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-sm text-xs font-mono text-[#98a6b8] hover:bg-[#13171e] hover:text-white transition-colors"
                        >
                            <div class="flex items-center gap-2.5">
                                <Globe class="w-3.5 h-3.5 text-[#6c7d93]" />
                                <span>Virtual Hosts</span>
                            </div>
                            <ChevronDown class="w-3 h-3 text-[#495466] transition-transform" :class="{'rotate-180': openSubmenus.domains}" />
                        </button>
                        <div v-if="openSubmenus.domains" class="mt-1 pl-7 pr-2 space-y-0.5 border-l border-[#191f2a] ml-4">
                            <Link href="/domains" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">VHost Records</Link>
                            <Link href="/subdomains" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">Subdomains</Link>
                        </div>
                    </div>

                    <!-- Database -->
                    <div class="pt-1">
                        <button
                            @click="toggleSubmenu('database')"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-sm text-xs font-mono text-[#98a6b8] hover:bg-[#13171e] hover:text-white transition-colors"
                        >
                            <div class="flex items-center gap-2.5">
                                <Database class="w-3.5 h-3.5 text-[#6c7d93]" />
                                <span>Databases</span>
                            </div>
                            <ChevronDown class="w-3 h-3 text-[#495466] transition-transform" :class="{'rotate-180': openSubmenus.database}" />
                        </button>
                        <div v-if="openSubmenus.database" class="mt-1 pl-7 pr-2 space-y-0.5 border-l border-[#191f2a] ml-4">
                            <Link href="/databases" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">MySQL Engines</Link>
                            <a href="/phpmyadmin/" target="_blank" class="flex items-center justify-between px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">
                                <span>phpMyAdmin</span>
                                <ExternalLink class="w-3 h-3 text-[#424c5c]" />
                            </a>
                        </div>
                    </div>

                    <!-- Files & Storage -->
                    <div class="pt-1">
                        <button
                            @click="toggleSubmenu('files')"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-sm text-xs font-mono text-[#98a6b8] hover:bg-[#13171e] hover:text-white transition-colors"
                        >
                            <div class="flex items-center gap-2.5">
                                <FolderTree class="w-3.5 h-3.5 text-[#6c7d93]" />
                                <span>File System</span>
                            </div>
                            <ChevronDown class="w-3 h-3 text-[#495466] transition-transform" :class="{'rotate-180': openSubmenus.files}" />
                        </button>
                        <div v-if="openSubmenus.files" class="mt-1 pl-7 pr-2 space-y-0.5 border-l border-[#191f2a] ml-4">
                            <Link href="/files" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">File Explorer</Link>
                            <Link href="/backups" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">Snapshots & Backup</Link>
                        </div>
                    </div>

                    <!-- PHP Runtime -->
                    <div class="pt-1">
                        <button
                            @click="toggleSubmenu('php')"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-sm text-xs font-mono text-[#98a6b8] hover:bg-[#13171e] hover:text-white transition-colors"
                        >
                            <div class="flex items-center gap-2.5">
                                <FileCode class="w-3.5 h-3.5 text-[#6c7d93]" />
                                <span>PHP Processors</span>
                            </div>
                            <ChevronDown class="w-3 h-3 text-[#495466] transition-transform" :class="{'rotate-180': openSubmenus.php}" />
                        </button>
                        <div v-if="openSubmenus.php" class="mt-1 pl-7 pr-2 space-y-0.5 border-l border-[#191f2a] ml-4">
                            <Link href="/php/manager" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">MultiPHP Manager</Link>
                            <Link href="/php/ini" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">INI Directives</Link>
                            <Link href="/php/extensions" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">Extensions</Link>
                            <Link v-if="isAdmin" href="/php/versions" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">PHP Version</Link>
                            <Link href="/php/modules" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">PHP Modules</Link>
                        </div>
                    </div>

                    <!-- Configuration Section -->
                    <div v-if="isAdmin" class="pt-1">
                        <button
                            @click="toggleSubmenu('configuration')"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-sm text-xs font-mono text-[#98a6b8] hover:bg-[#13171e] hover:text-white transition-colors"
                        >
                            <div class="flex items-center gap-2.5">
                                <Sliders class="w-3.5 h-3.5 text-[#6c7d93]" />
                                <span>OLS Parameters</span>
                            </div>
                            <ChevronDown class="w-3 h-3 text-[#495466] transition-transform" :class="{'rotate-180': openSubmenus.configuration}" />
                        </button>
                        <div v-if="openSubmenus.configuration" class="mt-1 pl-7 pr-2 space-y-0.5 border-l border-[#191f2a] ml-4">
                            <Link href="/configuration/settings" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">Core Settings</Link>
                            <Link href="/configuration/tuning" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">Tuning & Workers</Link>
                            <Link href="/configuration/php-limit" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">PHP Limit</Link>
                            <Link href="/configuration/error-logs" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">Audit Log</Link>
                        </div>
                    </div>

                    <!-- Server Telemetry -->
                    <div v-if="isAdmin" class="pt-1">
                        <button
                            @click="toggleSubmenu('server')"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-sm text-xs font-mono text-[#98a6b8] hover:bg-[#13171e] hover:text-white transition-colors"
                        >
                            <div class="flex items-center gap-2.5">
                                <Server class="w-3.5 h-3.5 text-[#6c7d93]" />
                                <span>Host System</span>
                            </div>
                            <ChevronDown class="w-3 h-3 text-[#495466] transition-transform" :class="{'rotate-180': openSubmenus.server}" />
                        </button>
                        <div v-if="openSubmenus.server" class="mt-1 pl-7 pr-2 space-y-0.5 border-l border-[#191f2a] ml-4">
                            <Link href="/server/services" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">System Services</Link>
                            <Link href="/server/processes" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">Process Table</Link>
                            <Link href="/server/timezone" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">Time Zone</Link>
                            <Link href="/server/restart" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">Daemon Cycle</Link>
                            <Link href="/server/hostname" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">Hostname</Link>
                            <Link href="/server/statistics" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">System Statistics</Link>
                        </div>
                    </div>

                    <!-- Security Hardening -->
                    <div class="pt-1">
                        <button
                            @click="toggleSubmenu('security')"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-sm text-xs font-mono text-[#98a6b8] hover:bg-[#13171e] hover:text-white transition-colors"
                        >
                            <div class="flex items-center gap-2.5">
                                <Shield class="w-3.5 h-3.5 text-[#6c7d93]" />
                                <span>Defense & SSL</span>
                            </div>
                            <ChevronDown class="w-3 h-3 text-[#495466] transition-transform" :class="{'rotate-180': openSubmenus.security}" />
                        </button>
                        <div v-if="openSubmenus.security" class="mt-1 pl-7 pr-2 space-y-0.5 border-l border-[#191f2a] ml-4">
                            <Link href="/security/ssl" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">SSL Certificates</Link>
                            <Link v-if="isAdmin" href="/security/csf" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">CSF Firewall</Link>
                            <Link v-if="isAdmin" href="/security/ufw" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">UFW Packet Filter</Link>
                            <Link v-if="isAdmin" href="/security/port" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">Port Firewall</Link>
                            <Link v-if="isAdmin" href="/security/modsecurity" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">ModSecurity WAF</Link>
                            <Link v-if="isAdmin" href="/security/panel-port" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">Panel Port</Link>
                            <Link v-if="isAdmin" href="/security/ssh-port" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">SSH Port</Link>
                            <Link v-if="isAdmin" href="/security/panel-ssl" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">Panel SSL</Link>
                            <Link v-if="isAdmin" href="/security/imunifyav" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">ImunifyAV</Link>
                            <Link href="/security/password" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">Password</Link>
                            <Link href="/security/two-factor" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">2-Factor Auth</Link>
                        </div>
                    </div>

                    <!-- Module Manager (Admin Only) -->
                    <div v-if="isAdmin" class="pt-1">
                        <Link
                            href="/modules"
                            class="flex items-center gap-2.5 px-3 py-2 rounded-sm text-xs font-mono text-[#98a6b8] hover:bg-[#13171e] hover:text-white transition-colors"
                        >
                            <Layers class="w-3.5 h-3.5 text-[#6c7d93]" />
                            <span>Module Manager</span>
                        </Link>
                    </div>

                    <!-- Email Section -->
                    <div class="pt-1">
                        <button
                            @click="toggleSubmenu('email')"
                            class="w-full flex items-center justify-between px-3 py-2 rounded-sm text-xs font-mono text-[#98a6b8] hover:bg-[#13171e] hover:text-white transition-colors"
                        >
                            <div class="flex items-center gap-2.5">
                                <Mail class="w-3.5 h-3.5 text-[#6c7d93]" />
                                <span>Email Transport</span>
                            </div>
                            <ChevronDown class="w-3 h-3 text-[#495466] transition-transform" :class="{'rotate-180': openSubmenus.email}" />
                        </button>
                        <div v-if="openSubmenus.email" class="mt-1 pl-7 pr-2 space-y-0.5 border-l border-[#191f2a] ml-4">
                            <Link href="/dashboard" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">Mail Accounts</Link>
                            <Link href="/dashboard" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">Forwarding Rules</Link>
                            <Link href="/dashboard" class="block px-2.5 py-1.5 rounded-sm text-xs font-mono text-[#6c7d93] hover:text-white hover:bg-[#13171e]">DKIM Signatures</Link>
                        </div>
                    </div>
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
