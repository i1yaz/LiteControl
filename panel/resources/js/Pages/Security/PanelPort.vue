<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Sliders, Save, AlertTriangle, ShieldCheck } from '@lucide/vue';
import { confirmDialog } from '@/Composables/useDialog';

const props = defineProps({
    currentPort: Number,
});

const form = useForm({
    port: props.currentPort || 8090,
});

const submit = async () => {
    if (await confirmDialog({
        title: 'Change Panel Port',
        message: `Changing the panel port will reload OpenLiteSpeed and require you to access LiteControl via https://<IP>:${form.port}/. Proceed?`,
        confirmText: 'Proceed',
        variant: 'warning',
    })) {
        form.post('/security/panel-port');
    }
};
</script>

<template>
            <Head title="Panel Port" />

        <div class="max-w-2xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Sliders class="w-6 h-6 text-rose-400" />
                        <span>Change Panel Listening Port</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Configure the TCP port that the LiteControl admin web panel binds to.
                    </p>
                </div>
            </div>

            <!-- Current Port Card -->
            <div class="p-5 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-between">
                <div>
                    <p class="text-xs text-slate-400">Current Panel Port</p>
                    <p class="text-2xl font-extrabold text-white font-mono mt-0.5">{{ currentPort }}</p>
                </div>
                <div class="p-3 rounded-xl bg-rose-500/10 text-rose-400 border border-rose-500/20">
                    <ShieldCheck class="w-6 h-6" />
                </div>
            </div>

            <!-- Warning Callout -->
            <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300 text-xs flex items-start gap-3">
                <AlertTriangle class="w-5 h-5 text-amber-400 flex-shrink-0 mt-0.5" />
                <div>
                    <p class="font-bold">Self-Lockout Protection</p>
                    <p class="mt-0.5 text-amber-200/80 leading-relaxed">
                        The new port is automatically allowed in UFW before the OpenLiteSpeed listener is updated. If OLS fails to bind to the new port, it will automatically roll back.
                    </p>
                </div>
            </div>

            <!-- Form -->
            <form @submit.prevent="submit" class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6">
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1.5">New Port (1024 - 65535)</label>
                    <input
                        v-model.number="form.port"
                        type="number"
                        min="1024"
                        max="65535"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm font-mono text-white focus:outline-none focus:border-rose-500"
                    />
                    <p v-if="form.errors.port" class="text-xs text-rose-400 mt-1">{{ form.errors.port }}</p>
                </div>

                <div class="pt-4 border-t border-slate-800 flex items-center justify-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-medium text-sm flex items-center gap-2 shadow-lg shadow-rose-600/20 disabled:opacity-50 transition-colors"
                    >
                        <Save class="w-4 h-4" />
                        <span>{{ form.processing ? 'Updating...' : 'Change Port' }}</span>
                    </button>
                </div>
            </form>
        </div>
</template>

