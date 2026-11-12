<script setup>
import { Head, router } from '@inertiajs/vue3';
import { Activity, RefreshCw, Cpu, HardDrive, Server, Clock, Globe } from '@lucide/vue';

const props = defineProps({
    stats: Object,
});

const refresh = () => {
    router.reload({ only: ['stats'] });
};
</script>

<template>
            <Head title="System Statistics" />

        <div class="max-w-5xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Activity class="w-6 h-6 text-blue-400" />
                        <span>System Statistics</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Real-time hardware utilization metrics and operating system details.
                    </p>
                </div>

                <button
                    @click="refresh"
                    class="p-2 rounded-xl bg-slate-900 border border-slate-800 text-slate-300 hover:text-white hover:bg-slate-800 transition-colors self-start sm:self-auto"
                    title="Refresh Stats"
                >
                    <RefreshCw class="w-4 h-4" />
                </button>
            </div>

            <!-- Resource Progress Bars Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                <!-- CPU -->
                <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">CPU Usage</span>
                        <Cpu class="w-4 h-4 text-sky-400" />
                    </div>
                    <div class="flex items-baseline justify-between">
                        <span class="text-3xl font-extrabold text-white">{{ stats.cpu_percent || 0 }}%</span>
                        <span class="text-xs text-slate-400">{{ stats.cpu_cores || 1 }} Cores</span>
                    </div>
                    <div class="w-full h-2 rounded-full bg-slate-800 overflow-hidden">
                        <div
                            class="h-full bg-sky-500 rounded-full transition-all duration-500"
                            :style="{ width: `${Math.min(stats.cpu_percent || 0, 100)}%` }"
                        ></div>
                    </div>
                </div>

                <!-- RAM -->
                <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Memory (RAM)</span>
                        <Server class="w-4 h-4 text-purple-400" />
                    </div>
                    <div class="flex items-baseline justify-between">
                        <span class="text-3xl font-extrabold text-white">{{ stats.ram_percent || 0 }}%</span>
                        <span class="text-xs text-slate-400">{{ stats.ram_used_mb || 0 }} / {{ stats.ram_total_mb || 0 }} MB</span>
                    </div>
                    <div class="w-full h-2 rounded-full bg-slate-800 overflow-hidden">
                        <div
                            class="h-full bg-purple-500 rounded-full transition-all duration-500"
                            :style="{ width: `${Math.min(stats.ram_percent || 0, 100)}%` }"
                        ></div>
                    </div>
                </div>

                <!-- Disk -->
                <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Primary Disk</span>
                        <HardDrive class="w-4 h-4 text-emerald-400" />
                    </div>
                    <div class="flex items-baseline justify-between">
                        <span class="text-3xl font-extrabold text-white">{{ stats.disk_percent || 0 }}%</span>
                        <span class="text-xs text-slate-400">{{ stats.disk_used_gb || 0 }} / {{ stats.disk_total_gb || 0 }} GB</span>
                    </div>
                    <div class="w-full h-2 rounded-full bg-slate-800 overflow-hidden">
                        <div
                            class="h-full bg-emerald-500 rounded-full transition-all duration-500"
                            :style="{ width: `${Math.min(stats.disk_percent || 0, 100)}%` }"
                        ></div>
                    </div>
                </div>
            </div>

            <!-- Hardware & Software Specs Card -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6">
                <div class="border-b border-slate-800 pb-4">
                    <h2 class="text-base font-semibold text-white">System Environment</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Operating system, kernel version, and core service runtimes.</p>
                </div>

                <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 text-xs">
                    <div>
                        <dt class="text-slate-400 font-medium">Operating System</dt>
                        <dd class="text-white font-semibold mt-1">{{ stats.os || stats.os_pretty || 'Ubuntu Linux' }}</dd>
                    </div>

                    <div>
                        <dt class="text-slate-400 font-medium">Linux Kernel</dt>
                        <dd class="text-white font-mono mt-1">{{ stats.kernel || '6.8.0-generic' }}</dd>
                    </div>

                    <div>
                        <dt class="text-slate-400 font-medium">Web Server</dt>
                        <dd class="text-emerald-400 font-semibold mt-1">{{ stats.ols_version || 'OpenLiteSpeed 1.8.2' }}</dd>
                    </div>

                    <div>
                        <dt class="text-slate-400 font-medium">System Uptime</dt>
                        <dd class="text-white font-medium mt-1">{{ stats.uptime || stats.uptime_human || 'Unknown' }}</dd>
                    </div>

                    <div>
                        <dt class="text-slate-400 font-medium">Load Average</dt>
                        <dd class="text-white font-mono mt-1">
                            {{ typeof stats.load_avg === 'object' ? `${stats.load_avg['1m']}, ${stats.load_avg['5m']}, ${stats.load_avg['15m']}` : (stats.load_avg || '0.10, 0.05, 0.01') }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-slate-400 font-medium">Hostname</dt>
                        <dd class="text-white font-mono mt-1">{{ stats.hostname || 'localhost' }}</dd>
                    </div>
                </dl>
            </div>
        </div>
</template>

