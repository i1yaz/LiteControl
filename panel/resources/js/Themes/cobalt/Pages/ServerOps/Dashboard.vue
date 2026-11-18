<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useDashboardMetrics } from '@domains/ServerOps/Composables/useDashboardMetrics';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import {
    Cpu, HardDrive, Globe, Database, Package,
    Clock, Zap, Server, ArrowUpRight, Users,
    Sliders, FolderTree, Shield, FileText, RefreshCw
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

const sparklineBars = [20, 35, 28, 42, 50, 30, 22, 18, 26, 38, 48, 32];
</script>

<template>
    <Head :title="__('Server Overview - LiteControl')" />

    <div class="flex flex-col w-full gap-4">
        <!-- Stitch Cobalt Precision Page Header -->
        <div class="flex flex-col xl:flex-row xl:items-center justify-between gap-3 pb-1">
            <div class="flex flex-col gap-0.5">
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl text-theme-text-primary tracking-tight font-semibold">
                        {{ __('Server Overview') }}
                    </h1>
                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-theme-sm bg-theme-bg-surface text-theme-accent text-[11px] font-semibold border border-theme-border">
                        <span class="w-1.5 h-1.5 rounded-full bg-theme-accent animate-pulse"></span>
                        {{ hostname }}
                    </span>
                </div>
                <p class="font-mono text-xs text-theme-text-secondary flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span>Host: <span class="text-theme-text-primary font-medium">{{ hostname }}</span></span>
                    <span class="text-theme-border-strong">·</span>
                    <span>{{ osPretty }}</span>
                    <span class="text-theme-border-strong">·</span>
                    <span class="inline-flex items-center gap-1 text-theme-text-primary">
                        <Clock class="w-3.5 h-3.5 text-theme-text-secondary" />
                        Uptime: {{ uptime }}
                    </span>
                </p>
            </div>

            <!-- Page Actions -->
            <div class="flex flex-wrap items-center gap-2">
                <Link href="/domains">
                    <UiButton variant="primary" size="sm">
                        <Globe class="w-3.5 h-3.5" />
                        <span>{{ __('Add VHost') }}</span>
                    </UiButton>
                </Link>
                <Link v-if="adminRole" href="/server/services">
                    <UiButton variant="secondary" size="sm">
                        <RefreshCw class="w-3.5 h-3.5" />
                        <span>{{ __('Manage Services') }}</span>
                    </UiButton>
                </Link>
                <Link v-if="adminRole" href="/server/restart">
                    <UiButton variant="warning" size="sm">
                        <Server class="w-3.5 h-3.5" />
                        <span>{{ __('Restart Daemons') }}</span>
                    </UiButton>
                </Link>
            </div>
        </div>

        <!-- Row 1: Real-Time Resource Gauges (Stitch 3-Column Telemetry Grid) -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <!-- Card 1: Processor Workload (CPU) -->
            <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-4 flex flex-col justify-between shadow-xs hover:border-theme-border-strong transition-colors">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-theme-text-secondary text-[11px] font-semibold uppercase tracking-wider">
                            {{ __('Processor Workload') }}
                        </div>
                        <div class="flex items-baseline gap-2 mt-1">
                            <span class="text-2xl font-bold text-theme-text-primary tracking-tight">
                                {{ metrics?.cpu_percent ?? 0 }}%
                            </span>
                            <span class="font-mono text-xs text-theme-text-secondary font-medium">
                                {{ metrics?.cpu_cores ?? 1 }} Cores
                            </span>
                        </div>
                    </div>
                    <UiBadge :variant="cpuPercent > 85 ? 'danger' : 'success'">
                        <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                        {{ cpuPercent > 85 ? 'HIGH LOAD' : 'NORMAL' }}
                    </UiBadge>
                </div>

                <!-- Sparkline Bars + Progress Bar -->
                <div class="my-3 py-1">
                    <div class="h-10 w-full flex items-end gap-1">
                        <div
                            v-for="(bar, idx) in sparklineBars"
                            :key="idx"
                            class="flex-1 bg-theme-bg-surface rounded-t-theme-sm"
                            :style="{ height: `${bar}%` }"
                        ></div>
                        <div
                            class="flex-1 bg-theme-accent rounded-t-theme-sm transition-all duration-500"
                            :style="{ height: `${Math.max(cpuPercent, 12)}%` }"
                        ></div>
                    </div>
                    <div class="w-full bg-theme-bg-surface h-1.5 rounded-full overflow-hidden mt-1.5">
                        <div
                            class="bg-theme-accent h-full rounded-full transition-all duration-500"
                            :style="{ width: `${cpuPercent}%` }"
                        ></div>
                    </div>
                </div>

                <div class="pt-2 border-t border-theme-border-subtle flex items-center justify-between text-theme-text-secondary font-mono text-xs">
                    <span class="truncate">{{ olsVersion }}</span>
                    <span class="shrink-0 text-theme-text-primary font-medium">
                        Load: {{ loadAvg['1m'] }}, {{ loadAvg['5m'] }}, {{ loadAvg['15m'] }}
                    </span>
                </div>
            </div>

            <!-- Card 2: Physical Memory (RAM) -->
            <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-4 flex flex-col justify-between shadow-xs hover:border-theme-border-strong transition-colors">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-theme-text-secondary text-[11px] font-semibold uppercase tracking-wider">
                            {{ __('Physical Memory (RAM)') }}
                        </div>
                        <div class="flex items-baseline gap-2 mt-1">
                            <span class="text-2xl font-bold text-theme-text-primary tracking-tight">
                                {{ ramUsedGb }} GB
                            </span>
                            <span class="font-mono text-xs text-theme-text-secondary">
                                / {{ ramTotalGb }} GB
                            </span>
                        </div>
                    </div>
                    <span class="font-mono text-xs font-semibold text-theme-accent px-2 py-0.5 rounded-theme-sm bg-theme-bg-surface border border-theme-border">
                        {{ metrics?.ram_percent ?? 0 }}% USED
                    </span>
                </div>

                <div class="my-3 py-1">
                    <div class="flex justify-between items-center text-[11px] font-semibold text-theme-text-secondary mb-1.5">
                        <span>{{ __('Active Heap & Buffers') }}</span>
                        <span class="font-mono text-xs text-theme-text-primary">{{ ramPercent }}% Allocated</span>
                    </div>
                    <div class="w-full bg-theme-bg-surface h-2 rounded-full overflow-hidden flex">
                        <div
                            class="bg-theme-accent h-full transition-all duration-500"
                            :style="{ width: `${ramPercent}%` }"
                        ></div>
                    </div>
                    <div class="flex items-center gap-3 mt-2 text-[11px] text-theme-text-secondary">
                        <span class="flex items-center gap-1">
                            <span class="w-2 h-2 rounded-full bg-theme-accent inline-block"></span>
                            Allocated ({{ ramUsedGb }}G)
                        </span>
                        <span class="flex items-center gap-1">
                            <span class="w-2 h-2 rounded-full bg-theme-border-strong inline-block"></span>
                            Total ({{ ramTotalGb }}G)
                        </span>
                    </div>
                </div>

                <div class="pt-2 border-t border-theme-border-subtle flex items-center justify-between text-theme-text-secondary font-mono text-xs">
                    <span>{{ __('Dynamic Memory Pool') }}</span>
                    <span class="text-theme-success-text font-medium">{{ __('Healthy') }}</span>
                </div>
            </div>

            <!-- Card 3: NVMe Primary Storage -->
            <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-4 flex flex-col justify-between shadow-xs hover:border-theme-border-strong transition-colors">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-theme-text-secondary text-[11px] font-semibold uppercase tracking-wider">
                            {{ __('NVMe Primary Storage') }}
                        </div>
                        <div class="flex items-baseline gap-2 mt-1">
                            <span class="text-2xl font-bold text-theme-text-primary tracking-tight">
                                {{ metrics?.disk_used_gb ?? 0 }} GB
                            </span>
                            <span class="font-mono text-xs text-theme-text-secondary">
                                / {{ metrics?.disk_total_gb ?? 0 }} GB
                            </span>
                        </div>
                    </div>
                    <span class="font-mono text-xs text-theme-text-secondary font-medium px-2 py-0.5 rounded-theme-sm bg-theme-bg-surface border border-theme-border">
                        {{ metrics?.disk_percent ?? 0 }}%
                    </span>
                </div>

                <div class="my-3 py-1">
                    <div class="flex justify-between items-center text-[11px] text-theme-text-secondary mb-1.5">
                        <span class="font-mono text-xs text-theme-text-primary">/dev/root</span>
                        <span class="font-mono text-xs text-theme-text-secondary">ext4 mounted at /</span>
                    </div>
                    <div class="w-full bg-theme-bg-surface h-2 rounded-full overflow-hidden">
                        <div
                            class="bg-theme-accent h-full rounded-full transition-all duration-500"
                            :style="{ width: `${diskPercent}%` }"
                        ></div>
                    </div>
                    <div class="flex items-center justify-between mt-2 text-[11px] text-theme-text-secondary">
                        <span>{{ __('Primary Partition') }}</span>
                        <span class="font-mono text-xs text-theme-accent font-medium">{{ 100 - diskPercent }}% Free</span>
                    </div>
                </div>

                <div class="pt-2 border-t border-theme-border-subtle flex items-center justify-between text-theme-text-secondary font-mono text-xs">
                    <span>{{ __('File System') }}</span>
                    <span class="text-theme-text-primary font-medium">ext4 · Quotas Enabled</span>
                </div>
            </div>
        </div>

        <!-- Row 2: 4 Admin Inventory StatCards -->
        <div v-if="adminRole && counts" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <Link
                href="/domains"
                class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-3.5 flex items-center justify-between shadow-xs hover:border-theme-border-strong transition-colors group"
            >
                <div class="flex flex-col">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-theme-text-secondary">
                        {{ __('Active Websites') }}
                    </span>
                    <span class="text-xl font-bold text-theme-text-primary mt-0.5">
                        {{ counts.total_domains ?? 0 }}
                    </span>
                    <span class="font-mono text-xs text-theme-success-text font-medium mt-1">
                        HTTP/3 QUIC Enabled
                    </span>
                </div>
                <div class="w-10 h-10 rounded-theme-md bg-theme-bg-surface flex items-center justify-center text-theme-accent">
                    <Globe class="w-5 h-5" />
                </div>
            </Link>

            <Link
                href="/users"
                class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-3.5 flex items-center justify-between shadow-xs hover:border-theme-border-strong transition-colors group"
            >
                <div class="flex flex-col">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-theme-text-secondary">
                        {{ __('Total Users') }}
                    </span>
                    <span class="text-xl font-bold text-theme-text-primary mt-0.5">
                        {{ counts.total_users ?? 0 }}
                    </span>
                    <span class="font-mono text-xs text-theme-text-secondary mt-1">
                        Isolated Linux Tenants
                    </span>
                </div>
                <div class="w-10 h-10 rounded-theme-md bg-theme-bg-surface flex items-center justify-center text-theme-accent">
                    <Users class="w-5 h-5" />
                </div>
            </Link>

            <Link
                href="/databases"
                class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-3.5 flex items-center justify-between shadow-xs hover:border-theme-border-strong transition-colors group"
            >
                <div class="flex flex-col">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-theme-text-secondary">
                        {{ __('MySQL Databases') }}
                    </span>
                    <span class="text-xl font-bold text-theme-text-primary mt-0.5">
                        {{ counts.total_databases ?? 0 }}
                    </span>
                    <span class="font-mono text-xs text-theme-text-secondary mt-1">
                        MariaDB Relational Engine
                    </span>
                </div>
                <div class="w-10 h-10 rounded-theme-md bg-theme-bg-surface flex items-center justify-center text-theme-accent">
                    <Database class="w-5 h-5" />
                </div>
            </Link>

            <Link
                href="/packages"
                class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-3.5 flex items-center justify-between shadow-xs hover:border-theme-border-strong transition-colors group"
            >
                <div class="flex flex-col">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-theme-text-secondary">
                        {{ __('Hosting Packages') }}
                    </span>
                    <span class="text-xl font-bold text-theme-text-primary mt-0.5">
                        {{ counts.total_packages ?? 0 }}
                    </span>
                    <span class="font-mono text-xs text-theme-text-secondary mt-1">
                        Resource Quota Profiles
                    </span>
                </div>
                <div class="w-10 h-10 rounded-theme-md bg-theme-bg-surface flex items-center justify-center text-theme-accent">
                    <Package class="w-5 h-5" />
                </div>
            </Link>
        </div>

        <!-- Row 3: Main Operational Workspace (7 cols / 5 cols split per Stitch design) -->
        <div class="grid grid-cols-1 xl:grid-cols-12 gap-4 items-start">
            <!-- Left Column (7 cols): Quick Actions Grid -->
            <div class="xl:col-span-7 bg-theme-bg-card border border-theme-border rounded-theme-lg p-4 shadow-xs">
                <div class="flex items-center justify-between pb-3 border-b border-theme-border-subtle mb-3">
                    <div class="flex items-center gap-2">
                        <Zap class="w-4 h-4 text-theme-accent" />
                        <h3 class="text-base text-theme-text-primary font-semibold">
                            {{ __('Quick Actions & Administrative Routines') }}
                        </h3>
                    </div>
                    <span class="font-mono text-xs text-theme-text-secondary">{{ __('Direct Shortcuts') }}</span>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5">
                    <Link
                        href="/domains"
                        class="flex flex-col items-center justify-center p-3.5 rounded-theme-lg border border-theme-border bg-theme-bg-card hover:bg-theme-bg-surface hover:border-theme-border-strong transition-all text-center group"
                    >
                        <Globe class="w-5 h-5 text-theme-text-secondary group-hover:text-theme-accent mb-1.5" />
                        <span class="text-xs font-semibold text-theme-text-primary">{{ __('Add VHost') }}</span>
                        <span class="text-[10px] text-theme-text-muted">{{ __('New Domain') }}</span>
                    </Link>

                    <Link
                        href="/databases"
                        class="flex flex-col items-center justify-center p-3.5 rounded-theme-lg border border-theme-border bg-theme-bg-card hover:bg-theme-bg-surface hover:border-theme-border-strong transition-all text-center group"
                    >
                        <Database class="w-5 h-5 text-theme-text-secondary group-hover:text-theme-accent mb-1.5" />
                        <span class="text-xs font-semibold text-theme-text-primary">{{ __('New MySQL DB') }}</span>
                        <span class="text-[10px] text-theme-text-muted">{{ __('+ User & Grants') }}</span>
                    </Link>

                    <Link
                        href="/files"
                        class="flex flex-col items-center justify-center p-3.5 rounded-theme-lg border border-theme-border bg-theme-bg-card hover:bg-theme-bg-surface hover:border-theme-border-strong transition-all text-center group"
                    >
                        <FolderTree class="w-5 h-5 text-theme-text-secondary group-hover:text-theme-accent mb-1.5" />
                        <span class="text-xs font-semibold text-theme-text-primary">{{ __('File Explorer') }}</span>
                        <span class="text-[10px] text-theme-text-muted">{{ __('Web Root Manager') }}</span>
                    </Link>

                    <Link
                        href="/php/manager"
                        class="flex flex-col items-center justify-center p-3.5 rounded-theme-lg border border-theme-border bg-theme-bg-card hover:bg-theme-bg-surface hover:border-theme-border-strong transition-all text-center group"
                    >
                        <Sliders class="w-5 h-5 text-theme-text-secondary group-hover:text-theme-accent mb-1.5" />
                        <span class="text-xs font-semibold text-theme-text-primary">{{ __('MultiPHP Engine') }}</span>
                        <span class="text-[10px] text-theme-text-muted">{{ __('LSAPI Runtimes') }}</span>
                    </Link>

                    <Link
                        href="/security/ssl"
                        class="flex flex-col items-center justify-center p-3.5 rounded-theme-lg border border-theme-border bg-theme-bg-card hover:bg-theme-bg-surface hover:border-theme-border-strong transition-all text-center group"
                    >
                        <Shield class="w-5 h-5 text-theme-text-secondary group-hover:text-theme-accent mb-1.5" />
                        <span class="text-xs font-semibold text-theme-text-primary">{{ __('SSL Certificates') }}</span>
                        <span class="text-[10px] text-theme-text-muted">{{ __('Let\'s Encrypt TLS') }}</span>
                    </Link>

                    <a
                        href="/phpmyadmin/"
                        target="_blank"
                        class="flex flex-col items-center justify-center p-3.5 rounded-theme-lg border border-theme-border bg-theme-bg-card hover:bg-theme-bg-surface hover:border-theme-border-strong transition-all text-center group"
                    >
                        <ArrowUpRight class="w-5 h-5 text-theme-text-secondary group-hover:text-theme-accent mb-1.5" />
                        <span class="text-xs font-semibold text-theme-text-primary">{{ __('phpMyAdmin') }}</span>
                        <span class="text-[10px] text-theme-text-muted">{{ __('SQL Web Portal') }}</span>
                    </a>
                </div>
            </div>

            <!-- Right Column (5 cols): Monospace Network & System Specifications Card -->
            <div class="xl:col-span-5 bg-theme-bg-card border border-theme-border rounded-theme-lg p-4 shadow-xs">
                <div class="flex items-center justify-between pb-3 border-b border-theme-border-subtle mb-3">
                    <div class="flex items-center gap-2">
                        <Server class="w-4 h-4 text-theme-accent" />
                        <h3 class="text-base text-theme-text-primary font-semibold">
                            {{ __('Network & System Specifications') }}
                        </h3>
                    </div>
                    <span class="font-mono text-xs text-theme-text-secondary font-medium">{{ __('Static Config') }}</span>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between p-2 rounded-theme-md bg-theme-bg-surface border border-theme-border">
                        <span class="text-[11px] font-semibold text-theme-text-secondary uppercase tracking-wider">{{ __('IPv4 Address') }}</span>
                        <span class="font-mono text-xs font-semibold text-theme-text-primary">{{ serverIp }}</span>
                    </div>
                    <div class="flex items-center justify-between p-2 rounded-theme-md bg-theme-bg-surface border border-theme-border">
                        <span class="text-[11px] font-semibold text-theme-text-secondary uppercase tracking-wider">{{ __('Web Server') }}</span>
                        <span class="font-mono text-xs font-semibold text-theme-text-primary">{{ olsVersion }}</span>
                    </div>
                    <div class="flex items-center justify-between p-2 rounded-theme-md bg-theme-bg-surface border border-theme-border">
                        <span class="text-[11px] font-semibold text-theme-text-secondary uppercase tracking-wider">{{ __('Operating System') }}</span>
                        <span class="font-mono text-xs font-semibold text-theme-text-primary">{{ osPretty }}</span>
                    </div>
                    <div class="flex items-center justify-between p-2 rounded-theme-md bg-theme-bg-surface border border-theme-border">
                        <span class="text-[11px] font-semibold text-theme-text-secondary uppercase tracking-wider">{{ __('Time Zone') }}</span>
                        <span class="font-mono text-xs font-semibold text-theme-text-primary">{{ timezone }}</span>
                    </div>
                    <div class="flex items-center justify-between p-2 rounded-theme-md bg-theme-bg-surface border border-theme-border">
                        <span class="text-[11px] font-semibold text-theme-text-secondary uppercase tracking-wider">{{ __('PHP Handler') }}</span>
                        <UiBadge variant="success">LiteSpeed LSAPI SuEXEC</UiBadge>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
