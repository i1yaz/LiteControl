<script setup>
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { FileCode, CheckCircle2, Circle, AlertCircle, RefreshCw } from 'lucide-vue-next';

const props = defineProps({
    currentVersion: String,
    extensionsData: Object,
    installedVersions: Array,
});

const page = usePage();
const isAdmin = page.props.auth?.user?.role === 'admin';

const activeExtensions = computed(() => props.extensionsData?.installed || []);
const availableExtensions = computed(() => props.extensionsData?.available || []);

const switchVersion = (ver) => {
    router.get('/php/extensions', { version: ver });
};

const toggleExt = (ext, currentActive) => {
    if (!isAdmin) return;
    const action = currentActive ? 'disable' : 'enable';
    router.post('/php/extensions/toggle', {
        version: props.currentVersion,
        extension: ext,
        action: action,
    });
};
</script>

<template>
            <Head title="PHP Extensions" />

        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <FileCode class="w-6 h-6 text-purple-400" />
                        <span>PHP Extensions</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Enable or disable compiled PHP modules and extensions for your chosen runtime engine.
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

            <!-- Active Extensions Grid -->
            <div class="space-y-4">
                <h3 class="text-sm font-semibold text-slate-300 uppercase tracking-wider flex items-center gap-2">
                    <CheckCircle2 class="w-4 h-4 text-emerald-400" />
                    <span>Active Extensions ({{ activeExtensions.length }})</span>
                </h3>

                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                    <div
                        v-for="ext in activeExtensions"
                        :key="ext"
                        class="p-3 bg-slate-900 border border-slate-800 rounded-xl flex items-center justify-between hover:border-slate-700 transition-colors"
                    >
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                            <span class="text-xs font-semibold text-white">{{ ext }}</span>
                        </div>
                        <button
                            v-if="isAdmin"
                            @click="toggleExt(ext, true)"
                            title="Disable Extension"
                            class="text-[10px] text-slate-400 hover:text-rose-400 transition-colors"
                        >
                            Disable
                        </button>
                    </div>
                </div>
            </div>

            <!-- Available Extensions Grid -->
            <div class="space-y-4 pt-4 border-t border-slate-800">
                <h3 class="text-sm font-semibold text-slate-400 uppercase tracking-wider flex items-center gap-2">
                    <Circle class="w-4 h-4 text-slate-500" />
                    <span>Available / Inactive Extensions ({{ availableExtensions.length }})</span>
                </h3>

                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                    <div
                        v-for="ext in availableExtensions"
                        :key="ext"
                        class="p-3 bg-slate-900/50 border border-slate-800/60 rounded-xl flex items-center justify-between hover:border-slate-700 transition-colors"
                    >
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-slate-600"></span>
                            <span class="text-xs font-medium text-slate-400">{{ ext }}</span>
                        </div>
                        <button
                            v-if="isAdmin"
                            @click="toggleExt(ext, false)"
                            title="Enable Extension"
                            class="text-[10px] text-purple-400 hover:text-purple-300 font-semibold transition-colors"
                        >
                            Enable
                        </button>
                    </div>
                </div>
            </div>
        </div>
</template>

