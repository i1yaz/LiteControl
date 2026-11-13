<script setup lang="ts">
import { shallowRef } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ShieldCheck, Lock, Save, Key, Globe } from '@lucide/vue';

const props = defineProps({
    panelHost: String,
    hasSsl: Boolean,
});

const mode = shallowRef('letsencrypt');

const form = useForm({
    type: 'letsencrypt',
    domain: props.panelHost || '',
    certificate: '',
    private_key: '',
});

const submit = () => {
    form.type = mode.value;
    form.post('/security/panel-ssl');
};
</script>

<template>
            <Head title="Panel SSL" />

        <div class="max-w-3xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Lock class="w-6 h-6 text-rose-400" />
                        <span>Panel SSL Certificate</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Secure the LiteControl control panel listener with an official TLS certificate.
                    </p>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex items-center gap-2 p-1 bg-slate-900 border border-slate-800 rounded-xl w-max">
                <button
                    @click="mode = 'letsencrypt'"
                    :class="[
                        'px-4 py-2 rounded-lg text-xs font-semibold transition-colors flex items-center gap-2',
                        mode === 'letsencrypt' ? 'bg-rose-600 text-white' : 'text-slate-400 hover:text-white'
                    ]"
                >
                    <Globe class="w-3.5 h-3.5" />
                    <span>Let's Encrypt (Automated)</span>
                </button>
                <button
                    @click="mode = 'custom'"
                    :class="[
                        'px-4 py-2 rounded-lg text-xs font-semibold transition-colors flex items-center gap-2',
                        mode === 'custom' ? 'bg-rose-600 text-white' : 'text-slate-400 hover:text-white'
                    ]"
                >
                    <Key class="w-3.5 h-3.5" />
                    <span>Custom Certificate</span>
                </button>
            </div>

            <!-- Form -->
            <form @submit.prevent="submit" class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6">
                <div v-if="mode === 'letsencrypt'" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Hostname / Panel Domain</label>
                        <input
                            v-model="form.domain"
                            type="text"
                            required
                            placeholder="panel.yourdomain.com"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-rose-500 font-mono"
                        />
                        <p class="text-[11px] text-slate-500 mt-1">
                            Ensure DNS points to this server IP before requesting a Let's Encrypt certificate.
                        </p>
                    </div>
                </div>

                <div v-else class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Certificate (CRT/PEM)</label>
                        <textarea
                            v-model="form.certificate"
                            rows="5"
                            required
                            placeholder="-----BEGIN CERTIFICATE-----"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-xs font-mono text-slate-200 focus:outline-none focus:border-rose-500"
                        ></textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Private Key (KEY)</label>
                        <textarea
                            v-model="form.private_key"
                            rows="5"
                            required
                            placeholder="-----BEGIN PRIVATE KEY-----"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-xs font-mono text-slate-200 focus:outline-none focus:border-rose-500"
                        ></textarea>
                    </div>
                </div>

                <div class="pt-4 border-t border-slate-800 flex items-center justify-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-medium text-sm flex items-center gap-2 shadow-lg shadow-rose-600/20 disabled:opacity-50 transition-colors"
                    >
                        <Save class="w-4 h-4" />
                        <span>{{ form.processing ? 'Applying...' : 'Apply Panel SSL' }}</span>
                    </button>
                </div>
            </form>
        </div>
</template>

