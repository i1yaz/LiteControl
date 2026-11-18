<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useDashboardMetrics } from '@domains/ServerOps/Composables/useDashboardMetrics';
import UiCard from '@shared/Ui/UiCard.vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiStatCard from '@shared/Ui/UiStatCard.vue';
import {
    Globe, Database, Package, Users
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
                    <span class="text-xs text-theme-text-secondary">{{ __('Load') }}</span>
                    <span class="text-2xl font-bold text-theme-text-primary">{{ metrics?.cpu_percent ?? 0 }}%</span>
                </div>
                <div class="h-2 w-full bg-theme-bg-surface rounded-theme-sm overflow-hidden">
                    <div class="h-full bg-theme-info transition-all duration-500" :style="{ width: `${cpuPercent}%` }"></div>
                </div>
                <p class="mt-2 text-xs text-theme-text-secondary">Load average: {{ loadAvg['1m'] }} / {{ loadAvg['5m'] }} / {{ loadAvg['15m'] }}</p>
            </UiCard>

            <UiCard :title="__('Memory Allocation')" :subtitle="`${ramUsedGb} GB / ${ramTotalGb} GB`">
                <div class="flex items-baseline justify-between mb-2">
                    <span class="text-xs text-theme-text-secondary">{{ __('Active RAM') }}</span>
                    <span class="text-2xl font-bold text-theme-text-primary">{{ metrics?.ram_percent ?? 0 }}%</span>
                </div>
                <div class="h-2 w-full bg-theme-bg-surface rounded-theme-sm overflow-hidden">
                    <div class="h-full bg-theme-success transition-all duration-500" :style="{ width: `${ramPercent}%` }"></div>
                </div>
                <p class="mt-2 text-xs text-theme-text-secondary">Uptime: {{ uptime }}</p>
            </UiCard>

            <UiCard :title="__('Storage Space')" :subtitle="`${metrics?.disk_used_gb ?? 0} GB / ${metrics?.disk_total_gb ?? 0} GB`">
                <div class="flex items-baseline justify-between mb-2">
                    <span class="text-xs text-theme-text-secondary">{{ __('Disk Pool') }}</span>
                    <span class="text-2xl font-bold text-theme-text-primary">{{ metrics?.disk_percent ?? 0 }}%</span>
                </div>
                <div class="h-2 w-full bg-theme-bg-surface rounded-theme-sm overflow-hidden">
                    <div class="h-full bg-theme-accent transition-all duration-500" :style="{ width: `${diskPercent}%` }"></div>
                </div>
                <p class="mt-2 text-xs text-theme-text-secondary">{{ __('Root filesystem') }}</p>
            </UiCard>
        </div>

        <!-- Inventory Badges (Admin) -->
        <div v-if="adminRole && counts" class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <UiStatCard :label="__('Users')" :value="counts.total_users ?? 0" variant="info">
                <template #icon>
                    <Users class="w-5 h-5" />
                </template>
            </UiStatCard>

            <UiStatCard :label="__('Virtual Hosts')" :value="counts.total_domains ?? 0" variant="success">
                <template #icon>
                    <Globe class="w-5 h-5" />
                </template>
            </UiStatCard>

            <UiStatCard :label="__('Databases')" :value="counts.total_databases ?? 0" variant="primary">
                <template #icon>
                    <Database class="w-5 h-5" />
                </template>
            </UiStatCard>

            <UiStatCard :label="__('Packages')" :value="counts.total_packages ?? 0" variant="warning">
                <template #icon>
                    <Package class="w-5 h-5" />
                </template>
            </UiStatCard>
        </div>
    </div>
</template>
