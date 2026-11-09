<script setup>
import { shallowRef, onMounted, watch } from 'vue';
import { FileText, RefreshCw, ChevronLeft, ChevronRight, X } from 'lucide-vue-next';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
    actionProps: {
        type: Object,
        default: () => ({ logType: 'access', title: 'HTTP Access Log' }),
    },
});

const emit = defineEmits(['close']);

const logType = shallowRef(props.actionProps.logType || 'access');
const lines = shallowRef([]);
const page = shallowRef(1);
const hasNext = shallowRef(false);
const hasPrev = shallowRef(false);
const loading = shallowRef(false);

const loadLogs = async (pageNum = 1) => {
    loading.value = true;
    try {
        const res = await fetch(`/domains/${props.domain.id}/logs?type=${logType.value}&page=${pageNum}&per_page=50`);
        const json = await res.json();
        if (json.status === 'success') {
            lines.value = json.lines || [];
            page.value = json.page || 1;
            hasNext.value = !!json.has_next;
            hasPrev.value = !!json.has_prev;
        }
    } catch (e) {
        console.error('Failed to load log stream', e);
    } finally {
        loading.value = false;
    }
};

watch(() => props.actionProps.logType, (newType) => {
    if (newType) {
        logType.value = newType;
        loadLogs(1);
    }
});

onMounted(() => {
    loadLogs(1);
});
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-xl p-5 shadow-sm space-y-4">
        <div class="flex items-center justify-between border-b border-theme-border pb-3">
            <div class="flex items-center gap-2">
                <FileText class="w-4 h-4 text-cyan-400" />
                <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary">
                    {{ actionProps.title || (logType === 'error' ? 'Web Server Error Log' : 'HTTP Access Log') }}
                </h3>
                <span class="text-[11px] text-theme-text-muted font-mono">
                    (Page {{ page }})
                </span>
            </div>

            <div class="flex items-center gap-2">
                <button
                    @click="loadLogs(page)"
                    :disabled="loading"
                    class="p-1.5 rounded-lg bg-theme-bg-surface hover:bg-theme-border text-theme-text-secondary hover:text-theme-text-primary border border-theme-border transition-colors text-xs flex items-center gap-1.5"
                    title="Refresh Log Stream"
                >
                    <RefreshCw class="w-3.5 h-3.5" :class="{ 'animate-spin': loading }" />
                    <span class="hidden sm:inline">Refresh</span>
                </button>
                <button @click="emit('close')" class="text-theme-text-muted hover:text-theme-text-primary p-1">
                    <X class="w-4 h-4" />
                </button>
            </div>
        </div>

        <!-- Monospace Console Log Terminal -->
        <div class="bg-slate-950 border border-slate-800 rounded-lg p-3 font-mono text-[11px] text-slate-300 h-96 overflow-y-auto leading-relaxed divide-y divide-slate-900/60">
            <div v-if="loading && lines.length === 0" class="flex items-center justify-center h-full text-slate-500">
                Loading log stream...
            </div>
            <div v-else-if="lines.length === 0" class="flex items-center justify-center h-full text-slate-500 italic">
                Log stream is currently empty.
            </div>
            <div
                v-else
                v-for="(line, idx) in lines"
                :key="idx"
                class="py-1 hover:bg-slate-900/70 whitespace-pre-wrap break-all"
            >
                {{ line }}
            </div>
        </div>

        <!-- Previous / Next Controls -->
        <div class="flex items-center justify-between pt-1">
            <span class="text-xs text-theme-text-muted">
                Showing newest entries first • 50 lines / page
            </span>

            <div class="flex items-center gap-2">
                <button
                    @click="loadLogs(page - 1)"
                    :disabled="!hasPrev || loading"
                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-theme-bg-surface border border-theme-border text-xs font-semibold text-theme-text-secondary hover:text-theme-text-primary disabled:opacity-40 transition-colors"
                >
                    <ChevronLeft class="w-3.5 h-3.5" />
                    <span>Previous</span>
                </button>

                <button
                    @click="loadLogs(page + 1)"
                    :disabled="!hasNext || loading"
                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-theme-bg-surface border border-theme-border text-xs font-semibold text-theme-text-secondary hover:text-theme-text-primary disabled:opacity-40 transition-colors"
                >
                    <span>Next</span>
                    <ChevronRight class="w-3.5 h-3.5" />
                </button>
            </div>
        </div>
    </div>
</template>

