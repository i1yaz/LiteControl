<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useDashboardMetrics } from '@/Composables/Domain/useDashboardMetrics';
import {
    Cpu, HardDrive, Globe, Database, Package,
    Clock, Zap, Terminal, ArrowUpRight, Users
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
    serverTime,
    olsVersion,
    osPretty,
    timezone,
} = useDashboardMetrics(props);
</script>

<template>
    <Head title="Telemetry Matrix - LiteControl" />

    <div class="space-y-6">
        <!-- Asymmetric Header & Machine Identification -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 pb-4 border-b border-[#1b222d]">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-[10px] font-mono tracking-widest uppercase text-[#ff6b35]">System Observatory</span>
                    <span class="text-[#3a4454] font-mono">/</span>
                    <span class="text-[10px] font-mono text-[#6c7d93]">{{ serverTime }}</span>
                </div>
                <h1 class="text-2xl font-bold tracking-tight text-white font-mono uppercase">
                    Active Node Telemetry
                </h1>
            </div>

            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2 px-3 py-1.5 rounded-sm bg-[#12161e] border border-[#232b38] font-mono text-xs">
                    <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                    <span class="text-[#8494a8]">HOST:</span>
                    <span class="text-white font-semibold">{{ hostname }}</span>
                </div>
                <div class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-sm bg-[#12161e] border border-[#232b38] font-mono text-xs text-[#8494a8]">
                    <span>IP:</span>
                    <span class="text-white">{{ serverIp }}</span>
                </div>
            </div>
        </div>

        <!-- Primary Asymmetric Control Stage -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
            <!-- Left 8-Col: Core Compute & Memory Matrix -->
            <div class="lg:col-span-8 bg-[#0f131a] border border-[#1e2634] rounded-sm p-6 relative overflow-hidden flex flex-col justify-between">
                <div class="flex items-center justify-between pb-4 border-b border-[#18202c]">
                    <div class="flex items-center gap-2 font-mono text-xs text-[#9aa8bc]">
                        <Cpu class="w-4 h-4 text-[#ff6b35]" />
                        <span class="uppercase font-semibold">Compute Core & Memory Engine</span>
                    </div>
                    <span class="text-[10px] font-mono text-[#58677c] uppercase">Hardware Architecture</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-8 my-6">
                    <!-- CPU Matrix -->
                    <div>
                        <div class="flex items-baseline justify-between mb-2 font-mono">
                            <span class="text-xs text-[#8e9cae]">CPU Processing Load</span>
                            <span class="text-2xl font-bold text-white">{{ metrics?.cpu_percent ?? 0 }}%</span>
                        </div>
                        <div class="h-2 w-full bg-[#18202b] rounded-none overflow-hidden flex">
                            <div
                                class="h-full bg-gradient-to-r from-[#ff6b35] to-[#f59e0b] transition-all duration-500"
                                :style="{ width: `${cpuPercent}%` }"
                            ></div>
                        </div>
                        <div class="mt-2 flex justify-between text-[11px] font-mono text-[#5d6d82]">
                            <span>{{ metrics?.cpu_cores ?? 1 }} Physical Cores</span>
                            <span>Dynamic Clock</span>
                        </div>
                    </div>

                    <!-- RAM Matrix -->
                    <div>
                        <div class="flex items-baseline justify-between mb-2 font-mono">
                            <span class="text-xs text-[#8e9cae]">Volatile RAM Usage</span>
                            <span class="text-2xl font-bold text-white">{{ metrics?.ram_percent ?? 0 }}%</span>
                        </div>
                        <div class="h-2 w-full bg-[#18202b] rounded-none overflow-hidden flex">
                            <div
                                class="h-full bg-gradient-to-r from-emerald-500 to-teal-400 transition-all duration-500"
                                :style="{ width: `${ramPercent}%` }"
                            ></div>
                        </div>
                        <div class="mt-2 flex justify-between text-[11px] font-mono text-[#5d6d82]">
                            <span>{{ ramUsedGb }} GB Allocated</span>
                            <span>{{ ramTotalGb }} GB Capacity</span>
                        </div>
                    </div>
                </div>

                <!-- Load Average Sub-Strip -->
                <div class="pt-4 border-t border-[#18202c] flex flex-wrap items-center justify-between gap-4 font-mono text-xs">
                    <div class="flex items-center gap-3">
                        <span class="text-[#64748b]">LOAD VECTORS:</span>
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 bg-[#171d27] border border-[#273244] text-white">{{ loadAvg['1m'] }}</span>
                            <span class="px-2 py-0.5 bg-[#171d27] border border-[#273244] text-[#8e9cae]">{{ loadAvg['5m'] }}</span>
                            <span class="px-2 py-0.5 bg-[#171d27] border border-[#273244] text-[#64748b]">{{ loadAvg['15m'] }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 text-[#6c7d93]">
                        <Clock class="w-3.5 h-3.5" />
                        <span>UPTIME: {{ uptime }}</span>
                    </div>
                </div>
            </div>

            <!-- Right 4-Col: Persistent Storage Indicator -->
            <div class="lg:col-span-4 bg-[#0f131a] border border-[#1e2634] rounded-sm p-6 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between pb-4 border-b border-[#18202c]">
                        <div class="flex items-center gap-2 font-mono text-xs text-[#9aa8bc]">
                            <HardDrive class="w-4 h-4 text-cyan-400" />
                            <span class="uppercase font-semibold">Storage Block</span>
                        </div>
                        <span class="text-[10px] font-mono text-[#58677c] uppercase">NVMe/SSD</span>
                    </div>

                    <div class="my-6">
                        <div class="flex items-baseline justify-between mb-2 font-mono">
                            <span class="text-xs text-[#8e9cae]">Partition /</span>
                            <span class="text-3xl font-bold text-white">{{ metrics?.disk_percent ?? 0 }}%</span>
                        </div>
                        <div class="h-2 w-full bg-[#18202b] rounded-none overflow-hidden">
                            <div
                                class="h-full bg-cyan-400 transition-all duration-500"
                                :style="{ width: `${diskPercent}%` }"
                            ></div>
                        </div>
                        <p class="mt-3 text-xs font-mono text-[#6c7d93]">
                            {{ metrics?.disk_used_gb ?? 0 }} GB consumed of {{ metrics?.disk_total_gb ?? 0 }} GB pool
                        </p>
                    </div>
                </div>

                <div class="pt-4 border-t border-[#18202c] font-mono text-xs flex items-center justify-between text-[#8e9cae]">
                    <span>File System</span>
                    <span class="text-white">ext4 / root</span>
                </div>
            </div>
        </div>

        <!-- Fleet Inventory Strip (Admin WHM) -->
        <div v-if="adminRole && counts" class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="p-4 bg-[#0e1218] border border-[#1d2532] rounded-sm flex items-center justify-between">
                <div>
                    <span class="text-[10px] font-mono uppercase tracking-wider text-[#637286] block">Users</span>
                    <span class="text-xl font-bold font-mono text-white mt-0.5 block">{{ counts.total_users ?? 0 }}</span>
                </div>
                <Users class="w-4 h-4 text-[#ff6b35]" />
            </div>

            <div class="p-4 bg-[#0e1218] border border-[#1d2532] rounded-sm flex items-center justify-between">
                <div>
                    <span class="text-[10px] font-mono uppercase tracking-wider text-[#637286] block">VHosts</span>
                    <span class="text-xl font-bold font-mono text-white mt-0.5 block">{{ counts.total_domains ?? 0 }}</span>
                </div>
                <Globe class="w-4 h-4 text-emerald-400" />
            </div>

            <div class="p-4 bg-[#0e1218] border border-[#1d2532] rounded-sm flex items-center justify-between">
                <div>
                    <span class="text-[10px] font-mono uppercase tracking-wider text-[#637286] block">Databases</span>
                    <span class="text-xl font-bold font-mono text-white mt-0.5 block">{{ counts.total_databases ?? 0 }}</span>
                </div>
                <Database class="w-4 h-4 text-cyan-400" />
            </div>

            <div class="p-4 bg-[#0e1218] border border-[#1d2532] rounded-sm flex items-center justify-between">
                <div>
                    <span class="text-[10px] font-mono uppercase tracking-wider text-[#637286] block">Profiles</span>
                    <span class="text-xl font-bold font-mono text-white mt-0.5 block">{{ counts.total_packages ?? 0 }}</span>
                </div>
                <Package class="w-4 h-4 text-amber-400" />
            </div>
        </div>

        <!-- System Spec Matrix & Execution Panel -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
            <!-- System Diagnostics (8 Cols) -->
            <div class="lg:col-span-8 bg-[#0f131a] border border-[#1e2634] rounded-sm p-6">
                <div class="flex items-center justify-between pb-4 border-b border-[#18202c] mb-4">
                    <div class="flex items-center gap-2 font-mono text-xs text-[#9aa8bc]">
                        <Terminal class="w-4 h-4 text-[#ff6b35]" />
                        <span class="uppercase font-semibold">Environment Diagnostics</span>
                    </div>
                    <span class="text-[10px] font-mono text-[#58677c] uppercase">System Specifications</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-2 font-mono text-xs">
                    <div class="flex justify-between py-2 border-b border-[#18202c]">
                        <span class="text-[#64748b]">Engine Distribution</span>
                        <span class="text-white">{{ olsVersion }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-[#18202c]">
                        <span class="text-[#64748b]">Kernel Host OS</span>
                        <span class="text-white">{{ osPretty }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-[#18202c]">
                        <span class="text-[#64748b]">Host Machine Time</span>
                        <span class="text-white">{{ serverTime }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-[#18202c]">
                        <span class="text-[#64748b]">Configured Zone</span>
                        <span class="text-white">{{ timezone }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-[#18202c]">
                        <span class="text-[#64748b]">Local Network IP</span>
                        <span class="text-white">{{ serverIp }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-[#18202c]">
                        <span class="text-[#64748b]">Runtime Profile</span>
                        <span class="text-emerald-400 font-semibold">LSAPI SuEXEC</span>
                    </div>
                </div>
            </div>

            <!-- Execution Actions (4 Cols) -->
            <div class="lg:col-span-4 bg-[#0f131a] border border-[#1e2634] rounded-sm p-6 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between pb-4 border-b border-[#18202c] mb-4">
                        <div class="flex items-center gap-2 font-mono text-xs text-[#9aa8bc]">
                            <Zap class="w-4 h-4 text-[#ff6b35]" />
                            <span class="uppercase font-semibold">Immediate Operations</span>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <a
                            href="/phpmyadmin/"
                            target="_blank"
                            class="flex items-center justify-between p-3 rounded-sm bg-[#131720] hover:bg-[#181e28] text-xs font-mono text-[#d6dce7] border border-[#222b3b] hover:border-[#ff6b35]/40 transition-colors group"
                        >
                            <span>phpMyAdmin Portal</span>
                            <ArrowUpRight class="w-3.5 h-3.5 text-[#5d6c80] group-hover:text-[#ff6b35]" />
                        </a>

                        <Link
                            href="/domains"
                            class="flex items-center justify-between p-3 rounded-sm bg-[#131720] hover:bg-[#181e28] text-xs font-mono text-[#d6dce7] border border-[#222b3b] hover:border-emerald-500/40 transition-colors group"
                        >
                            <span>VHost Management</span>
                            <ArrowUpRight class="w-3.5 h-3.5 text-[#5d6c80] group-hover:text-emerald-400" />
                        </Link>

                        <Link
                            href="/php/manager"
                            class="flex items-center justify-between p-3 rounded-sm bg-[#131720] hover:bg-[#181e28] text-xs font-mono text-[#d6dce7] border border-[#222b3b] hover:border-cyan-400/40 transition-colors group"
                        >
                            <span>MultiPHP Runtimes</span>
                            <ArrowUpRight class="w-3.5 h-3.5 text-[#5d6c80] group-hover:text-cyan-400" />
                        </Link>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-[#18202c] font-mono text-[10px] text-[#4d596b] flex items-center justify-between">
                    <span>SECURITY LAYER</span>
                    <span class="text-white">ENFORCED</span>
                </div>
            </div>
        </div>
    </div>
</template>
