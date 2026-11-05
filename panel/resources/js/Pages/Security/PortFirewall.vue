<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import PanelLayout from '@/Layouts/PanelLayout.vue';
import { ShieldCheck, ShieldAlert, Lock, Unlock, Server } from 'lucide-vue-next';

const props = defineProps({
    standardPorts: Array,
    activeRules: Array,
});

const isPortOpen = (port) => {
    return (props.activeRules || []).some(r => {
        const to = r.to || '';
        return to.startsWith(port + '/') || to === port;
    });
};

const togglePort = (item) => {
    const open = isPortOpen(item.port);
    if (open) {
        if (confirm(`Close port ${item.port} (${item.name})?`)) {
            router.delete(`/security/ufw/rules/${item.port}`);
        }
    } else {
        router.post('/security/ufw/rules', {
            name: item.name,
            port: item.port,
            protocol: item.protocol,
            action: 'allow',
            source_ip: null,
        });
    }
};
</script>

<template>
    <PanelLayout>
        <Head title="Port Firewall" />

        <div class="max-w-4xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Server class="w-6 h-6 text-rose-400" />
                        <span>Port Firewall (Service Presets)</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Quick 1-click firewall control for standard web hosting protocols and ports.
                    </p>
                </div>
            </div>

            <!-- Ports Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div
                    v-for="p in standardPorts"
                    :key="p.port"
                    class="p-5 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-between shadow-lg"
                >
                    <div class="flex items-center gap-3.5">
                        <div
                            :class="[
                                'p-2.5 rounded-xl border',
                                isPortOpen(p.port)
                                    ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
                                    : 'bg-rose-500/10 text-rose-400 border-rose-500/20'
                            ]"
                        >
                            <ShieldCheck v-if="isPortOpen(p.port)" class="w-5 h-5" />
                            <ShieldAlert v-else class="w-5 h-5" />
                        </div>
                        <div>
                            <p class="text-sm font-bold text-white">{{ p.name }}</p>
                            <p class="text-xs text-slate-400 font-mono">Port {{ p.port }}/{{ p.protocol }}</p>
                        </div>
                    </div>

                    <button
                        @click="togglePort(p)"
                        :class="[
                            'px-3.5 py-1.5 rounded-xl text-xs font-bold transition-colors border',
                            isPortOpen(p.port)
                                ? 'bg-rose-500/10 text-rose-400 border-rose-500/20 hover:bg-rose-500/20'
                                : 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20 hover:bg-emerald-500/20'
                        ]"
                    >
                        {{ isPortOpen(p.port) ? 'Close Port' : 'Open Port' }}
                    </button>
                </div>
            </div>
        </div>
    </PanelLayout>
</template>

