<script setup>
import { Head, router } from '@inertiajs/vue3';
import { Layers, Download, Trash2, CheckCircle2, AlertCircle, ArrowUpRight } from '@lucide/vue';

const props = defineProps({
    modules: Array,
});

const installModule = (mod) => {
    if (confirm(`Install optional module '${mod.title || mod.name}'? This will download packages and configure system services.`)) {
        router.post(`/modules/${mod.name}/install`);
    }
};

const removeModule = (mod) => {
    if (confirm(`Are you sure you want to uninstall module '${mod.title || mod.name}'?`)) {
        router.post(`/modules/${mod.name}/remove`);
    }
};
</script>

<template>
    <Head title="Module Manager" />

    <div class="max-w-5xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Layers class="w-6 h-6 text-emerald-400" />
                        <span>On-Demand Module Manager</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Extend LiteControl capabilities on-demand without bloating the core OpenLiteSpeed installation.
                    </p>
                </div>
            </div>

            <!-- Modules Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div
                    v-for="mod in modules"
                    :key="mod.name"
                    class="bg-slate-900 border border-slate-800 rounded-2xl p-6 flex flex-col justify-between space-y-5 shadow-xl hover:border-slate-700/70 transition-all"
                >
                    <div class="space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="text-base font-bold text-white">{{ mod.title || mod.name }}</h2>
                                <p class="text-[11px] font-mono text-emerald-400">module: {{ mod.name }}</p>
                            </div>
                            <span
                                :class="[
                                    'px-2.5 py-1 rounded-full text-[11px] font-semibold border flex items-center gap-1.5',
                                    mod.installed
                                        ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
                                        : 'bg-slate-800 text-slate-400 border-slate-700'
                                ]"
                            >
                                <span class="w-2 h-2 rounded-full" :class="mod.installed ? 'bg-emerald-400' : 'bg-slate-500'"></span>
                                <span>{{ mod.installed ? 'Installed' : 'Available' }}</span>
                            </span>
                        </div>

                        <p class="text-xs text-slate-400 leading-relaxed">
                            {{ mod.description }}
                        </p>
                    </div>

                    <div class="pt-4 border-t border-slate-800 flex items-center justify-between">
                        <span class="text-[11px] text-slate-500">
                            {{ mod.installed ? 'Active in lc-cli' : 'Click to install on-demand' }}
                        </span>

                        <div class="flex items-center gap-2">
                            <button
                                v-if="!mod.installed"
                                @click="installModule(mod)"
                                class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-medium text-xs flex items-center gap-1.5 shadow-lg shadow-emerald-600/20 transition-all"
                            >
                                <Download class="w-3.5 h-3.5" />
                                <span>Install</span>
                            </button>
                            <button
                                v-else
                                @click="removeModule(mod)"
                                class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-rose-950/40 text-slate-400 hover:text-rose-400 font-medium text-xs flex items-center gap-1.5 border border-slate-700 hover:border-rose-500/30 transition-all"
                            >
                                <Trash2 class="w-3.5 h-3.5" />
                                <span>Uninstall</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
</template>

