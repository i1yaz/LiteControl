<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useDashboardMetrics } from '@domains/ServerOps/Composables/useDashboardMetrics';
import UiCard from '@shared/Ui/UiCard.vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import {
    Cpu, HardDrive, Globe, Database, Package,
    Clock, Zap, ArrowUpRight, Users, Server
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
        <UiPageHeader
            :title="__('System Dashboard')"
            :subtitle="`Node ${hostname} (${serverIp}) // ${olsVersion}`"
        />

        <!-- Primary Hardware Matrix -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <UiCard :title="__('CPU Processing')" :subtitle="`${metrics?.cpu_cores ?? 1} Physical Cores`">
                <div class="flex items-baseline justify-between mb-2">
                    <span class="text-xs text-slate-400">{{ __('Load') }}</span>
                    <span class="text-2xl font-bold">{{ metrics?.cpu_percent ?? 0 }}%</span>
                </div>
                <div class="h-2 w-full bg-slate-800 rounded overflow-hidden">
                    <div class="h-full bg-sky-500 transition-all duration-500" :style="{ width: `${cpuPercent}%` }"></div>
                </div>
                <p class="mt-2 text-xs text-slate-400">Load average: {{ loadAvg['1m'] }} / {{ loadAvg['5m'] }} / {{ loadAvg['15m'] }}</p>
            </UiCard>

            <UiCard :title="__('Memory Allocation')" :subtitle="`${ramUsedGb} GB / ${ramTotalGb} GB`">
                <div class="flex items-baseline justify-between mb-2">
                    <span class="text-xs text-slate-400">{{ __('Active RAM') }}</span>
                    <span class="text-2xl font-bold">{{ metrics?.ram_percent ?? 0 }}%</span>
                </div>
                <div class="h-2 w-full bg-slate-800 rounded overflow-hidden">
                    <div class="h-full bg-emerald-500 transition-all duration-500" :style="{ width: `${ramPercent}%` }"></div>
                </div>
                <p class="mt-2 text-xs text-slate-400">Uptime: {{ uptime }}</p>
            </UiCard>

            <UiCard :title="__('Storage Space')" :subtitle="`${metrics?.disk_used_gb ?? 0} GB / ${metrics?.disk_total_gb ?? 0} GB`">
                <div class="flex items-baseline justify-between mb-2">
                    <span class="text-xs text-slate-400">{{ __('Disk Pool') }}</span>
                    <span class="text-2xl font-bold">{{ metrics?.disk_percent ?? 0 }}%</span>
                </div>
                <div class="h-2 w-full bg-slate-800 rounded overflow-hidden">
                    <div class="h-full bg-indigo-500 transition-all duration-500" :style="{ width: `${diskPercent}%` }"></div>
                </div>
                <p class="mt-2 text-xs text-slate-400">{{ __('Root filesystem') }}</p>
            </UiCard>
        </div>

        <!-- Inventory Badges (Admin) -->
        <div v-if="adminRole && counts" class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <UiCard>
                <div class="flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-400">{{ __('Users') }}</span>
                        <span class="text-xl font-bold block">{{ counts.total_users ?? 0 }}</span>
                    </div>
                    <Users class="w-5 h-5 text-sky-400" />
                </div>
            </UiCard>

            <UiCard>
                <div class="flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-400">{{ __('Virtual Hosts') }}</span>
                        <span class="text-xl font-bold block">{{ counts.total_domains ?? 0 }}</span>
                    </div>
                    <Globe class="w-5 h-5 text-emerald-400" />
                </div>
            </UiCard>

            <UiCard>
                <div class="flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-400">{{ __('Databases') }}</span>
                        <span class="text-xl font-bold block">{{ counts.total_databases ?? 0 }}</span>
                    </div>
                    <Database class="w-5 h-5 text-indigo-400" />
                </div>
            </UiCard>

            <UiCard>
                <div class="flex items-center justify-between">
                    <div>
                        <span class="text-xs text-slate-400">{{ __('Packages') }}</span>
                        <span class="text-xl font-bold block">{{ counts.total_packages ?? 0 }}</span>
                    </div>
                    <Package class="w-5 h-5 text-amber-400" />
                </div>
            </UiCard>
        </div>
    </div>
</template>
