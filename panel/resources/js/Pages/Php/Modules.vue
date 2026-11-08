<script setup>
import { shallowRef, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { FileCode, Search, CheckCircle2 } from 'lucide-vue-next';

const props = defineProps({
    currentVersion: String,
    modules: Array,
    installedVersions: Array,
});

const searchQuery = shallowRef('');

const filteredModules = computed(() => {
    if (!searchQuery.value) return props.modules || [];
    return (props.modules || []).filter((m) =>
        m.toLowerCase().includes(searchQuery.value.toLowerCase())
    );
});

const switchVersion = (ver) => {
    router.get('/php/modules', { version: ver });
};
</script>

<template>
            <Head title="PHP Modules" />

        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <FileCode class="w-6 h-6 text-purple-400" />
                        <span>Loaded PHP Modules</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        View all active compiled and dynamic extensions currently loaded by the PHP engine (`php -m`).
                    </p>
                </div>

                <!-- Version Selector Tabs -->
                <div class="flex items-center gap-1.5 p-1 bg-slate-900 border border-slate-800 rounded-xl overflow-x-auto">
                    <button
                        v-for="v in installedVersions"
                        :key="v.version"
                        @click="switchVersion(v.version)"
                        :class="[
                            'px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors',
                            currentVersion === v.version
                                ? 'bg-purple-600 text-white shadow-sm'
                                : 'text-slate-400 hover:text-white hover:bg-slate-800'
                        ]"
                    >
                        PHP {{ v.version }}
                    </button>
                </div>
            </div>

            <!-- Search Bar -->
            <div class="flex items-center gap-3 bg-slate-900 border border-slate-800 rounded-xl p-3">
                <div class="relative flex-1">
                    <Search class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Search loaded modules (e.g. openssl, curl, opcache)..."
                        class="w-full pl-9 pr-4 py-1.5 bg-slate-950/70 border border-slate-800 rounded-lg text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-purple-500 transition-colors"
                    />
                </div>
                <span class="text-xs text-slate-400 px-2 font-mono">
                    {{ filteredModules.length }} modules
                </span>
            </div>

            <!-- Modules List Grid -->
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                <div
                    v-for="mod in filteredModules"
                    :key="mod"
                    class="p-3 bg-slate-900 border border-slate-800 rounded-xl flex items-center gap-2 hover:border-slate-700 transition-colors shadow-sm"
                >
                    <CheckCircle2 class="w-4 h-4 text-emerald-400 shrink-0" />
                    <span class="text-xs font-semibold text-slate-200 truncate">{{ mod }}</span>
                </div>
            </div>
        </div>
</template>

