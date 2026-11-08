<script setup>
import { shallowRef } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { Shield, Plus, Trash2, CheckCircle2, AlertCircle } from 'lucide-vue-next';

const props = defineProps({
    active: Boolean,
    systemRules: Array,
    rules: Array,
});

const modalOpen = shallowRef(false);

const form = useForm({
    name: '',
    port: '',
    protocol: 'tcp',
    action: 'allow',
    source_ip: '',
});

const submitRule = () => {
    form.post('/security/ufw/rules', {
        onSuccess: () => {
            modalOpen.value = false;
            form.reset();
        },
    });
};

const deleteRule = (ruleId) => {
    if (confirm(`Are you sure you want to delete firewall rule ${ruleId}?`)) {
        router.delete(`/security/ufw/rules/${ruleId}`);
    }
};
</script>

<template>
            <Head title="UFW Firewall" />

        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Shield class="w-6 h-6 text-rose-400" />
                        <span>UFW Firewall Manager</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Control inbound server network traffic and ports with Uncomplicated Firewall.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <span
                        :class="[
                            'px-3 py-1 rounded-full text-xs font-semibold border flex items-center gap-1.5',
                            active ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border-rose-500/20'
                        ]"
                    >
                        <span class="w-2 h-2 rounded-full" :class="active ? 'bg-emerald-400' : 'bg-rose-400'"></span>
                        <span>UFW {{ active ? 'Active' : 'Inactive' }}</span>
                    </span>

                    <button
                        @click="modalOpen = true"
                        class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-medium text-xs flex items-center gap-2 shadow-lg shadow-rose-600/20 transition-colors"
                    >
                        <Plus class="w-4 h-4" />
                        <span>Add Rule</span>
                    </button>
                </div>
            </div>

            <!-- Rules Table -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-slate-800/80 bg-slate-950/40 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                <th class="px-6 py-4"># / Port</th>
                                <th class="px-6 py-4">Protocol</th>
                                <th class="px-6 py-4">Action</th>
                                <th class="px-6 py-4">Source IP</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 font-mono text-xs text-slate-300">
                            <!-- Display system rules parsed from lc-cli -->
                            <tr v-for="(r, idx) in systemRules" :key="r.id || idx" class="hover:bg-slate-800/30 transition-colors">
                                <td class="px-6 py-4 font-semibold text-white font-sans">
                                    {{ r.to || r.port }}
                                </td>
                                <td class="px-6 py-4 text-slate-400 uppercase">
                                    {{ (r.to && r.to.includes('/')) ? r.to.split('/')[1] : 'tcp' }}
                                </td>
                                <td class="px-6 py-4">
                                    <span
                                        :class="[
                                            'px-2 py-0.5 rounded text-[10px] font-bold font-sans uppercase border',
                                            (r.action && r.action.toLowerCase().includes('allow'))
                                                ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
                                                : 'bg-rose-500/10 text-rose-400 border-rose-500/20'
                                        ]"
                                    >
                                        {{ r.action }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-slate-400">
                                    {{ r.from || 'Anywhere' }}
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <button
                                        @click="deleteRule(r.id || r.number || r.to)"
                                        class="p-1.5 rounded-lg bg-slate-800 hover:bg-rose-950/40 text-slate-400 hover:text-rose-400 transition-colors"
                                        title="Delete Rule"
                                    >
                                        <Trash2 class="w-3.5 h-3.5" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add Rule Modal -->
            <div v-if="modalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
                <div class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-md p-6 sm:p-8 space-y-6 shadow-2xl">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                        <h2 class="text-base font-bold text-white flex items-center gap-2">
                            <Shield class="w-5 h-5 text-rose-400" />
                            <span>Add Firewall Rule</span>
                        </h2>
                        <button @click="modalOpen = false" class="text-slate-400 hover:text-white">&times;</button>
                    </div>

                    <form @submit.prevent="submitRule" class="space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1.5">Rule Name / Description</label>
                            <input
                                v-model="form.name"
                                type="text"
                                required
                                placeholder="Custom App Port"
                                class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-xs text-white focus:outline-none focus:border-rose-500"
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-slate-300 mb-1.5">Port (or Range)</label>
                                <input
                                    v-model="form.port"
                                    type="text"
                                    required
                                    placeholder="8080"
                                    class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-xs text-white focus:outline-none focus:border-rose-500"
                                />
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-300 mb-1.5">Protocol</label>
                                <select
                                    v-model="form.protocol"
                                    class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-xs text-white focus:outline-none focus:border-rose-500"
                                >
                                    <option value="tcp">TCP</option>
                                    <option value="udp">UDP</option>
                                    <option value="any">Any</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-slate-300 mb-1.5">Action</label>
                                <select
                                    v-model="form.action"
                                    class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-xs text-white focus:outline-none focus:border-rose-500"
                                >
                                    <option value="allow">ALLOW</option>
                                    <option value="deny">DENY</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-300 mb-1.5">Source IP (Optional)</label>
                                <input
                                    v-model="form.source_ip"
                                    type="text"
                                    placeholder="Anywhere"
                                    class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-xs text-white focus:outline-none focus:border-rose-500"
                                />
                            </div>
                        </div>

                        <div class="pt-4 border-t border-slate-800 flex items-center justify-end gap-3">
                            <button
                                type="button"
                                @click="modalOpen = false"
                                class="px-4 py-2 rounded-xl text-xs font-medium text-slate-400 hover:text-white"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-medium text-xs disabled:opacity-50 transition-colors"
                            >
                                {{ form.processing ? 'Adding...' : 'Add Rule' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
</template>

