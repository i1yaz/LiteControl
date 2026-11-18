<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useDashboardMetrics } from '@domains/ServerOps/Composables/useDashboardMetrics';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import {
    Cpu, HardDrive, Globe, Database, Package,
    Zap, Server, ArrowUpRight, Users, CheckCircle2,
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
    <Head :title="__('Dashboard - LiteControl')" />

    <div class="space-y-6">
        <!-- Welcome & System Health Banner -->
        <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-6 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <UiBadge variant="success">
                        <CheckCircle2 class="w-3.5 h-3.5" /> All Services Operational
                    </UiBadge>
                    <span class="text-theme-text-muted text-xs">•</span>
                    <span class="text-xs text-theme-text-secondary">Uptime: {{ uptime }}</span>
                </div>
                <h1 class="text-2xl font-bold text-theme-text-primary tracking-tight">
                    Server Overview
                </h1>
                <p class="text-xs text-theme-text-secondary mt-1">
                    Node <span class="text-theme-text-primary font-semibold">{{ hostname }}</span> ({{ serverIp }}) running {{ olsVersion }}
                </p>
            </div>

            <div class="flex items-center gap-3">
                <Link href="/domains">
                    <UiButton variant="primary" size="sm">
                        <Globe class="w-3.5 h-3.5" /> Add Domain
                    </UiButton>
                </Link>
                <Link href="/databases">
                    <UiButton variant="secondary" size="sm">
                        <Database class="w-3.5 h-3.5" /> Databases
                    </UiButton>
                </Link>
            </div>
        </div>

        <!-- 3 Core Resource Gauges -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <!-- CPU Load Card -->
            <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-5 shadow-xs">
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-theme-border-subtle">
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-theme-md bg-theme-accent-subtle text-theme-accent-text">
                            <Cpu class="w-5 h-5" />
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-theme-text-primary">{{ __('CPU Processing') }}</h3>
                            <span class="text-[11px] text-theme-text-secondary">{{ metrics?.cpu_cores ?? 1 }} vCPU Cores</span>
                        </div>
                    </div>
                    <span class="text-2xl font-bold text-theme-text-primary">{{ metrics?.cpu_percent ?? 0 }}%</span>
                </div>
                <div class="space-y-2">
                    <div class="h-2.5 w-full bg-theme-bg-surface rounded-full overflow-hidden">
                        <div
                            class="h-full bg-theme-accent rounded-full transition-all duration-500"
                            :style="{ width: `${cpuPercent}%` }"
                        ></div>
                    </div>
                    <div class="flex justify-between text-xs text-theme-text-secondary">
                        <span>Load Avg: {{ loadAvg['1m'] }} / {{ loadAvg['5m'] }} / {{ loadAvg['15m'] }}</span>
                    </div>
                </div>
            </div>

            <!-- Memory Card -->
            <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-5 shadow-xs">
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-theme-border-subtle">
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-theme-md bg-theme-success-bg text-theme-success-text">
                            <Server class="w-5 h-5" />
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-theme-text-primary">{{ __('System Memory') }}</h3>
                            <span class="text-[11px] text-theme-text-secondary">{{ ramUsedGb }} GB / {{ ramTotalGb }} GB</span>
                        </div>
                    </div>
                    <span class="text-2xl font-bold text-theme-text-primary">{{ metrics?.ram_percent ?? 0 }}%</span>
                </div>
                <div class="space-y-2">
                    <div class="h-2.5 w-full bg-theme-bg-surface rounded-full overflow-hidden">
                        <div
                            class="h-full bg-theme-success rounded-full transition-all duration-500"
                            :style="{ width: `${ramPercent}%` }"
                        ></div>
                    </div>
                    <div class="flex justify-between text-xs text-theme-text-secondary">
                        <span>{{ __('Dynamic Memory Pool') }}</span>
                        <span>{{ ramPercent }}% Active</span>
                    </div>
                </div>
            </div>

            <!-- Storage Card -->
            <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-5 shadow-xs">
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-theme-border-subtle">
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-theme-md bg-theme-info-bg text-theme-info-text">
                            <HardDrive class="w-5 h-5" />
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-theme-text-primary">{{ __('Disk Storage') }}</h3>
                            <span class="text-[11px] text-theme-text-secondary">{{ metrics?.disk_used_gb ?? 0 }} GB / {{ metrics?.disk_total_gb ?? 0 }} GB</span>
                        </div>
                    </div>
                    <span class="text-2xl font-bold text-theme-text-primary">{{ metrics?.disk_percent ?? 0 }}%</span>
                </div>
                <div class="space-y-2">
                    <div class="h-2.5 w-full bg-theme-bg-surface rounded-full overflow-hidden">
                        <div
                            class="h-full bg-theme-info rounded-full transition-all duration-500"
                            :style="{ width: `${diskPercent}%` }"
                        ></div>
                    </div>
                    <div class="flex justify-between text-xs text-theme-text-secondary">
                        <span>{{ __('NVMe Primary Partition') }}</span>
                        <span>{{ __('ext4') }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inventory Summary Badges (Admin) -->
        <div v-if="adminRole && counts" class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <Link href="/users" class="p-4 bg-theme-bg-card border border-theme-border rounded-theme-lg hover:border-theme-border-strong transition-colors flex items-center justify-between group">
                <div>
                    <span class="text-xs text-theme-text-secondary font-medium">{{ __('User Accounts') }}</span>
                    <span class="text-xl font-bold text-theme-text-primary mt-0.5 block">{{ counts.total_users ?? 0 }}</span>
                </div>
                <Users class="w-5 h-5 text-theme-accent group-hover:scale-110 transition-transform" />
            </Link>

            <Link href="/domains" class="p-4 bg-theme-bg-card border border-theme-border rounded-theme-lg hover:border-theme-border-strong transition-colors flex items-center justify-between group">
                <div>
                    <span class="text-xs text-theme-text-secondary font-medium">{{ __('Hosted Domains') }}</span>
                    <span class="text-xl font-bold text-theme-text-primary mt-0.5 block">{{ counts.total_domains ?? 0 }}</span>
                </div>
                <Globe class="w-5 h-5 text-theme-success-text group-hover:scale-110 transition-transform" />
            </Link>

            <Link href="/databases" class="p-4 bg-theme-bg-card border border-theme-border rounded-theme-lg hover:border-theme-border-strong transition-colors flex items-center justify-between group">
                <div>
                    <span class="text-xs text-theme-text-secondary font-medium">{{ __('Databases') }}</span>
                    <span class="text-xl font-bold text-theme-text-primary mt-0.5 block">{{ counts.total_databases ?? 0 }}</span>
                </div>
                <Database class="w-5 h-5 text-theme-info-text group-hover:scale-110 transition-transform" />
            </Link>

            <Link href="/packages" class="p-4 bg-theme-bg-card border border-theme-border rounded-theme-lg hover:border-theme-border-strong transition-colors flex items-center justify-between group">
                <div>
                    <span class="text-xs text-theme-text-secondary font-medium">{{ __('Hosting Packages') }}</span>
                    <span class="text-xl font-bold text-theme-text-primary mt-0.5 block">{{ counts.total_packages ?? 0 }}</span>
                </div>
                <Package class="w-5 h-5 text-theme-warning-text group-hover:scale-110 transition-transform" />
            </Link>
        </div>

        <!-- Quick Access Services & Specifications Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
            <!-- System Details Card -->
            <div class="lg:col-span-7 bg-theme-bg-card border border-theme-border rounded-theme-lg p-6 shadow-xs">
                <h3 class="text-base font-semibold text-theme-text-primary mb-4 pb-3 border-b border-theme-border-subtle flex items-center gap-2">
                    <Server class="w-4 h-4 text-theme-accent" /> System Specifications
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div class="p-3 rounded-theme-md bg-theme-bg-surface border border-theme-border">
                        <span class="text-theme-text-secondary block mb-0.5">{{ __('Web Server') }}</span>
                        <span class="text-theme-text-primary font-medium">{{ olsVersion }}</span>
                    </div>
                    <div class="p-3 rounded-theme-md bg-theme-bg-surface border border-theme-border">
                        <span class="text-theme-text-secondary block mb-0.5">{{ __('Operating System') }}</span>
                        <span class="text-theme-text-primary font-medium">{{ osPretty }}</span>
                    </div>
                    <div class="p-3 rounded-theme-md bg-theme-bg-surface border border-theme-border">
                        <span class="text-theme-text-secondary block mb-0.5">{{ __('Time Zone') }}</span>
                        <span class="text-theme-text-primary font-medium">{{ timezone }}</span>
                    </div>
                    <div class="p-3 rounded-theme-md bg-theme-bg-surface border border-theme-border">
                        <span class="text-theme-text-secondary block mb-0.5">{{ __('PHP Engine') }}</span>
                        <span class="text-theme-success-text font-medium">{{ __('LiteSpeed LSAPI') }}</span>
                    </div>
                </div>
            </div>

            <!-- Quick Service Portals -->
            <div class="lg:col-span-5 bg-theme-bg-card border border-theme-border rounded-theme-lg p-6 shadow-xs flex flex-col justify-between">
                <div>
                    <h3 class="text-base font-semibold text-theme-text-primary mb-4 pb-3 border-b border-theme-border-subtle flex items-center gap-2">
                        <Zap class="w-4 h-4 text-theme-warning-text" /> Direct Shortcuts
                    </h3>
                    <div class="space-y-2.5">
                        <a
                            href="/phpmyadmin/"
                            target="_blank"
                            class="flex items-center justify-between p-3 rounded-theme-md bg-theme-bg-surface hover:bg-theme-border border border-theme-border text-xs font-medium text-theme-text-primary transition-colors group"
                        >
                            <span class="flex items-center gap-2">
                                <Database class="w-4 h-4 text-theme-accent" /> phpMyAdmin Database Manager
                            </span>
                            <ArrowUpRight class="w-4 h-4 text-theme-text-muted group-hover:text-theme-text-primary" />
                        </a>
                        <Link
                            href="/files"
                            class="flex items-center justify-between p-3 rounded-theme-md bg-theme-bg-surface hover:bg-theme-border border border-theme-border text-xs font-medium text-theme-text-primary transition-colors group"
                        >
                            <span class="flex items-center gap-2">
                                <FolderTree class="w-4 h-4 text-theme-accent" /> Web File Explorer
                            </span>
                            <ArrowUpRight class="w-4 h-4 text-theme-text-muted group-hover:text-theme-text-primary" />
                        </Link>
                        <Link
                            href="/php/manager"
                            class="flex items-center justify-between p-3 rounded-theme-md bg-theme-bg-surface hover:bg-theme-border border border-theme-border text-xs font-medium text-theme-text-primary transition-colors group"
                        >
                            <span class="flex items-center gap-2">
                                <Sliders class="w-4 h-4 text-theme-success-text" /> MultiPHP Configuration
                            </span>
                            <ArrowUpRight class="w-4 h-4 text-theme-text-muted group-hover:text-theme-text-primary" />
                        </Link>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-theme-border-subtle text-[11px] text-theme-text-secondary flex items-center justify-between">
                    <span>{{ __('LiteControl Core') }}</span>
                    <span class="text-theme-success-text font-medium">{{ __('Ready') }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
