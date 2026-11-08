<script setup>
import { Head, router } from '@inertiajs/vue3';
import { Shield, Download, CheckCircle2, Bug, ExternalLink } from 'lucide-vue-next';

const props = defineProps({
    installed: Boolean,
    status: String,
});

const installImunify = () => {
    router.post('/modules/imunifyav/install');
};
</script>

<template>
            <Head title="ImunifyAV Antivirus" />

        <div class="max-w-4xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Bug class="w-6 h-6 text-rose-400" />
                        <span>ImunifyAV Antivirus & Malware Scanner</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Automated malicious file detection, webshell scanning, and antivirus database for hosted domains.
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
                        <h2 class="text-lg font-bold text-white">ImunifyAV Scanner Active</h2>
                        <p class="text-xs text-slate-400 mt-0.5">Antivirus signatures and background daemon active on host system.</p>
                    </div>
                </div>

                <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 text-xs text-slate-300 font-mono space-y-1">
                    <p>Status: <span class="text-emerald-400 font-bold uppercase">{{ status }}</span></p>
                    <p>Scanner: imunify-antivirus (active)</p>
                    <p>Signatures: Daily cloud definitions synchronized</p>
                </div>
            </div>

            <!-- Not Installed State -->
            <div v-else class="bg-slate-900 border border-slate-800 rounded-2xl p-8 text-center space-y-6">
                <div class="w-16 h-16 rounded-2xl bg-rose-500/10 border border-rose-500/20 text-rose-400 flex items-center justify-center mx-auto">
                    <Bug class="w-8 h-8" />
                </div>
                <div class="max-w-md mx-auto space-y-2">
                    <h2 class="text-lg font-bold text-white">ImunifyAV is Not Installed</h2>
                    <p class="text-xs text-slate-400 leading-relaxed">
                        ImunifyAV is an optional module in LiteControl providing high-precision malware scanning across all web root directories and PHP scripts.
                    </p>
                </div>
                <div>
                    <button
                        @click="installImunify"
                        class="px-6 py-3 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-semibold text-xs inline-flex items-center gap-2 shadow-lg shadow-rose-600/20 transition-all"
                    >
                        <Download class="w-4 h-4" />
                        <span>Install ImunifyAV Module</span>
                    </button>
                </div>
            </div>
        </div>
</template>

