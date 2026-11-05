<script setup>
import { Head, router } from '@inertiajs/vue3';
import PanelLayout from '@/Layouts/PanelLayout.vue';
import { FileCode, Download, Trash2, CheckCircle2, AlertTriangle, ShieldCheck } from 'lucide-vue-next';

const props = defineProps({
    phpVersions: Array,
});

const installVer = (ver) => {
    if (confirm(`Install lsphp${ver} from the official LiteSpeed repository? This will register an external processor.`)) {
        router.post('/php/versions/install', { version: ver });
    }
};

const removeVer = (ver) => {
    if (ver === '83') {
        alert('lsphp83 is the primary control panel runtime and cannot be uninstalled.');
        return;
    }
    if (confirm(`Uninstall lsphp${ver}? Any virtual hosts assigned to this version will need to be re-pointed.`)) {
        router.post('/php/versions/remove', { version: ver });
    }
};
</script>

<template>
    <PanelLayout>
        <Head title="PHP Version Manager" />

        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <FileCode class="w-6 h-6 text-purple-400" />
                        <span>PHP Version Manager</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Install or remove multiple OpenLiteSpeed PHP versions (lsphp74 through lsphp85).
                    </p>
                </div>
            </div>

            <!-- Version Cards Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div
                    v-for="v in phpVersions"
                    :key="v.version"
                    class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4 hover:border-slate-700 transition-colors shadow-sm"
                >
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div
                                :class="[
                                    'w-10 h-10 rounded-lg flex items-center justify-center font-bold text-xs',
                                    v.installed
                                        ? 'bg-purple-500/10 border border-purple-500/20 text-purple-400'
                                        : 'bg-slate-800 text-slate-500'
                                ]"
                            >
                                <FileCode class="w-5 h-5" />
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-white flex items-center gap-2">
                                    <span>PHP {{ v.version }}</span>
                                    <span v-if="v.is_default || v.version === '83'" class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                        Core Default
                                    </span>
                                </h3>
                                <span class="text-xs text-slate-400">
                                    Package: lsphp{{ v.version }}
                                </span>
                            </div>
                        </div>

                        <!-- Status Pill -->
                        <span
                            :class="[
                                'px-2 py-0.5 rounded text-xs font-semibold',
                                v.installed
                                    ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'
                                    : 'bg-slate-800 text-slate-500 border border-slate-700'
                            ]"
                        >
                            {{ v.installed ? 'Installed' : 'Not Installed' }}
                        </span>
                    </div>

                    <div class="pt-3 border-t border-slate-800 flex items-center justify-between text-xs">
                        <span class="text-slate-500 font-mono text-[11px] truncate max-w-[180px]">
                            {{ v.installed ? (v.path || `/usr/local/lsws/lsphp${v.version}/bin/lsphp`) : 'Available in LiteSpeed repo' }}
                        </span>

                        <div class="flex items-center gap-2">
                            <button
                                v-if="!v.installed"
                                @click="installVer(v.version)"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-purple-600 hover:bg-purple-500 text-white font-semibold transition-colors shadow-sm"
                            >
                                <Download class="w-3.5 h-3.5" />
                                <span>Install</span>
                            </button>

                            <button
                                v-else-if="v.version !== '83'"
                                @click="removeVer(v.version)"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-rose-500/20 text-rose-400 font-semibold transition-colors"
                            >
                                <Trash2 class="w-3.5 h-3.5" />
                                <span>Remove</span>
                            </button>

                            <span v-else class="text-slate-500 text-[11px] flex items-center gap-1">
                                <ShieldCheck class="w-3.5 h-3.5 text-emerald-400" />
                                System Protected
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </PanelLayout>
</template>

