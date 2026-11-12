<script setup>
import { shallowRef, ref, onMounted } from 'vue';
import { FileCode, RefreshCw, Save, AlertTriangle, CheckCircle2 } from '@lucide/vue';
import axios from 'axios';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close']);

const conf = ref('');
const initialConf = ref('');
const loading = ref(false);
const saving = ref(false);
const errorMsg = ref('');
const successMsg = ref('');

const loadConf = async () => {
    loading.value = true;
    errorMsg.value = '';
    successMsg.value = '';
    try {
        const res = await axios.get(`/domains/${props.domain.id}/vhost-conf`);
        if (res.data.status === 'success') {
            conf.value = res.data.conf || '';
            initialConf.value = res.data.conf || '';
        }
    } catch (e) {
        errorMsg.value = e.response?.data?.message || 'Failed to load VirtualHost configuration.';
    } finally {
        loading.value = false;
    }
};

const saveConf = async () => {
    saving.value = true;
    errorMsg.value = '';
    successMsg.value = '';
    try {
        const res = await axios.post(`/domains/${props.domain.id}/vhost-conf`, {
            conf: conf.value,
        });
        if (res.data.status === 'success') {
            successMsg.value = res.data.message || 'Configuration syntax verified and applied successfully!';
            initialConf.value = conf.value;
        }
    } catch (e) {
        errorMsg.value = e.response?.data?.message || 'Validation failed. Changes were rolled back automatically.';
    } finally {
        saving.value = false;
    }
};

onMounted(() => {
    loadConf();
});
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-xl p-5 shadow-sm space-y-4">
        <!-- Header -->
        <div class="flex items-center justify-between border-b border-theme-border pb-3">
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary flex items-center gap-2">
                    <FileCode class="w-4 h-4 text-blue-500" />
                    <span>Virtual Host Configuration</span>
                </h3>
                <span class="text-[11px] text-theme-text-secondary font-mono mt-0.5 block">
                    {{ domain.domain }} (vhconf.conf)
                </span>
            </div>

            <div class="flex items-center gap-2">
                <button
                    @click="loadConf"
                    :disabled="loading || saving"
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

        <!-- Status notice / info banner -->
        <div class="p-3 bg-blue-500/10 border border-blue-500/20 rounded-lg text-xs text-blue-300 flex items-start gap-2">
            <CheckCircle2 class="w-4 h-4 text-blue-400 shrink-0 mt-0.5" />
            <div class="leading-relaxed">
                <span class="font-semibold">Syntax Validation & Auto-Rollback Guard:</span>
                Changes are tested with <code class="px-1 py-0.5 rounded bg-blue-900/40 text-blue-200 font-mono">openlitespeed -t</code> before reloading. If syntax errors occur, changes are automatically aborted with zero downtime.
            </div>
        </div>

        <!-- Success Message -->
        <div v-if="successMsg" class="p-3 bg-emerald-500/10 border border-emerald-500/30 rounded-lg text-xs text-emerald-400 flex items-center gap-2">
            <CheckCircle2 class="w-4 h-4 shrink-0" />
            <span>{{ successMsg }}</span>
        </div>

        <!-- Error Message -->
        <div v-if="errorMsg" class="p-3 bg-rose-500/10 border border-rose-500/30 rounded-lg text-xs text-rose-300 flex items-start gap-2 whitespace-pre-wrap font-mono">
            <AlertTriangle class="w-4 h-4 text-rose-400 shrink-0 mt-0.5" />
            <div class="leading-relaxed">{{ errorMsg }}</div>
        </div>

        <!-- Editable Directive Box -->
        <div class="space-y-3">
            <textarea
                v-model="conf"
                :disabled="loading || saving"
                rows="16"
                class="w-full p-4 bg-slate-950 border border-slate-800 focus:border-blue-500 rounded-lg font-mono text-xs text-slate-200 leading-relaxed focus:outline-none resize-y selection:bg-blue-600/30 disabled:opacity-60 transition-colors"
                placeholder="# Loading VirtualHost directives..."
                spellcheck="false"
            ></textarea>

            <div class="flex items-center justify-between">
                <span class="text-[11px] text-theme-text-muted">
                    Directives apply to OpenLiteSpeed vHost configuration.
                </span>

                <div class="flex items-center gap-2">
                    <button
                        v-if="conf !== initialConf"
                        @click="conf = initialConf"
                        :disabled="saving"
                        class="px-3 py-2 rounded-lg bg-theme-bg-surface hover:bg-theme-border text-theme-text-secondary text-xs font-medium border border-theme-border transition-colors"
                    >
                        Reset Changes
                    </button>

                    <button
                        @click="saveConf"
                        :disabled="saving || loading || !conf"
                        class="inline-flex items-center gap-1.5 px-5 py-2 bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold rounded-lg shadow-sm shadow-blue-600/30 transition-all disabled:opacity-50"
                    >
                        <Save v-if="!saving" class="w-3.5 h-3.5" />
                        <RefreshCw v-else class="w-3.5 h-3.5 animate-spin" />
                        <span>{{ saving ? 'Testing & Applying...' : 'Save & Reload' }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

