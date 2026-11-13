<script setup lang="ts">
import { shallowRef, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Cpu, RefreshCw, XCircle, Search, AlertCircle } from '@lucide/vue';
import { confirmDialog } from '@/Composables/useDialog';

const props = defineProps({
    processes: Array,
});

const search = shallowRef('');

const filteredProcesses = computed(() => {
    if (!search.value) return props.processes || [];
    const q = search.value.toLowerCase();
    return (props.processes || []).filter(p =>
        (p.command && p.command.toLowerCase().includes(q)) ||
        (p.user && p.user.toLowerCase().includes(q)) ||
        String(p.pid).includes(q)
    );
});

const killProcess = async (pid) => {
    if (await confirmDialog({
        title: 'Terminate Process',
        message: `Are you sure you want to terminate PID ${pid}?`,
        confirmText: 'Terminate',
        variant: 'danger',
    })) {
        router.post(`/server/processes/${pid}/kill`);
    }
};

const refresh = () => {
    router.reload({ only: ['processes'] });
};
</script>

<template>
            <Head title="Process Manager" />

        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Cpu class="w-6 h-6 text-blue-400" />
                        <span>Process Manager</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Active Linux server processes sorted by CPU and memory consumption.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <div class="relative">
                        <Search class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Filter processes..."
                            class="pl-9 pr-3.5 py-1.5 rounded-xl bg-slate-900 border border-slate-800 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-blue-500"
                        />
                    </div>
                    <button
                        @click="refresh"
                        class="p-2 rounded-xl bg-slate-900 border border-slate-800 text-slate-300 hover:text-white hover:bg-slate-800 transition-colors"
                        title="Refresh"
                    >
                        <RefreshCw class="w-4 h-4" />
                    </button>
                </div>
            </div>

            <!-- Process Table -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-slate-800/80 bg-slate-950/40 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                <th class="px-5 py-3.5">PID</th>
                                <th class="px-5 py-3.5">User</th>
                                <th class="px-5 py-3.5">CPU %</th>
                                <th class="px-5 py-3.5">Mem %</th>
                                <th class="px-5 py-3.5">Command</th>
                                <th class="px-5 py-3.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 font-mono text-xs text-slate-300">
                            <tr v-for="p in filteredProcesses" :key="p.pid" class="hover:bg-slate-800/30 transition-colors">
                                <td class="px-5 py-3 text-sky-400 font-semibold">{{ p.pid }}</td>
                                <td class="px-5 py-3 text-slate-300 font-sans">{{ p.user }}</td>
                                <td class="px-5 py-3">
                                    <span :class="Number(p.cpu) > 10 ? 'text-amber-400 font-bold' : 'text-slate-300'">
                                        {{ p.cpu }}%
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-slate-300">{{ p.mem }}%</td>
                                <td class="px-5 py-3 max-w-md truncate text-slate-200" :title="p.command">
                                    {{ p.command }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <button
                                        @click="killProcess(p.pid)"
                                        class="px-2.5 py-1 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/20 text-[11px] font-sans font-semibold transition-colors"
                                    >
                                        Kill
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="filteredProcesses.length === 0">
                                <td colspan="6" class="px-5 py-8 text-center text-slate-500 font-sans text-xs">
                                    No processes found matching your filter.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
</template>

