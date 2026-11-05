<script setup>
import { shallowRef } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PanelLayout from '@/Layouts/PanelLayout.vue';
import { FileText, RefreshCw, Terminal, Filter } from 'lucide-vue-next';

const props = defineProps({
    logType: String,
    lines: Number,
    logFile: String,
    content: String,
});

const currentType = shallowRef(props.logType);
const currentLines = shallowRef(props.lines);
const searchQuery = shallowRef('');

const fetchLogs = (type, lines) => {
    currentType.value = type;
    currentLines.value = lines;
    router.get('/configuration/error-logs', { type, lines }, { preserveScroll: true });
};

const refresh = () => {
    fetchLogs(currentType.value, currentLines.value);
};
</script>

<template>
    <PanelLayout>
        <Head title="Error Logs" />

        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <FileText class="w-6 h-6 text-sky-400" />
                        <span>System & Error Logs</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Inspect live server runtime logs from OpenLiteSpeed and LiteControl daemon.
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        @click="refresh"
                        class="p-2 rounded-xl bg-slate-900 border border-slate-800 text-slate-300 hover:text-white hover:bg-slate-800 transition-colors"
                        title="Refresh Log"
                    >
                        <RefreshCw class="w-4 h-4" />
                    </button>
                </div>
            </div>

            <!-- Controls Card -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 sm:p-5 flex flex-wrap items-center justify-between gap-4">
                <!-- Log Type Tabs -->
                <div class="flex items-center gap-1.5 p-1 bg-slate-950 border border-slate-800 rounded-xl">
                    <button
                        @click="fetchLogs('ols-error', currentLines)"
                        :class="[
                            'px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors',
                            currentType === 'ols-error' ? 'bg-sky-600 text-white' : 'text-slate-400 hover:text-white'
                        ]"
                    >
                        OLS Error Log
                    </button>
                    <button
                        @click="fetchLogs('ols-access', currentLines)"
                        :class="[
                            'px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors',
                            currentType === 'ols-access' ? 'bg-sky-600 text-white' : 'text-slate-400 hover:text-white'
                        ]"
                    >
                        OLS Access Log
                    </button>
                    <button
                        @click="fetchLogs('panel-error', currentLines)"
                        :class="[
                            'px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors',
                            currentType === 'panel-error' ? 'bg-sky-600 text-white' : 'text-slate-400 hover:text-white'
                        ]"
                    >
                        LiteControl Log
                    </button>
                </div>

                <!-- Lines selector -->
                <div class="flex items-center gap-3">
                    <span class="text-xs text-slate-400">Tail:</span>
                    <select
                        :value="currentLines"
                        @change="fetchLogs(currentType, $event.target.value)"
                        class="bg-slate-950 border border-slate-800 text-xs text-white rounded-lg px-2.5 py-1.5 focus:outline-none"
                    >
                        <option :value="50">50 lines</option>
                        <option :value="100">100 lines</option>
                        <option :value="250">250 lines</option>
                        <option :value="500">500 lines</option>
                        <option :value="1000">1000 lines</option>
                    </select>
                </div>
            </div>

            <!-- Terminal Output Card -->
            <div class="bg-slate-950 border border-slate-800 rounded-2xl overflow-hidden shadow-2xl">
                <div class="bg-slate-900/90 border-b border-slate-800/80 px-4 py-3 flex items-center justify-between">
                    <div class="flex items-center gap-2 text-xs font-mono text-slate-400">
                        <Terminal class="w-4 h-4 text-emerald-400" />
                        <span>{{ logFile || 'System Log Stream' }}</span>
                    </div>
                </div>

                <div class="p-4 sm:p-6 overflow-x-auto max-h-[600px] scrollbar-thin scrollbar-thumb-slate-800 font-mono text-xs text-slate-300 leading-relaxed whitespace-pre-wrap select-text">
                    {{ content || 'No log entries recorded.' }}
                </div>
            </div>
        </div>
    </PanelLayout>
</template>

