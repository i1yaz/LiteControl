<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useDashboardMetrics } from '@domains/ServerOps/Composables/useDashboardMetrics';
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
    <Head :title="__('Telemetry Matrix - LiteControl')" />

    <div class="space-y-6">
        <!-- Asymmetric Header & Machine Identification -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 pb-4 border-b border-theme-border-subtle">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-[10px] font-mono tracking-widest uppercase text-theme-accent">{{ __('System Observatory') }}</span>
                    <span class="text-theme-text-muted font-mono">/</span>
                    <span class="text-[10px] font-mono text-theme-text-secondary">{{ serverTime }}</span>
                </div>
                <h1 class="text-2xl font-bold tracking-tight text-theme-text-primary font-mono uppercase">
                    Active Node Telemetry
                </h1>
            </div>

            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2 px-3 py-1.5 rounded-theme-sm bg-theme-bg-surface border border-theme-border font-mono text-xs">
                    <span class="w-2 h-2 rounded-full bg-theme-success"></span>
                    <span class="text-theme-text-secondary">{{ __('HOST:') }}</span>
                    <span class="text-theme-text-primary font-semibold">{{ hostname }}</span>
                </div>
                <div class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-theme-sm bg-theme-bg-surface border border-theme-border font-mono text-xs text-theme-text-secondary">
                    <span>{{ __('IP:') }}</span>
                    <span class="text-theme-text-primary">{{ serverIp }}</span>
                </div>
            </div>
        </div>

        <!-- Primary Asymmetric Control Stage -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
            <!-- Left 8-Col: Core Compute & Memory Matrix -->
            <div class="lg:col-span-8 bg-theme-bg-card border border-theme-border rounded-theme-sm p-6 relative overflow-hidden flex flex-col justify-between">
                <div class="flex items-center justify-between pb-4 border-b border-theme-border-subtle">
                    <div class="flex items-center gap-2 font-mono text-xs text-theme-text-secondary">
                        <Cpu class="w-4 h-4 text-theme-accent" />
                        <span class="uppercase font-semibold">{{ __('Compute Core & Memory Engine') }}</span>
                    </div>
                    <span class="text-[10px] font-mono text-theme-text-muted uppercase">{{ __('Hardware Architecture') }}</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-8 my-6">
                    <!-- CPU Matrix -->
                    <div>
                        <div class="flex items-baseline justify-between mb-2 font-mono">
                            <span class="text-xs text-theme-text-secondary">{{ __('CPU Processing Load') }}</span>
                            <span class="text-2xl font-bold text-theme-text-primary">{{ metrics?.cpu_percent ?? 0 }}%</span>
                        </div>
                        <div class="h-2 w-full bg-theme-bg-surface rounded-none overflow-hidden flex">
                            <div
                                class="h-full bg-theme-accent transition-all duration-500"
                                :style="{ width: `${cpuPercent}%` }"
                            ></div>
                        </div>
                        <div class="mt-2 flex justify-between text-[11px] font-mono text-theme-text-muted">
                            <span>{{ metrics?.cpu_cores ?? 1 }} Physical Cores</span>
                            <span>{{ __('Dynamic Clock') }}</span>
                        </div>
                    </div>

                    <!-- RAM Matrix -->
                    <div>
                        <div class="flex items-baseline justify-between mb-2 font-mono">
                            <span class="text-xs text-theme-text-secondary">{{ __('Volatile RAM Usage') }}</span>
                            <span class="text-2xl font-bold text-theme-text-primary">{{ metrics?.ram_percent ?? 0 }}%</span>
                        </div>
                        <div class="h-2 w-full bg-theme-bg-surface rounded-none overflow-hidden flex">
                            <div
                                class="h-full bg-theme-success transition-all duration-500"
                                :style="{ width: `${ramPercent}%` }"
                            ></div>
                        </div>
                        <div class="mt-2 flex justify-between text-[11px] font-mono text-theme-text-muted">
                            <span>{{ ramUsedGb }} GB Allocated</span>
                            <span>{{ ramTotalGb }} GB Capacity</span>
                        </div>
                    </div>
                </div>

                <!-- Load Average Sub-Strip -->
                <div class="pt-4 border-t border-theme-border-subtle flex flex-wrap items-center justify-between gap-4 font-mono text-xs">
                    <div class="flex items-center gap-3">
                        <span class="text-theme-text-muted">{{ __('LOAD VECTORS:') }}</span>
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 bg-theme-bg-surface border border-theme-border text-theme-text-primary">{{ loadAvg['1m'] }}</span>
                            <span class="px-2 py-0.5 bg-theme-bg-surface border border-theme-border text-theme-text-secondary">{{ loadAvg['5m'] }}</span>
                            <span class="px-2 py-0.5 bg-theme-bg-surface border border-theme-border text-theme-text-muted">{{ loadAvg['15m'] }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 text-theme-text-secondary">
                        <Clock class="w-3.5 h-3.5" />
                        <span>UPTIME: {{ uptime }}</span>
                    </div>
                </div>
            </div>

            <!-- Right 4-Col: Persistent Storage Indicator -->
            <div class="lg:col-span-4 bg-theme-bg-card border border-theme-border rounded-theme-sm p-6 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between pb-4 border-b border-theme-border-subtle">
                        <div class="flex items-center gap-2 font-mono text-xs text-theme-text-secondary">
                            <HardDrive class="w-4 h-4 text-theme-info-text" />
                            <span class="uppercase font-semibold">{{ __('Storage Block') }}</span>
                        </div>
                        <span class="text-[10px] font-mono text-theme-text-muted uppercase">{{ __('NVMe/SSD') }}</span>
                    </div>

                    <div class="my-6">
                        <div class="flex items-baseline justify-between mb-2 font-mono">
                            <span class="text-xs text-theme-text-secondary">{{ __('Partition /') }}</span>
                            <span class="text-3xl font-bold text-theme-text-primary">{{ metrics?.disk_percent ?? 0 }}%</span>
                        </div>
                        <div class="h-2 w-full bg-theme-bg-surface rounded-none overflow-hidden">
                            <div
                                class="h-full bg-theme-info transition-all duration-500"
                                :style="{ width: `${diskPercent}%` }"
                            ></div>
                        </div>
                        <p class="mt-3 text-xs font-mono text-theme-text-secondary">
                            {{ metrics?.disk_used_gb ?? 0 }} GB consumed of {{ metrics?.disk_total_gb ?? 0 }} GB pool
                        </p>
                    </div>
                </div>

                <div class="pt-4 border-t border-theme-border-subtle font-mono text-xs flex items-center justify-between text-theme-text-secondary">
                    <span>{{ __('File System') }}</span>
                    <span class="text-theme-text-primary">{{ __('ext4 / root') }}</span>
                </div>
            </div>
        </div>

        <!-- Fleet Inventory Strip (Admin WHM) -->
        <div v-if="adminRole && counts" class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="p-4 bg-theme-bg-card border border-theme-border rounded-theme-sm flex items-center justify-between">
                <div>
                    <span class="text-[10px] font-mono uppercase tracking-wider text-theme-text-muted block">{{ __('Users') }}</span>
                    <span class="text-xl font-bold font-mono text-theme-text-primary mt-0.5 block">{{ counts.total_users ?? 0 }}</span>
                </div>
                <Users class="w-4 h-4 text-theme-accent" />
            </div>

            <div class="p-4 bg-theme-bg-card border border-theme-border rounded-theme-sm flex items-center justify-between">
                <div>
                    <span class="text-[10px] font-mono uppercase tracking-wider text-theme-text-muted block">{{ __('VHosts') }}</span>
                    <span class="text-xl font-bold font-mono text-theme-text-primary mt-0.5 block">{{ counts.total_domains ?? 0 }}</span>
                </div>
                <Globe class="w-4 h-4 text-theme-success-text" />
            </div>

            <div class="p-4 bg-theme-bg-card border border-theme-border rounded-theme-sm flex items-center justify-between">
                <div>
                    <span class="text-[10px] font-mono uppercase tracking-wider text-theme-text-muted block">{{ __('Databases') }}</span>
                    <span class="text-xl font-bold font-mono text-theme-text-primary mt-0.5 block">{{ counts.total_databases ?? 0 }}</span>
                </div>
                <Database class="w-4 h-4 text-theme-info-text" />
            </div>

            <div class="p-4 bg-theme-bg-card border border-theme-border rounded-theme-sm flex items-center justify-between">
                <div>
                    <span class="text-[10px] font-mono uppercase tracking-wider text-theme-text-muted block">{{ __('Profiles') }}</span>
                    <span class="text-xl font-bold font-mono text-theme-text-primary mt-0.5 block">{{ counts.total_packages ?? 0 }}</span>
                </div>
                <Package class="w-4 h-4 text-theme-warning-text" />
            </div>
        </div>

        <!-- System Spec Matrix & Execution Panel -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
            <!-- System Diagnostics (8 Cols) -->
            <div class="lg:col-span-8 bg-theme-bg-card border border-theme-border rounded-theme-sm p-6">
                <div class="flex items-center justify-between pb-4 border-b border-theme-border-subtle mb-4">
                    <div class="flex items-center gap-2 font-mono text-xs text-theme-text-secondary">
                        <Terminal class="w-4 h-4 text-theme-accent" />
                        <span class="uppercase font-semibold">{{ __('Environment Diagnostics') }}</span>
                    </div>
                    <span class="text-[10px] font-mono text-theme-text-muted uppercase">{{ __('System Specifications') }}</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-2 font-mono text-xs">
                    <div class="flex justify-between py-2 border-b border-theme-border-subtle">
                        <span class="text-theme-text-muted">{{ __('Engine Distribution') }}</span>
                        <span class="text-theme-text-primary">{{ olsVersion }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-theme-border-subtle">
                        <span class="text-theme-text-muted">{{ __('Kernel Host OS') }}</span>
                        <span class="text-theme-text-primary">{{ osPretty }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-theme-border-subtle">
                        <span class="text-theme-text-muted">{{ __('Host Machine Time') }}</span>
                        <span class="text-theme-text-primary">{{ serverTime }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-theme-border-subtle">
                        <span class="text-theme-text-muted">{{ __('Configured Zone') }}</span>
                        <span class="text-theme-text-primary">{{ timezone }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-theme-border-subtle">
                        <span class="text-theme-text-muted">{{ __('Local Network IP') }}</span>
                        <span class="text-theme-text-primary">{{ serverIp }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-theme-border-subtle">
                        <span class="text-theme-text-muted">{{ __('Runtime Profile') }}</span>
                        <span class="text-theme-success-text font-semibold">{{ __('LSAPI SuEXEC') }}</span>
                    </div>
                </div>
            </div>

            <!-- Execution Actions (4 Cols) -->
            <div class="lg:col-span-4 bg-theme-bg-card border border-theme-border rounded-theme-sm p-6 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between pb-4 border-b border-theme-border-subtle mb-4">
                        <div class="flex items-center gap-2 font-mono text-xs text-theme-text-secondary">
                            <Zap class="w-4 h-4 text-theme-accent" />
                            <span class="uppercase font-semibold">{{ __('Immediate Operations') }}</span>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <a
                            href="/phpmyadmin/"
                            target="_blank"
                            class="flex items-center justify-between p-3 rounded-theme-sm bg-theme-bg-surface hover:bg-theme-border text-xs font-mono text-theme-text-primary border border-theme-border hover:border-theme-accent-border transition-colors group"
                        >
                            <span>{{ __('phpMyAdmin Portal') }}</span>
                            <ArrowUpRight class="w-3.5 h-3.5 text-theme-text-muted group-hover:text-theme-accent" />
                        </a>

                        <Link
                            href="/domains"
                            class="flex items-center justify-between p-3 rounded-theme-sm bg-theme-bg-surface hover:bg-theme-border text-xs font-mono text-theme-text-primary border border-theme-border hover:border-theme-success-border transition-colors group"
                        >
                            <span>{{ __('VHost Management') }}</span>
                            <ArrowUpRight class="w-3.5 h-3.5 text-theme-text-muted group-hover:text-theme-success-text" />
                        </Link>

                        <Link
                            href="/php/manager"
                            class="flex items-center justify-between p-3 rounded-theme-sm bg-theme-bg-surface hover:bg-theme-border text-xs font-mono text-theme-text-primary border border-theme-border hover:border-theme-info-border transition-colors group"
                        >
                            <span>{{ __('MultiPHP Runtimes') }}</span>
                            <ArrowUpRight class="w-3.5 h-3.5 text-theme-text-muted group-hover:text-theme-info-text" />
                        </Link>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-theme-border-subtle font-mono text-[10px] text-theme-text-muted flex items-center justify-between">
                    <span>{{ __('SECURITY LAYER') }}</span>
                    <span class="text-theme-text-primary">{{ __('ENFORCED') }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
