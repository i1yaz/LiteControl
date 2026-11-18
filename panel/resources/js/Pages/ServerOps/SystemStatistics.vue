<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Activity, RefreshCw, Cpu, HardDrive, Server } from '@lucide/vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiCard from '@shared/Ui/UiCard.vue';

const props = defineProps({
    stats: Object,
});

const refresh = () => {
    router.reload({ only: ['stats'] });
};
</script>

<template>
    <Head :title="__('System Statistics')" />

    <div class="max-w-5xl mx-auto space-y-6">
        <UiPageHeader
            :title="__('System Statistics')"
            subtitle="Real-time hardware utilization metrics and operating system details."
        >
            <template #icon>
                <Activity class="w-6 h-6 text-theme-accent" />
            </template>
            <template #actions>
                <UiButton
                    variant="secondary"
                    @click="refresh"
                    :title="__('Refresh Stats')"
                >
                    <RefreshCw class="w-4 h-4" />
                </UiButton>
            </template>
        </UiPageHeader>

        <!-- Resource Progress Bars Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
            <!-- CPU -->
            <UiCard class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-theme-text-secondary uppercase tracking-wider">{{ __('CPU Usage') }}</span>
                    <Cpu class="w-4 h-4 text-theme-info" />
                </div>
                <div class="flex items-baseline justify-between">
                    <span class="text-3xl font-extrabold text-theme-text-primary">{{ stats.cpu_percent || 0 }}%</span>
                    <span class="text-xs text-theme-text-secondary">{{ stats.cpu_cores || 1 }} Cores</span>
                </div>
                <div class="w-full h-2 rounded-full bg-theme-bg-surface overflow-hidden">
                    <div
                        class="h-full bg-theme-info rounded-full transition-all duration-500"
                        :style="{ width: `${Math.min(stats.cpu_percent || 0, 100)}%` }"
                    ></div>
                </div>
            </UiCard>

            <!-- RAM -->
            <UiCard class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-theme-text-secondary uppercase tracking-wider">{{ __('Memory (RAM)') }}</span>
                    <Server class="w-4 h-4 text-theme-accent" />
                </div>
                <div class="flex items-baseline justify-between">
                    <span class="text-3xl font-extrabold text-theme-text-primary">{{ stats.ram_percent || 0 }}%</span>
                    <span class="text-xs text-theme-text-secondary">{{ stats.ram_used_mb || 0 }} / {{ stats.ram_total_mb || 0 }} MB</span>
                </div>
                <div class="w-full h-2 rounded-full bg-theme-bg-surface overflow-hidden">
                    <div
                        class="h-full bg-theme-accent rounded-full transition-all duration-500"
                        :style="{ width: `${Math.min(stats.ram_percent || 0, 100)}%` }"
                    ></div>
                </div>
            </UiCard>

            <!-- Disk -->
            <UiCard class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-theme-text-secondary uppercase tracking-wider">{{ __('Primary Disk') }}</span>
                    <HardDrive class="w-4 h-4 text-theme-success" />
                </div>
                <div class="flex items-baseline justify-between">
                    <span class="text-3xl font-extrabold text-theme-text-primary">{{ stats.disk_percent || 0 }}%</span>
                    <span class="text-xs text-theme-text-secondary">{{ stats.disk_used_gb || 0 }} / {{ stats.disk_total_gb || 0 }} GB</span>
                </div>
                <div class="w-full h-2 rounded-full bg-theme-bg-surface overflow-hidden">
                    <div
                        class="h-full bg-theme-success rounded-full transition-all duration-500"
                        :style="{ width: `${Math.min(stats.disk_percent || 0, 100)}%` }"
                    ></div>
                </div>
            </UiCard>
        </div>

        <!-- Hardware & Software Specs Card -->
        <UiCard
            :title="__('System Environment')"
            :subtitle="__('Operating system, kernel version, and core service runtimes.')"
        >
            <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 text-xs">
                <div>
                    <dt class="text-theme-text-secondary font-medium">{{ __('Operating System') }}</dt>
                    <dd class="text-theme-text-primary font-semibold mt-1">{{ stats.os || stats.os_pretty || 'Ubuntu Linux' }}</dd>
                </div>

                <div>
                    <dt class="text-theme-text-secondary font-medium">{{ __('Linux Kernel') }}</dt>
                    <dd class="text-theme-text-primary font-mono mt-1">{{ stats.kernel || '6.8.0-generic' }}</dd>
                </div>

                <div>
                    <dt class="text-theme-text-secondary font-medium">{{ __('Web Server') }}</dt>
                    <dd class="text-theme-success-text font-semibold mt-1">{{ stats.ols_version || 'OpenLiteSpeed 1.8.2' }}</dd>
                </div>

                <div>
                    <dt class="text-theme-text-secondary font-medium">{{ __('System Uptime') }}</dt>
                    <dd class="text-theme-text-primary font-medium mt-1">{{ stats.uptime || stats.uptime_human || 'Unknown' }}</dd>
                </div>

                <div>
                    <dt class="text-theme-text-secondary font-medium">{{ __('Load Average') }}</dt>
                    <dd class="text-theme-text-primary font-mono mt-1">
                        {{ typeof stats.load_avg === 'object' ? `${stats.load_avg['1m']}, ${stats.load_avg['5m']}, ${stats.load_avg['15m']}` : (stats.load_avg || '0.10, 0.05, 0.01') }}
                    </dd>
                </div>

                <div>
                    <dt class="text-theme-text-secondary font-medium">{{ __('Hostname') }}</dt>
                    <dd class="text-theme-text-primary font-mono mt-1">{{ stats.hostname || 'localhost' }}</dd>
                </div>
            </dl>
        </UiCard>
    </div>
</template>
