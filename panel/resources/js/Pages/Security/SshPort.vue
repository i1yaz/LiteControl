<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PanelLayout from '@/Layouts/PanelLayout.vue';
import { Terminal, Save, AlertTriangle, ShieldCheck } from 'lucide-vue-next';

const props = defineProps({
    currentPort: Number,
});

const form = useForm({
    port: props.currentPort || 22,
});

const submit = () => {
    if (confirm(`Change SSH port to ${form.port}? Make sure you remember this port when opening SSH sessions.`)) {
        form.post('/security/ssh-port');
    }
};
</script>

<template>
    <PanelLayout>
        <Head title="SSH Port" />

        <div class="max-w-2xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Terminal class="w-6 h-6 text-rose-400" />
                        <span>Change SSH Port</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Hardening SSH against automated brute-force attacks by moving away from default port 22.
                    </p>
                </div>
            </div>

            <!-- Current SSH Port -->
            <div class="p-5 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-between">
                <div>
                    <p class="text-xs text-slate-400">Current SSH Port</p>
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
                    <p class="font-bold">Firewall Synchronization</p>
                    <p class="mt-0.5 text-amber-200/80 leading-relaxed">
                        LiteControl automatically adds an allow rule for the new SSH port in UFW, tests sshd syntax via <code>sshd -t</code>, restarts the SSH daemon, and removes the old rule once verified.
                    </p>
                </div>
            </div>

            <!-- Form -->
            <form @submit.prevent="submit" class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6">
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1.5">New SSH Port (1 - 65535)</label>
                    <input
                        v-model.number="form.port"
                        type="number"
                        min="1"
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
                        <span>{{ form.processing ? 'Updating...' : 'Change SSH Port' }}</span>
                    </button>
                </div>
            </form>
        </div>
    </PanelLayout>
</template>

