<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import PanelLayout from '@/Layouts/PanelLayout.vue';
import { FileCode, Globe, CheckSquare, Square, RefreshCw } from 'lucide-vue-next';

const props = defineProps({
    domains: Array,
    installedVersions: Array,
});

const selectedDomains = ref([]);
const targetVersion = ref(props.installedVersions?.[0]?.version || '83');

const allSelected = computed({
    get: () => props.domains.length > 0 && selectedDomains.value.length === props.domains.length,
    set: (val) => {
        selectedDomains.value = val ? props.domains.map((d) => d.id) : [];
    },
});

const toggleSelectAll = () => {
    allSelected.value = !allSelected.value;
};

const batchForm = useForm({
    domain_ids: [],
    php_version: '83',
});

const applyBatch = () => {
    if (selectedDomains.value.length === 0) {
        alert('Please select at least one domain to switch PHP version.');
        return;
    }

    batchForm.domain_ids = selectedDomains.value;
    batchForm.php_version = targetVersion.value;
    batchForm.post('/php/manager', {
        onSuccess: () => {
            selectedDomains.value = [];
        },
    });
};

const switchSingle = (domainId, ver) => {
    router.post(`/domains/${domainId}/php`, { php_version: ver });
};
</script>

<template>
    <PanelLayout>
        <Head title="MultiPHP Manager" />

        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <FileCode class="w-6 h-6 text-purple-400" />
                        <span>MultiPHP Manager</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Select virtual hosts and seamlessly assign their dedicated SuEXEC PHP processor engine.
                    </p>
                </div>
            </div>

            <!-- Batch Action Toolbar -->
            <div class="p-4 bg-slate-900 border border-slate-800 rounded-xl flex flex-wrap items-center justify-between gap-4 shadow-sm">
                <div class="flex items-center gap-3">
                    <button
                        @click="toggleSelectAll"
                        class="p-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition-colors flex items-center gap-2 text-xs font-semibold"
                    >
                        <CheckSquare v-if="allSelected" class="w-4 h-4 text-purple-400" />
                        <Square v-else class="w-4 h-4 text-slate-400" />
                        <span>{{ allSelected ? 'Deselect All' : 'Select All' }}</span>
                    </button>
                    <span class="text-xs text-slate-400">
                        {{ selectedDomains.length }} selected
                    </span>
                </div>

                <div class="flex items-center gap-3">
                    <span class="text-xs text-slate-300 font-semibold">Set PHP Version:</span>
                    <select
                        v-model="targetVersion"
                        class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-lg text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                    >
                        <option v-for="v in installedVersions" :key="v.version" :value="v.version">
                            PHP {{ v.version }} {{ v.is_default ? '(Default)' : '' }}
                        </option>
                    </select>

                    <button
                        @click="applyBatch"
                        :disabled="selectedDomains.length === 0 || batchForm.processing"
                        class="px-4 py-1.5 rounded-lg bg-purple-600 hover:bg-purple-500 disabled:opacity-50 text-xs font-semibold text-white transition-colors shadow-sm"
                    >
                        {{ batchForm.processing ? 'Applying...' : 'Apply to Selected' }}
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-300">
                        <thead class="bg-slate-950/50 text-xs font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-800">
                            <tr>
                                <th class="w-10 px-5 py-3">
                                    <span class="sr-only">Select</span>
                                </th>
                                <th class="px-5 py-3">Domain</th>
                                <th class="px-5 py-3">Account User</th>
                                <th class="px-5 py-3">Current PHP Engine</th>
                                <th class="px-5 py-3 text-right">Change PHP</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            <tr v-for="dom in domains" :key="dom.id" class="hover:bg-slate-800/40 transition-colors">
                                <td class="px-5 py-3.5">
                                    <input
                                        type="checkbox"
                                        :value="dom.id"
                                        v-model="selectedDomains"
                                        class="rounded bg-slate-950 border-slate-800 text-purple-600 focus:ring-0 focus:ring-offset-0"
                                    />
                                </td>
                                <td class="px-5 py-3.5">
                                    <div class="font-semibold text-white flex items-center gap-2">
                                        <Globe class="w-4 h-4 text-emerald-400" />
                                        <span>{{ dom.domain }}</span>
                                    </div>
                                </td>
                                <td class="px-5 py-3.5 text-xs text-slate-400">
                                    {{ dom.user?.username || '—' }}
                                </td>
                                <td class="px-5 py-3.5">
                                    <span class="px-2.5 py-1 rounded-md text-xs font-semibold bg-purple-500/10 text-purple-300 border border-purple-500/20">
                                        lsphp{{ dom.php_version }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <select
                                        :value="dom.php_version"
                                        @change="switchSingle(dom.id, $event.target.value)"
                                        class="px-2.5 py-1 rounded bg-slate-950 border border-slate-800 text-slate-300 text-xs focus:outline-none focus:border-purple-500"
                                    >
                                        <option v-for="v in installedVersions" :key="v.version" :value="v.version">
                                            PHP {{ v.version }}
                                        </option>
                                    </select>
                                </td>
                            </tr>
                            <tr v-if="!domains || domains.length === 0">
                                <td colspan="5" class="px-5 py-8 text-center text-slate-500 text-sm">
                                    No domains configured.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </PanelLayout>
</template>

