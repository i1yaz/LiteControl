<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Globe, Save, AlertTriangle } from '@lucide/vue';

const props = defineProps({
    currentHostname: String,
});

const form = useForm({
    hostname: props.currentHostname || '',
});

const submit = () => {
    form.post('/server/hostname');
};
</script>

<template>
            <Head title="Change Hostname" />

        <div class="max-w-3xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Globe class="w-6 h-6 text-blue-400" />
                        <span>Change Server Hostname</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Update the server Fully Qualified Domain Name (FQDN) across systemd and OpenLiteSpeed configuration.
                    </p>
                </div>
            </div>

            <!-- Current Hostname Banner -->
            <div class="p-5 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-between">
                <div>
                    <p class="text-xs text-slate-400">Current Hostname</p>
                    <p class="text-lg font-bold text-white font-mono mt-0.5">{{ currentHostname }}</p>
                </div>
                <div class="p-3 rounded-xl bg-blue-500/10 text-blue-400 border border-blue-500/20">
                    <Globe class="w-6 h-6" />
                </div>
            </div>

            <!-- Hostname Form -->
            <form @submit.prevent="submit" class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6">
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-1.5">New Server Hostname (FQDN)</label>
                    <input
                        v-model="form.hostname"
                        type="text"
                        required
                        placeholder="server1.yourdomain.com"
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm font-mono text-white focus:outline-none focus:border-blue-500"
                    />
                    <p v-if="form.errors.hostname" class="text-xs text-rose-400 mt-1">{{ form.errors.hostname }}</p>
                    <p class="text-[11px] text-slate-500 mt-1.5">
                        Ensure the hostname has an active DNS A record pointing to this server IP address.
                    </p>
                </div>

                <div class="pt-4 border-t border-slate-800 flex items-center justify-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-medium text-sm flex items-center gap-2 shadow-lg shadow-blue-600/20 disabled:opacity-50 transition-colors"
                    >
                        <Save class="w-4 h-4" />
                        <span>{{ form.processing ? 'Updating...' : 'Update Hostname' }}</span>
                    </button>
                </div>
            </form>
        </div>
</template>

