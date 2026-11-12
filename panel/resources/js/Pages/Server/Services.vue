<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Server, RefreshCw, Play, Square, RotateCw, ShieldCheck, ShieldAlert, ExternalLink, Globe, Lock } from '@lucide/vue';

const props = defineProps({
    services: Array,
    webAdmin: Object,
});

const serviceAction = (service, action) => {
    if (action === 'stop' && !confirm(`Are you sure you want to STOP ${service}? This may disrupt hosted sites.`)) {
        return;
    }
    router.post(`/server/services/${service}/${action}`);
};

const form = useForm({
    allowed_ips: props.webAdmin?.allowed_ips || '',
});

const toggleWebAdmin = () => {
    if (props.webAdmin?.enabled) {
        if (!confirm('Disable OpenLiteSpeed WebAdmin console on port ' + (props.webAdmin?.port || 7080) + '?')) {
            return;
        }
        router.post('/server/webadmin/disable');
    } else {
        form.post('/server/webadmin/enable');
    }
};

const updateWebAdminIps = () => {
    form.post('/server/webadmin/enable');
};
</script>

<template>
            <Head title="Services & WebAdmin Manager" />

        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Server class="w-6 h-6 text-blue-400" />
                        <span>Services & WebAdmin Manager</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Monitor system daemons and manage OpenLiteSpeed native WebAdmin console access.
                    </p>
                </div>
            </div>

            <!-- OLS WebAdmin Management Card -->
            <div class="bg-gradient-to-r from-slate-900 to-slate-950 border border-slate-800 rounded-2xl p-6 shadow-xl relative overflow-hidden">
                <div class="absolute top-0 right-0 w-96 h-96 bg-blue-500/5 rounded-full blur-3xl pointer-events-none"></div>

                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6 relative z-10">
                    <div class="space-y-2 max-w-2xl">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center" :class="webAdmin?.enabled ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-800 text-slate-400 border border-slate-700'">
                                <Lock class="w-5 h-5" />
                            </div>
                            <div>
                                <div class="flex items-center gap-2.5">
                                    <h2 class="text-lg font-bold text-white">OpenLiteSpeed WebAdmin Console</h2>
                                    <span
                                        :class="[
                                            'px-2.5 py-0.5 rounded-full text-[11px] font-semibold border',
                                            webAdmin?.enabled
                                                ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
                                                : 'bg-amber-500/10 text-amber-400 border-amber-500/20'
                                        ]"
                                    >
                                        {{ webAdmin?.enabled ? `Enabled (Port ${webAdmin?.port || 7080})` : 'Disabled (Protected)' }}
                                    </span>
                                </div>
                                <p class="text-xs text-slate-400 mt-0.5">
                                    {{ webAdmin?.enabled 
                                        ? 'Native OLS WebAdmin console is active. Firewall and port rules are synchronized.' 
                                        : 'WebAdmin is locked down to prevent brute force and PHP exploit attempts.' }}
                                </p>
                            </div>
                        </div>

                        <!-- IP Whitelist Info / Form -->
                        <div v-if="webAdmin?.enabled" class="pt-2">
                            <form @submit.prevent="updateWebAdminIps" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5">
                                <div class="relative flex-1">
                                    <Globe class="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2" />
                                    <input
                                        v-model="form.allowed_ips"
                                        type="text"
                                        placeholder="Allowed IPs (comma separated, e.g. 192.0.2.1, 10.0.0.0/24, or empty for all)"
                                        class="w-full pl-9 pr-3 py-2 bg-slate-950/80 border border-slate-800 rounded-xl text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                    />
                                </div>
                                <button
                                    type="submit"
                                    :disabled="form.processing"
                                    class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-medium transition-colors whitespace-nowrap"
                                >
                                    Update Allowed IPs
                                </button>
                            </form>
                            <p class="text-[11px] text-slate-500 mt-1.5">
                                Leave blank to allow connections from any IP. If IPs are listed, clients from unlisted addresses receive a 403 Forbidden response.
                            </p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-3">
                        <a
                            v-if="webAdmin?.enabled"
                            :href="`https://${typeof window !== 'undefined' ? window.location.hostname : 'localhost'}:${webAdmin?.port || 7080}/`"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="px-4 py-2.5 rounded-xl bg-blue-500/10 hover:bg-blue-500/20 text-blue-400 border border-blue-500/20 text-xs font-semibold flex items-center gap-2 transition-all shadow-lg shadow-blue-500/5"
                        >
                            <ExternalLink class="w-4 h-4" />
                            <span>Launch WebAdmin</span>
                        </a>

                        <button
                            @click="toggleWebAdmin"
                            :class="[
                                'px-4 py-2.5 rounded-xl text-xs font-semibold flex items-center gap-2 transition-all shadow-lg',
                                webAdmin?.enabled
                                    ? 'bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/20 shadow-rose-500/5'
                                    : 'bg-emerald-600 hover:bg-emerald-500 text-white shadow-emerald-500/10'
                            ]"
                        >
                            <ShieldCheck v-if="!webAdmin?.enabled" class="w-4 h-4" />
                            <ShieldAlert v-else class="w-4 h-4" />
                            <span>{{ webAdmin?.enabled ? 'Disable WebAdmin' : 'Enable WebAdmin' }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Services Table Card -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-slate-800/80 bg-slate-950/40 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                <th class="px-6 py-4">Service</th>
                                <th class="px-6 py-4">Status</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 text-xs text-slate-300">
                            <tr v-for="svc in services" :key="svc.service || svc.name" class="hover:bg-slate-800/30 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-2.5 h-2.5 rounded-full" :class="svc.status === 'active' ? 'bg-emerald-400 animate-pulse' : 'bg-rose-500'"></div>
                                        <div>
                                            <p class="font-semibold text-white capitalize text-sm">{{ svc.service || svc.name }}</p>
                                            <p class="text-[11px] text-slate-500">{{ svc.description || 'System service' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span
                                        :class="[
                                            'px-2.5 py-1 rounded-full text-[11px] font-semibold border',
                                            svc.status === 'active'
                                                ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
                                                : 'bg-rose-500/10 text-rose-400 border-rose-500/20'
                                        ]"
                                    >
                                        {{ svc.status === 'active' ? 'Running' : 'Stopped' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button
                                            @click="serviceAction(svc.service || svc.name, 'restart')"
                                            class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium flex items-center gap-1.5 transition-colors"
                                            title="Restart"
                                        >
                                            <RotateCw class="w-3.5 h-3.5 text-blue-400" />
                                            <span>Restart</span>
                                        </button>
                                        <button
                                            v-if="svc.status === 'active'"
                                            @click="serviceAction(svc.service || svc.name, 'stop')"
                                            class="p-1.5 rounded-lg bg-slate-800 hover:bg-rose-950/40 text-slate-400 hover:text-rose-400 transition-colors"
                                            title="Stop Service"
                                        >
                                            <Square class="w-3.5 h-3.5" />
                                        </button>
                                        <button
                                            v-else
                                            @click="serviceAction(svc.service || svc.name, 'start')"
                                            class="p-1.5 rounded-lg bg-slate-800 hover:bg-emerald-950/40 text-slate-400 hover:text-emerald-400 transition-colors"
                                            title="Start Service"
                                        >
                                            <Play class="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
</template>

