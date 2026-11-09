<script setup>
import { useForm } from '@inertiajs/vue3';
import { ShieldCheck, RefreshCw, X, AlertCircle } from 'lucide-vue-next';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close']);

const form = useForm({
    domain_id: props.domain.id,
});

const handleIssue = () => {
    form.post('/security/ssl/issue', {
        preserveScroll: true,
    });
};
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-xl p-5 shadow-sm space-y-5">
        <div class="flex items-center justify-between border-b border-theme-border pb-3">
            <div class="flex items-center gap-2">
                <ShieldCheck class="w-4 h-4 text-emerald-400" />
                <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary">
                    Issue / Renew Let's Encrypt SSL
                </h3>
            </div>

            <button @click="emit('close')" class="text-theme-text-muted hover:text-theme-text-primary p-1">
                <X class="w-4 h-4" />
            </button>
        </div>

        <div class="max-w-xl space-y-4">
            <div class="p-3 bg-blue-500/10 border border-blue-500/20 rounded-lg text-xs text-blue-300 flex items-start gap-2.5">
                <AlertCircle class="w-4 h-4 shrink-0 text-blue-400 mt-0.5" />
                <div>
                    Let's Encrypt automated HTTP-01 challenge will be verified against
                    <strong class="text-white">{{ domain.domain }}</strong> and
                    <strong class="text-white">www.{{ domain.domain }}</strong>.
                    Ensure DNS A-records are pointed to this server IP.
                </div>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button
                    @click="handleIssue"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold rounded-lg shadow-sm shadow-emerald-600/30 transition-all disabled:opacity-50"
                >
                    <RefreshCw class="w-3.5 h-3.5" :class="{ 'animate-spin': form.processing }" />
                    <span>{{ form.processing ? 'Requesting Certificate...' : (domain.ssl_enabled ? 'Renew SSL Certificate' : 'Issue SSL Certificate') }}</span>
                </button>

                <button
                    type="button"
                    @click="emit('close')"
                    class="px-4 py-2 rounded-lg bg-theme-bg-surface hover:bg-theme-border text-xs font-semibold text-theme-text-secondary hover:text-theme-text-primary border border-theme-border transition-colors"
                >
                    Cancel
                </button>
            </div>
        </div>
    </div>
</template>

