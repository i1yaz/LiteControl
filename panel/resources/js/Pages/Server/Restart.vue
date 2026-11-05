<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PanelLayout from '@/Layouts/PanelLayout.vue';
import { RotateCw, RefreshCw, AlertTriangle, Server, Database, Zap } from 'lucide-vue-next';

const props = defineProps({
    stats: Object,
});

const form = useForm({
    action: 'ols_restart',
});

const execute = (actionName) => {
    form.action = actionName;
    if (confirm(`Confirm restart action: ${actionName}?`)) {
        form.post('/server/restart');
    }
};
</script>

<template>
    <PanelLayout>
        <Head title="Restart Services" />

        <div class="max-w-4xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <RotateCw class="w-6 h-6 text-blue-400" />
                        <span>Restart Services & Server</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Trigger graceful restarts of OpenLiteSpeed web server, database, cache, or system components.
                    </p>
                </div>
            </div>

            <!-- Actions Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- OLS Graceful Reload -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 flex flex-col justify-between space-y-4">
                    <div class="flex items-start gap-3">
                        <div class="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                            <Zap class="w-5 h-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-white">OpenLiteSpeed Safe Reload</h2>
                            <p class="text-xs text-slate-400 mt-1">
                                Zero-downtime graceful reload. Validates syntax before reloading worker pools with automatic rollback on error.
                            </p>
                        </div>
                    </div>
                    <button
                        @click="execute('ols_reload')"
                        :disabled="form.processing"
                        class="w-full py-2.5 rounded-xl bg-emerald-600/20 hover:bg-emerald-600 text-emerald-300 hover:text-white border border-emerald-500/30 text-xs font-bold transition-all"
                    >
                        Execute OLS Safe Reload
                    </button>
                </div>

                <!-- OLS Full Restart -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 flex flex-col justify-between space-y-4">
                    <div class="flex items-start gap-3">
                        <div class="p-2.5 rounded-xl bg-amber-500/10 text-amber-400 border border-amber-500/20">
                            <RotateCw class="w-5 h-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-white">OpenLiteSpeed Hard Restart</h2>
                            <p class="text-xs text-slate-400 mt-1">
                                Fully stops and starts OpenLiteSpeed server daemon and all SuEXEC detached PHP processes.
                            </p>
                        </div>
                    </div>
                    <button
                        @click="execute('ols_restart')"
                        :disabled="form.processing"
                        class="w-full py-2.5 rounded-xl bg-amber-600/20 hover:bg-amber-600 text-amber-300 hover:text-white border border-amber-500/30 text-xs font-bold transition-all"
                    >
                        Execute OLS Restart
                    </button>
                </div>

                <!-- MariaDB Restart -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 flex flex-col justify-between space-y-4">
                    <div class="flex items-start gap-3">
                        <div class="p-2.5 rounded-xl bg-sky-500/10 text-sky-400 border border-sky-500/20">
                            <Database class="w-5 h-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-white">MariaDB SQL Restart</h2>
                            <p class="text-xs text-slate-400 mt-1">
                                Restarts the MySQL / MariaDB database engine daemon. May interrupt active client transactions.
                            </p>
                        </div>
                    </div>
                    <button
                        @click="execute('mariadb_restart')"
                        :disabled="form.processing"
                        class="w-full py-2.5 rounded-xl bg-sky-600/20 hover:bg-sky-600 text-sky-300 hover:text-white border border-sky-500/30 text-xs font-bold transition-all"
                    >
                        Restart MariaDB
                    </button>
                </div>

                <!-- Redis Restart -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 flex flex-col justify-between space-y-4">
                    <div class="flex items-start gap-3">
                        <div class="p-2.5 rounded-xl bg-rose-500/10 text-rose-400 border border-rose-500/20">
                            <Server class="w-5 h-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-white">Redis Cache Restart</h2>
                            <p class="text-xs text-slate-400 mt-1">
                                Restarts Redis in-memory cache server. Cached session keys and transient queue jobs may reset.
                            </p>
                        </div>
                    </div>
                    <button
                        @click="execute('redis_restart')"
                        :disabled="form.processing"
                        class="w-full py-2.5 rounded-xl bg-rose-600/20 hover:bg-rose-600 text-rose-300 hover:text-white border border-rose-500/30 text-xs font-bold transition-all"
                    >
                        Restart Redis
                    </button>
                </div>
            </div>
        </div>
    </PanelLayout>
</template>

