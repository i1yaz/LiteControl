<script setup lang="ts">
import { shallowRef } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { FileText, RefreshCw, Terminal } from '@lucide/vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiTabs from '@shared/Ui/UiTabs.vue';

const props = defineProps({
    logType: String,
    lines: Number,
    logFile: String,
    content: String,
});

const currentType = shallowRef(props.logType);
const currentLines = shallowRef(props.lines);
const searchQuery = shallowRef('');

const logTabs = [
    { id: 'ols-error', label: 'OLS Error Log' },
    { id: 'ols-access', label: 'OLS Access Log' },
    { id: 'panel-error', label: 'LiteControl Log' },
];

const fetchLogs = (type: string | undefined, lines: number | string | undefined) => {
    currentType.value = type;
    currentLines.value = Number(lines);
    router.get('/configuration/error-logs', { type, lines }, { preserveScroll: true });
};

const refresh = () => {
    fetchLogs(currentType.value, currentLines.value);
};
</script>

<template>
    <Head :title="__('Error Logs')" />

    <div class="space-y-6">
        <!-- Header -->
        <UiPageHeader
            :title="__('System & Error Logs')"
            subtitle="Inspect live server runtime logs from OpenLiteSpeed and LiteControl daemon."
        >
            <template #icon>
                <FileText class="w-6 h-6 text-theme-accent" />
            </template>
            <template #actions>
                <UiButton
                    variant="secondary"
                    size="sm"
                    @click="refresh"
                    :title="__('Refresh Log')"
                >
                    <RefreshCw class="w-4 h-4" />
                    <span>{{ __('Refresh') }}</span>
                </UiButton>
            </template>
        </UiPageHeader>

        <!-- Controls Card -->
        <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-4 sm:p-5 flex flex-wrap items-center justify-between gap-4 shadow-xs">
            <!-- Log Type Tabs -->
            <UiTabs
                :model-value="String(currentType || 'ols-error')"
                :tabs="logTabs"
                @select="(type: string) => fetchLogs(type, currentLines)"
                class="border-b-0 pb-0"
            />

            <!-- Lines selector -->
            <div class="flex items-center gap-3">
                <span class="text-xs text-theme-text-secondary">{{ __('Tail:') }}</span>
                <select
                    :value="currentLines"
                    @change="fetchLogs(currentType, ($event.target as HTMLSelectElement).value)"
                    class="bg-theme-bg-input border border-theme-border text-xs text-theme-text-primary rounded-theme-md px-2.5 py-1.5 focus:outline-none focus:border-theme-accent"
                >
                    <option :value="50">{{ __('50 lines') }}</option>
                    <option :value="100">{{ __('100 lines') }}</option>
                    <option :value="250">{{ __('250 lines') }}</option>
                    <option :value="500">{{ __('500 lines') }}</option>
                    <option :value="1000">{{ __('1000 lines') }}</option>
                </select>
            </div>
        </div>

        <!-- Terminal Output Card -->
        <div class="bg-theme-bg-input border border-theme-border rounded-theme-lg overflow-hidden shadow-xs">
            <div class="bg-theme-bg-surface border-b border-theme-border px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-2 text-xs font-mono text-theme-text-secondary">
                    <Terminal class="w-4 h-4 text-theme-success" />
                    <span>{{ logFile || 'System Log Stream' }}</span>
                </div>
            </div>

            <div class="p-4 sm:p-6 overflow-x-auto max-h-[600px] font-mono text-xs text-theme-text-primary leading-relaxed whitespace-pre-wrap select-text">
                {{ content || 'No log entries recorded.' }}
            </div>
        </div>
    </div>
</template>


