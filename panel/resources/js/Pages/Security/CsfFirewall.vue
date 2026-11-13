<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Shield, Download, CheckCircle2, AlertTriangle, ArrowRight } from '@lucide/vue';

const props = defineProps({
    installed: Boolean,
    status: String,
});

const installCsf = () => {
    router.post('/modules/csf/install');
};
</script>

<template>
            <Head title="CSF Firewall" />

        <div class="max-w-4xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Shield class="w-6 h-6 text-rose-400" />
                        <span>ConfigServer Security & Firewall (CSF)</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Advanced SPI firewall, Login Failure Daemon (LFD), intrusion prevention, and automated brute-force mitigation.
                    </p>
                </div>
            </div>

            <!-- Installed State -->
            <div v-if="installed" class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6">
                <div class="flex items-center gap-4">
                    <div class="p-3 rounded-2xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                        <CheckCircle2 class="w-7 h-7" />
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-white">CSF & LFD Active</h2>
                        <p class="text-xs text-slate-400 mt-0.5">CSF stateful packet inspection firewall is installed and synchronizing with OpenLiteSpeed listeners.</p>
                    </div>
                </div>

                <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 text-xs text-slate-300 font-mono space-y-1">
                    <p>Status: <span class="text-emerald-400 font-bold uppercase">{{ status }}</span></p>
                    <p>Config file: /etc/csf/csf.conf</p>
                    <p>Daemon: lfd.service (Running)</p>
                </div>
            </div>

            <!-- Not Installed State -->
            <div v-else class="bg-slate-900 border border-slate-800 rounded-2xl p-8 text-center space-y-6">
                <div class="w-16 h-16 rounded-2xl bg-rose-500/10 border border-rose-500/20 text-rose-400 flex items-center justify-center mx-auto">
                    <Shield class="w-8 h-8" />
                </div>
                <div class="max-w-md mx-auto space-y-2">
                    <h2 class="text-lg font-bold text-white">CSF Firewall is Not Installed</h2>
                    <p class="text-xs text-slate-400 leading-relaxed">
                        CSF is an optional module in LiteControl. It replaces or enhances default UFW rules with deeper deep packet inspection and port scanning defense.
                    </p>
                </div>
                <div>
                    <button
                        @click="installCsf"
                        class="px-6 py-3 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-semibold text-xs inline-flex items-center gap-2 shadow-lg shadow-rose-600/20 transition-all"
                    >
                        <Download class="w-4 h-4" />
                        <span>Install CSF Module Now</span>
                    </button>
                </div>
            </div>
        </div>
</template>

