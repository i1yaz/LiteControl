<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useDashboardMetrics } from '@/Composables/Domain/useDashboardMetrics';
import {
    Cpu, HardDrive, Globe, Database, Package,
    Clock, Zap, Server, ArrowUpRight, Users, CheckCircle2,
    Sliders, FolderTree
} from '@lucide/vue';

const props = defineProps({
    isAdmin: Boolean,
    stats: Object,
    counters: Object,
    recentUsers: Array,
    userQuotas: Object,
    domains: Array,
});

const {
    stats: metrics,
    counters: counts,
    isAdmin: adminRole,
    cpuPercent,
    ramPercent,
    diskPercent,
    ramUsedGb,
    ramTotalGb,
    loadAvg,
    uptime,
    hostname,
    serverIp,
    olsVersion,
    osPretty,
    timezone,
} = useDashboardMetrics(props);
</script>

<template>
    <Head title="Dashboard - LiteControl" />

    <div class="space-y-6">
        <!-- Welcome & System Health Banner -->
        <div class="bg-gradient-to-r from-slate-900 via-slate-900 to-indigo-950/40 border border-slate-800 rounded-2xl p-6 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                        <CheckCircle2 class="w-3.5 h-3.5" /> All Services Operational
                    </span>
                    <span class="text-slate-500 text-xs">•</span>
                    <span class="text-xs text-slate-400">Uptime: {{ uptime }}</span>
                </div>
                <h1 class="text-2xl font-bold text-white tracking-tight">
                    Server Overview
                </h1>
                <p class="text-xs text-slate-400 mt-1">
                    Node <span class="text-slate-200 font-semibold">{{ hostname }}</span> ({{ serverIp }}) running {{ olsVersion }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <Link
                    href="/domains"
                    class="px-4 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold shadow-md shadow-sky-500/20 transition-all flex items-center gap-1.5"
                >
                    <Globe class="w-3.5 h-3.5" /> Add Domain
                </Link>
                <Link
                    href="/databases"
                    class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold border border-slate-700/80 transition-all flex items-center gap-1.5"
                >
                    <Database class="w-3.5 h-3.5" /> Databases
                </Link>
            </div>
        </div>

        <!-- 3 Core Resource Gauges -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <!-- CPU Load Card -->
            <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 shadow-sm backdrop-blur">
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-slate-800/80">
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-sky-500/10 text-sky-400">
                            <Cpu class="w-5 h-5" />
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-white">CPU Processing</h3>
                            <span class="text-[11px] text-slate-400">{{ metrics?.cpu_cores ?? 1 }} vCPU Cores</span>
                        </div>
                    </div>
                    <span class="text-2xl font-bold text-white">{{ metrics?.cpu_percent ?? 0 }}%</span>
                </div>
                <div class="space-y-2">
                    <div class="h-2.5 w-full bg-slate-800 rounded-full overflow-hidden">
                        <div
                            class="h-full bg-gradient-to-r from-sky-500 to-indigo-500 rounded-full transition-all duration-500"
                            :style="{ width: `${cpuPercent}%` }"
                        ></div>
                    </div>
                    <div class="flex justify-between text-xs text-slate-400">
                        <span>Load Avg: {{ loadAvg['1m'] }} / {{ loadAvg['5m'] }} / {{ loadAvg['15m'] }}</span>
                    </div>
                </div>
            </div>

            <!-- Memory Card -->
            <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 shadow-sm backdrop-blur">
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-slate-800/80">
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-emerald-500/10 text-emerald-400">
                            <Server class="w-5 h-5" />
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-white">System Memory</h3>
                            <span class="text-[11px] text-slate-400">{{ ramUsedGb }} GB / {{ ramTotalGb }} GB</span>
                        </div>
                    </div>
                    <span class="text-2xl font-bold text-white">{{ metrics?.ram_percent ?? 0 }}%</span>
                </div>
                <div class="space-y-2">
                    <div class="h-2.5 w-full bg-slate-800 rounded-full overflow-hidden">
                        <div
                            class="h-full bg-gradient-to-r from-emerald-500 to-teal-400 rounded-full transition-all duration-500"
                            :style="{ width: `${ramPercent}%` }"
                        ></div>
                    </div>
                    <div class="flex justify-between text-xs text-slate-400">
                        <span>Dynamic Memory Pool</span>
                        <span>{{ ramPercent }}% Active</span>
                    </div>
                </div>
            </div>

            <!-- Storage Card -->
            <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 shadow-sm backdrop-blur">
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-slate-800/80">
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-indigo-500/10 text-indigo-400">
                            <HardDrive class="w-5 h-5" />
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-white">Disk Storage</h3>
                            <span class="text-[11px] text-slate-400">{{ metrics?.disk_used_gb ?? 0 }} GB / {{ metrics?.disk_total_gb ?? 0 }} GB</span>
                        </div>
                    </div>
                    <span class="text-2xl font-bold text-white">{{ metrics?.disk_percent ?? 0 }}%</span>
                </div>
                <div class="space-y-2">
                    <div class="h-2.5 w-full bg-slate-800 rounded-full overflow-hidden">
                        <div
                            class="h-full bg-gradient-to-r from-indigo-500 to-sky-400 rounded-full transition-all duration-500"
                            :style="{ width: `${diskPercent}%` }"
                        ></div>
                    </div>
                    <div class="flex justify-between text-xs text-slate-400">
                        <span>NVMe Primary Partition</span>
                        <span>ext4</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inventory Summary Badges (Admin) -->
        <div v-if="adminRole && counts" class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <Link href="/users" class="p-4 bg-slate-900/70 border border-slate-800/90 rounded-xl hover:border-slate-700 transition-colors flex items-center justify-between group">
                <div>
                    <span class="text-xs text-slate-400 font-medium">User Accounts</span>
                    <span class="text-xl font-bold text-white mt-0.5 block">{{ counts.total_users ?? 0 }}</span>
                </div>
                <Users class="w-5 h-5 text-sky-400 group-hover:scale-110 transition-transform" />
            </Link>

            <Link href="/domains" class="p-4 bg-slate-900/70 border border-slate-800/90 rounded-xl hover:border-slate-700 transition-colors flex items-center justify-between group">
                <div>
                    <span class="text-xs text-slate-400 font-medium">Hosted Domains</span>
                    <span class="text-xl font-bold text-white mt-0.5 block">{{ counts.total_domains ?? 0 }}</span>
                </div>
                <Globe class="w-5 h-5 text-emerald-400 group-hover:scale-110 transition-transform" />
            </Link>

            <Link href="/databases" class="p-4 bg-slate-900/70 border border-slate-800/90 rounded-xl hover:border-slate-700 transition-colors flex items-center justify-between group">
                <div>
                    <span class="text-xs text-slate-400 font-medium">Databases</span>
                    <span class="text-xl font-bold text-white mt-0.5 block">{{ counts.total_databases ?? 0 }}</span>
                </div>
                <Database class="w-5 h-5 text-indigo-400 group-hover:scale-110 transition-transform" />
            </Link>

            <Link href="/packages" class="p-4 bg-slate-900/70 border border-slate-800/90 rounded-xl hover:border-slate-700 transition-colors flex items-center justify-between group">
                <div>
                    <span class="text-xs text-slate-400 font-medium">Hosting Packages</span>
                    <span class="text-xl font-bold text-white mt-0.5 block">{{ counts.total_packages ?? 0 }}</span>
                </div>
                <Package class="w-5 h-5 text-amber-400 group-hover:scale-110 transition-transform" />
            </Link>
        </div>

        <!-- Quick Access Services & Specifications Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
            <!-- System Details Card -->
            <div class="lg:col-span-7 bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-sm">
                <h3 class="text-base font-semibold text-white mb-4 pb-3 border-b border-slate-800 flex items-center gap-2">
                    <Server class="w-4 h-4 text-sky-400" /> System Specifications
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div class="p-3 rounded-xl bg-slate-950/60 border border-slate-800/60">
                        <span class="text-slate-400 block mb-0.5">Web Server</span>
                        <span class="text-slate-100 font-medium">{{ olsVersion }}</span>
                    </div>
                    <div class="p-3 rounded-xl bg-slate-950/60 border border-slate-800/60">
                        <span class="text-slate-400 block mb-0.5">Operating System</span>
                        <span class="text-slate-100 font-medium">{{ osPretty }}</span>
                    </div>
                    <div class="p-3 rounded-xl bg-slate-950/60 border border-slate-800/60">
                        <span class="text-slate-400 block mb-0.5">Time Zone</span>
                        <span class="text-slate-100 font-medium">{{ timezone }}</span>
                    </div>
                    <div class="p-3 rounded-xl bg-slate-950/60 border border-slate-800/60">
                        <span class="text-slate-400 block mb-0.5">PHP Engine</span>
                        <span class="text-emerald-400 font-medium">LiteSpeed LSAPI</span>
                    </div>
                </div>
            </div>

            <!-- Quick Service Portals -->
            <div class="lg:col-span-5 bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-sm flex flex-col justify-between">
                <div>
                    <h3 class="text-base font-semibold text-white mb-4 pb-3 border-b border-slate-800 flex items-center gap-2">
                        <Zap class="w-4 h-4 text-amber-400" /> Direct Shortcuts
                    </h3>
                    <div class="space-y-2.5">
                        <a
                            href="/phpmyadmin/"
                            target="_blank"
                            class="flex items-center justify-between p-3 rounded-xl bg-slate-950/60 hover:bg-slate-800/80 border border-slate-800/60 text-xs font-medium text-slate-200 transition-colors group"
                        >
                            <span class="flex items-center gap-2">
                                <Database class="w-4 h-4 text-indigo-400" /> phpMyAdmin Database Manager
                            </span>
                            <ArrowUpRight class="w-4 h-4 text-slate-500 group-hover:text-white" />
                        </a>
                        <Link
                            href="/files"
                            class="flex items-center justify-between p-3 rounded-xl bg-slate-950/60 hover:bg-slate-800/80 border border-slate-800/60 text-xs font-medium text-slate-200 transition-colors group"
                        >
                            <span class="flex items-center gap-2">
                                <FolderTree class="w-4 h-4 text-sky-400" /> Web File Explorer
                            </span>
                            <ArrowUpRight class="w-4 h-4 text-slate-500 group-hover:text-white" />
                        </Link>
                        <Link
                            href="/php/manager"
                            class="flex items-center justify-between p-3 rounded-xl bg-slate-950/60 hover:bg-slate-800/80 border border-slate-800/60 text-xs font-medium text-slate-200 transition-colors group"
                        >
                            <span class="flex items-center gap-2">
                                <Sliders class="w-4 h-4 text-emerald-400" /> MultiPHP Configuration
                            </span>
                            <ArrowUpRight class="w-4 h-4 text-slate-500 group-hover:text-white" />
                        </Link>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-slate-800 text-[11px] text-slate-400 flex items-center justify-between">
                    <span>LiteControl Core</span>
                    <span class="text-emerald-400 font-medium">Ready</span>
                </div>
            </div>
        </div>
    </div>
</template>
