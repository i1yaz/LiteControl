<script setup>
import { shallowRef } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import PanelLayout from '@/Layouts/PanelLayout.vue';
import { ShieldCheck, ShieldAlert, Lock, RefreshCw, Key, Plus, CheckCircle2 } from 'lucide-vue-next';

const props = defineProps({
    domains: Array,
});

const customModalOpen = shallowRef(false);
const selectedDomain = shallowRef(null);

const issueForm = useForm({
    domain_id: null,
});

const customForm = useForm({
    domain_id: null,
    certificate: '',
    private_key: '',
});

const issueSsl = (domain) => {
    if (confirm(`Issue Let's Encrypt SSL certificate for ${domain.domain}?`)) {
        issueForm.domain_id = domain.id;
        issueForm.post('/security/ssl/issue');
    }
};

const renewSsl = (domain) => {
    router.post(`/security/ssl/${domain.id}/renew`);
};

const openCustomModal = (domain) => {
    selectedDomain.value = domain;
    customForm.domain_id = domain.id;
    customForm.certificate = '';
    customForm.private_key = '';
    customModalOpen.value = true;
};

const submitCustomSsl = () => {
    customForm.post('/security/ssl/custom', {
        onSuccess: () => {
            customModalOpen.value = false;
        },
    });
};
</script>

<template>
    <PanelLayout>
        <Head title="SSL Certificates" />

        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Lock class="w-6 h-6 text-rose-400" />
                        <span>SSL Certificates</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Issue free automated Let's Encrypt TLS/SSL certificates or install custom enterprise SSL certificates.
                    </p>
                </div>
            </div>

            <!-- Domains Table Card -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-slate-800/80 bg-slate-950/40 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                <th class="px-6 py-4">Domain</th>
                                <th class="px-6 py-4">User</th>
                                <th class="px-6 py-4">SSL Status</th>
                                <th class="px-6 py-4">Expires</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 text-xs text-slate-300">
                            <tr v-for="d in domains" :key="d.id" class="hover:bg-slate-800/30 transition-colors">
                                <td class="px-6 py-4 font-semibold text-white font-mono text-sm">
                                    {{ d.domain }}
                                </td>
                                <td class="px-6 py-4 text-slate-400">
                                    {{ d.user?.username || 'admin' }}
                                </td>
                                <td class="px-6 py-4">
                                    <span
                                        :class="[
                                            'px-2.5 py-1 rounded-full text-[11px] font-semibold border flex items-center gap-1.5 w-max',
                                            d.ssl_enabled
                                                ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
                                                : 'bg-amber-500/10 text-amber-400 border-amber-500/20'
                                        ]"
                                    >
                                        <ShieldCheck v-if="d.ssl_enabled" class="w-3.5 h-3.5" />
                                        <ShieldAlert v-else class="w-3.5 h-3.5" />
                                        <span>{{ d.ssl_enabled ? 'Active SSL' : 'Self-Signed / None' }}</span>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-slate-400 font-mono">
                                    {{ d.ssl_expires_at ? new Date(d.ssl_expires_at).toLocaleDateString() : 'N/A' }}
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            v-if="!d.ssl_enabled"
                                            @click="issueSsl(d)"
                                            :disabled="issueForm.processing"
                                            class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-medium text-xs transition-colors"
                                        >
                                            Issue Let's Encrypt
                                        </button>
                                        <button
                                            v-else
                                            @click="renewSsl(d)"
                                            class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 font-medium text-xs flex items-center gap-1.5 transition-colors"
                                        >
                                            <RefreshCw class="w-3 h-3 text-emerald-400" />
                                            <span>Renew</span>
                                        </button>
                                        <button
                                            @click="openCustomModal(d)"
                                            class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium text-xs flex items-center gap-1 transition-colors"
                                        >
                                            <Key class="w-3.5 h-3.5 text-rose-400" />
                                            <span>Custom SSL</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="domains.length === 0">
                                <td colspan="5" class="px-6 py-8 text-center text-slate-500 text-xs">
                                    No domains created yet. Add a domain to manage SSL certificates.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Custom SSL Modal -->
            <div v-if="customModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
                <div class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-xl p-6 sm:p-8 space-y-6 shadow-2xl">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                        <h2 class="text-lg font-bold text-white flex items-center gap-2">
                            <Key class="w-5 h-5 text-rose-400" />
                            <span>Install Custom SSL: {{ selectedDomain?.domain }}</span>
                        </h2>
                        <button @click="customModalOpen = false" class="text-slate-400 hover:text-white">&times;</button>
                    </div>

                    <form @submit.prevent="submitCustomSsl" class="space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1.5">Certificate (CRT/PEM)</label>
                            <textarea
                                v-model="customForm.certificate"
                                rows="5"
                                required
                                placeholder="-----BEGIN CERTIFICATE-----"
                                class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-xs font-mono text-slate-200 focus:outline-none focus:border-rose-500"
                            ></textarea>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1.5">Private Key (KEY)</label>
                            <textarea
                                v-model="customForm.private_key"
                                rows="5"
                                required
                                placeholder="-----BEGIN PRIVATE KEY-----"
                                class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-xs font-mono text-slate-200 focus:outline-none focus:border-rose-500"
                            ></textarea>
                        </div>

                        <div class="pt-4 border-t border-slate-800 flex items-center justify-end gap-3">
                            <button
                                type="button"
                                @click="customModalOpen = false"
                                class="px-4 py-2 rounded-xl text-xs font-medium text-slate-400 hover:text-white"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="customForm.processing"
                                class="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-medium text-xs disabled:opacity-50 transition-colors"
                            >
                                {{ customForm.processing ? 'Installing...' : 'Install Certificate' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </PanelLayout>
</template>

