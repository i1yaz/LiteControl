<script setup>
import { Head, router } from '@inertiajs/vue3';
import PanelLayout from '@/Layouts/PanelLayout.vue';
import { Server, RefreshCw, Play, Square, RotateCw } from 'lucide-vue-next';

const props = defineProps({
    services: Array,
});

const serviceAction = (service, action) => {
    if (action === 'stop' && !confirm(`Are you sure you want to STOP ${service}? This may disrupt hosted sites.`)) {
        return;
    }
    router.post(`/server/services/${service}/${action}`);
};
</script>

<template>
    <PanelLayout>
        <Head title="Services Manager" />

        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Server class="w-6 h-6 text-blue-400" />
                        <span>Services Manager</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Monitor health and manage life cycles of core and module daemon services.
                    </p>
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
    </PanelLayout>
</template>

