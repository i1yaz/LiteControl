<script setup>
import { shallowRef, onMounted } from 'vue';
import { FileCode, RefreshCw, X } from 'lucide-vue-next';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close']);

const conf = shallowRef('');
const loading = shallowRef(false);

const loadConf = async () => {
    loading.value = true;
    try {
        const res = await fetch(`/domains/${props.domain.id}/vhost-conf`);
        const json = await res.json();
        if (json.status === 'success') {
            conf.value = json.conf || '';
        }
    } catch (e) {
        console.error('Failed to load vHost conf', e);
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    loadConf();
});
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-xl p-5 shadow-sm space-y-4">
        <!-- Header matching Screenshot 2026-11-09 132449.png -->
        <div class="flex items-center justify-between border-b border-theme-border pb-3">
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary flex items-center gap-2">
                    <FileCode class="w-4 h-4 text-blue-500" />
                    <span>Virtual Host Configuration</span>
                </h3>
                <span class="text-[11px] text-theme-text-secondary font-mono mt-0.5 block">
                    {{ domain.domain }}
                </span>
            </div>

            <div class="flex items-center gap-2">
                <button
                    @click="loadConf"
                    :disabled="loading"
                    class="p-1.5 rounded-lg bg-theme-bg-surface hover:bg-theme-border text-theme-text-secondary hover:text-theme-text-primary border border-theme-border transition-colors text-xs flex items-center gap-1.5"
                    title="Reload Configuration"
                >
                    <RefreshCw class="w-3.5 h-3.5" :class="{ 'animate-spin': loading }" />
                </button>

                <button
                    @click="emit('close')"
                    class="px-3 py-1.5 rounded-lg bg-theme-bg-surface hover:bg-theme-border text-xs font-semibold text-theme-text-secondary hover:text-theme-text-primary border border-theme-border transition-colors"
                >
                    Close
                </button>
            </div>
        </div>

        <div class="p-2.5 bg-emerald-500/10 border border-emerald-500/20 rounded-lg text-xs text-emerald-400">
            Current configuration in the file fetched. (Read-Only)
        </div>

        <!-- Read Only Directive Box -->
        <div class="relative">
            <textarea
                :value="conf"
                readonly
                rows="16"
                class="w-full p-4 bg-slate-950 border border-slate-800 rounded-lg font-mono text-xs text-slate-200 leading-relaxed focus:outline-none resize-y selection:bg-blue-600/30"
                spellcheck="false"
            ></textarea>
        </div>
    </div>
</template>

