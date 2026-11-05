<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PanelLayout from '@/Layouts/PanelLayout.vue';
import {
    Cpu,
    HardDrive,
    Server,
    Layers,
    Users,
    Globe,
    Database,
    Package,
    Activity,
    Shield,
    FolderTree,
    FileCode,
    Sliders,
    Clock,
    Zap,
    ExternalLink
} from 'lucide-vue-next';

const props = defineProps({
    isAdmin: Boolean,
    stats: Object,
    counters: Object,
    recentUsers: Array,
    userQuotas: Object,
    domains: Array,
});

// Helper for gauge colors
const getProgressColor = (percent) => {
    if (percent > 85) return 'bg-rose-500';
    if (percent > 65) return 'bg-amber-500';
    return 'bg-indigo-500';
};
</script>

<template>
    <Head title="Dashboard - LiteControl" />

    <PanelLayout>
        <div class="space-y-6">
            <!-- Header Welcome Title -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
                        <span>Dashboard</span>
                        <span v-if="isAdmin" class="text-xs px-2.5 py-0.5 rounded-full bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 font-semibold">
                            WHM Administrator
                        </span>
                    </h1>
                    <p class="text-xs text-slate-400 mt-1">
                        Server overview, resource metrics, and hosting services
                    </p>
                </div>

                <div class="flex items-center gap-2 text-xs text-slate-400 bg-slate-900 px-3 py-1.5 rounded-lg border border-slate-800">
                    <Server class="w-4 h-4 text-indigo-400" />
                    <span class="font-mono text-slate-300">{{ stats?.hostname || 'localhost' }}</span>
                </div>
            </div>

            <!-- Resource Gauges (Matches screenshots) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- CPU Usage -->
                <div class="p-5 rounded-2xl bg-slate-900/90 border border-slate-800/80 shadow-sm relative overflow-hidden">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">CPU Usage</span>
                        <div class="p-2 rounded-lg bg-indigo-500/10 text-indigo-400">
                            <Cpu class="w-4 h-4" />
                        </div>
                    </div>
                    <div class="mt-4 flex items-baseline gap-2">
                        <span class="text-2xl font-bold text-white">{{ stats?.cpu_percent ?? 0 }}%</span>
                        <span class="text-xs text-slate-400">{{ stats?.cpu_cores ?? 1 }} Cores</span>
                    </div>
                    <div class="mt-3 w-full bg-slate-800 rounded-full h-1.5 overflow-hidden">
                        <div
                            :class="getProgressColor(stats?.cpu_percent ?? 0)"
                            class="h-1.5 rounded-full transition-all duration-500"
                            :style="{ width: `${Math.min(stats?.cpu_percent ?? 0, 100)}%` }"
                        ></div>
                    </div>
                </div>

                <!-- RAM Usage -->
                <div class="p-5 rounded-2xl bg-slate-900/90 border border-slate-800/80 shadow-sm relative overflow-hidden">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Memory (RAM)</span>
                        <div class="p-2 rounded-lg bg-emerald-500/10 text-emerald-400">
                            <Layers class="w-4 h-4" />
                        </div>
                    </div>
                    <div class="mt-4 flex items-baseline gap-2">
                        <span class="text-2xl font-bold text-white">{{ stats?.ram_percent ?? 0 }}%</span>
                        <span class="text-xs text-slate-400">
                            {{ Math.round((stats?.ram_used_mb ?? 0) / 1024 * 10) / 10 }} / {{ Math.round((stats?.ram_total_mb ?? 0) / 1024 * 10) / 10 }} GB
                        </span>
                    </div>
                    <div class="mt-3 w-full bg-slate-800 rounded-full h-1.5 overflow-hidden">
                        <div
                            :class="getProgressColor(stats?.ram_percent ?? 0)"
                            class="h-1.5 rounded-full transition-all duration-500"
                            :style="{ width: `${Math.min(stats?.ram_percent ?? 0, 100)}%` }"
                        ></div>
                    </div>
                </div>

                <!-- Disk Usage -->
                <div class="p-5 rounded-2xl bg-slate-900/90 border border-slate-800/80 shadow-sm relative overflow-hidden">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Disk Storage</span>
                        <div class="p-2 rounded-lg bg-amber-500/10 text-amber-400">
                            <HardDrive class="w-4 h-4" />
                        </div>
                    </div>
                    <div class="mt-4 flex items-baseline gap-2">
                        <span class="text-2xl font-bold text-white">{{ stats?.disk_percent ?? 0 }}%</span>
                        <span class="text-xs text-slate-400">
                            {{ stats?.disk_used_gb ?? 0 }} / {{ stats?.disk_total_gb ?? 0 }} GB
                        </span>
                    </div>
                    <div class="mt-3 w-full bg-slate-800 rounded-full h-1.5 overflow-hidden">
                        <div
                            :class="getProgressColor(stats?.disk_percent ?? 0)"
                            class="h-1.5 rounded-full transition-all duration-500"
                            :style="{ width: `${Math.min(stats?.disk_percent ?? 0, 100)}%` }"
                        ></div>
                    </div>
                </div>

                <!-- Server Load / Uptime -->
                <div class="p-5 rounded-2xl bg-slate-900/90 border border-slate-800/80 shadow-sm relative overflow-hidden">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Load Average</span>
                        <div class="p-2 rounded-lg bg-sky-500/10 text-sky-400">
                            <Activity class="w-4 h-4" />
                        </div>
                    </div>
                    <div class="mt-4 flex items-baseline gap-2">
                        <span class="text-xl font-bold text-white font-mono">
                            {{ stats?.load_avg?.['1m'] ?? 0 }}
                        </span>
                        <span class="text-xs text-slate-400 font-mono">
                            {{ stats?.load_avg?.['5m'] ?? 0 }} {{ stats?.load_avg?.['15m'] ?? 0 }}
                        </span>
                    </div>
                    <div class="mt-3 flex items-center gap-1.5 text-[11px] text-slate-400 truncate">
                        <Clock class="w-3 h-3 text-slate-500 flex-shrink-0" />
                        <span>Up {{ stats?.uptime_human || 'recently' }}</span>
                    </div>
                </div>
            </div>

            <!-- Admin WHM Counter Cards -->
            <div v-if="isAdmin && counters" class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="p-4 rounded-xl bg-slate-900 border border-slate-800 flex items-center gap-3">
                    <div class="p-2.5 rounded-lg bg-indigo-500/10 text-indigo-400">
                        <Users class="w-5 h-5" />
                    </div>
                    <div>
                        <span class="block text-xl font-bold text-white">{{ counters.total_users ?? 0 }}</span>
                        <span class="block text-xs text-slate-400">Hosting Users</span>
                    </div>
                </div>

                <div class="p-4 rounded-xl bg-slate-900 border border-slate-800 flex items-center gap-3">
                    <div class="p-2.5 rounded-lg bg-emerald-500/10 text-emerald-400">
                        <Globe class="w-5 h-5" />
                    </div>
                    <div>
                        <span class="block text-xl font-bold text-white">{{ counters.total_domains ?? 0 }}</span>
                        <span class="block text-xs text-slate-400">Domains</span>
                    </div>
                </div>

                <div class="p-4 rounded-xl bg-slate-900 border border-slate-800 flex items-center gap-3">
                    <div class="p-2.5 rounded-lg bg-amber-500/10 text-amber-400">
                        <Database class="w-5 h-5" />
                    </div>
                    <div>
                        <span class="block text-xl font-bold text-white">{{ counters.total_databases ?? 0 }}</span>
                        <span class="block text-xs text-slate-400">Databases</span>
                    </div>
                </div>

                <div class="p-4 rounded-xl bg-slate-900 border border-slate-800 flex items-center gap-3">
                    <div class="p-2.5 rounded-lg bg-purple-500/10 text-purple-400">
                        <Package class="w-5 h-5" />
                    </div>
                    <div>
                        <span class="block text-xl font-bold text-white">{{ counters.total_packages ?? 0 }}</span>
                        <span class="block text-xs text-slate-400">Packages</span>
                    </div>
                </div>
            </div>

            <!-- Server Statistics Table & Info Box -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Server Information Table (Matches whm.png & user.png) -->
                <div class="lg:col-span-2 rounded-2xl bg-slate-900/90 border border-slate-800/80 p-5 shadow-sm">
                    <h2 class="text-sm font-semibold text-white uppercase tracking-wider mb-4 flex items-center gap-2">
                        <Server class="w-4 h-4 text-indigo-400" />
                        <span>Server Statistics</span>
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                        <div class="flex justify-between py-2 border-b border-slate-800">
                            <span class="text-slate-400">Server IP</span>
                            <span class="text-slate-200 font-mono">{{ stats?.server_ip || '127.0.0.1' }}</span>
                        </div>
                        <div class="flex justify-between py-2 border-b border-slate-800">
                            <span class="text-slate-400">Server Time</span>
                            <span class="text-slate-200 font-mono">{{ stats?.server_time || 'N/A' }}</span>
                        </div>
                        <div class="flex justify-between py-2 border-b border-slate-800">
                            <span class="text-slate-400">Web Server</span>
                            <span class="text-slate-200 font-medium">{{ stats?.ols_version || 'OpenLiteSpeed' }}</span>
                        </div>
                        <div class="flex justify-between py-2 border-b border-slate-800">
                            <span class="text-slate-400">Operating System</span>
                            <span class="text-slate-200 font-medium">{{ stats?.os_pretty || 'Ubuntu Linux' }}</span>
                        </div>
                        <div class="flex justify-between py-2 border-b border-slate-800">
                            <span class="text-slate-400">Timezone</span>
                            <span class="text-slate-200">{{ stats?.timezone || 'UTC' }}</span>
                        </div>
                        <div class="flex justify-between py-2 border-b border-slate-800">
                            <span class="text-slate-400">Uptime</span>
                            <span class="text-slate-200">{{ stats?.uptime_human || 'Active' }}</span>
                        </div>
                    </div>
                </div>

                <!-- Quick Services Status / Actions -->
                <div class="rounded-2xl bg-slate-900/90 border border-slate-800/80 p-5 shadow-sm flex flex-col justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-white uppercase tracking-wider mb-4 flex items-center gap-2">
                            <Zap class="w-4 h-4 text-amber-400" />
                            <span>Quick Actions</span>
                        </h2>
                        <div class="space-y-2">
                            <a
                                href="/phpmyadmin/"
                                target="_blank"
                                class="flex items-center justify-between p-3 rounded-xl bg-slate-800/60 hover:bg-slate-800 text-xs font-medium text-slate-200 transition-colors border border-slate-700/40"
                            >
                                <div class="flex items-center gap-2.5">
                                    <Database class="w-4 h-4 text-amber-400" />
                                    <span>Launch phpMyAdmin</span>
                                </div>
                                <ExternalLink class="w-3.5 h-3.5 text-slate-500" />
                            </a>
                            <Link
                                href="/dashboard"
                                class="flex items-center justify-between p-3 rounded-xl bg-slate-800/60 hover:bg-slate-800 text-xs font-medium text-slate-200 transition-colors border border-slate-700/40"
                            >
                                <div class="flex items-center gap-2.5">
                                    <Globe class="w-4 h-4 text-emerald-400" />
                                    <span>Manage Domains</span>
                                </div>
                            </Link>
                            <Link
                                href="/dashboard"
                                class="flex items-center justify-between p-3 rounded-xl bg-slate-800/60 hover:bg-slate-800 text-xs font-medium text-slate-200 transition-colors border border-slate-700/40"
                            >
                                <div class="flex items-center gap-2.5">
                                    <FileCode class="w-4 h-4 text-purple-400" />
                                    <span>MultiPHP Manager</span>
                                </div>
                            </Link>
                        </div>
                    </div>

                    <div class="mt-4 pt-4 border-t border-slate-800 text-[11px] text-slate-500 flex items-center justify-between">
                        <span>Panel Mode</span>
                        <span class="text-indigo-400 font-medium">OpenLiteSpeed SuEXEC</span>
                    </div>
                </div>
            </div>
        </div>
    </PanelLayout>
</template>

