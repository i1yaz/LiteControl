<script setup>
import { shallowRef } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { Sliders, Save, FileCode, CheckCircle2 } from 'lucide-vue-next';

const props = defineProps({
    currentVersion: String,
    installedVersions: Array,
    limits: Object,
});

const selectedVersion = shallowRef(props.currentVersion);

const form = useForm({
    version: props.currentVersion,
    memory_limit: props.limits?.memory_limit || '256M',
    max_execution_time: props.limits?.max_execution_time || 300,
    upload_max_filesize: props.limits?.upload_max_filesize || '64M',
    post_max_size: props.limits?.post_max_size || '64M',
    max_input_vars: props.limits?.max_input_vars || 3000,
});

const switchVersion = (ver) => {
    router.get('/configuration/php-limit', { version: ver });
};

const submit = () => {
    form.post('/configuration/php-limit');
};
</script>

<template>
            <Head title="PHP Limit" />

        <div class="max-w-4xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Sliders class="w-6 h-6 text-purple-400" />
                        <span>PHP Limit Configuration</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Global resource allocation per PHP version (Memory, execution time, and upload quotas).
                    </p>
                </div>

                <!-- Version Tabs -->
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
                        PHP {{ v.version.split('').join('.') }}
                    </button>
                </div>
            </div>

            <!-- Form -->
            <form @submit.prevent="submit" class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6">
                <div class="border-b border-slate-800 pb-4">
                    <h2 class="text-base font-semibold text-white">Active Limits for PHP {{ currentVersion }}</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Applies globally across all virtual hosts bound to lsphp{{ currentVersion }}.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Memory Limit</label>
                        <input
                            v-model="form.memory_limit"
                            type="text"
                            required
                            placeholder="256M"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-purple-500"
                        />
                        <p class="text-[11px] text-slate-500 mt-1">E.g. 128M, 256M, 512M, 1G</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Max Execution Time (seconds)</label>
                        <input
                            v-model.number="form.max_execution_time"
                            type="number"
                            min="10"
                            max="86400"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-purple-500"
                        />
                        <p class="text-[11px] text-slate-500 mt-1">Maximum time a script is allowed to run before termination.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Upload Max Filesize</label>
                        <input
                            v-model="form.upload_max_filesize"
                            type="text"
                            required
                            placeholder="64M"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-purple-500"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Post Max Size</label>
                        <input
                            v-model="form.post_max_size"
                            type="text"
                            required
                            placeholder="64M"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-purple-500"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Max Input Vars</label>
                        <input
                            v-model.number="form.max_input_vars"
                            type="number"
                            min="100"
                            max="50000"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-purple-500"
                        />
                        <p class="text-[11px] text-slate-500 mt-1">Default: 3000. Important for large form posts and menu builders.</p>
                    </div>
                </div>

                <div class="pt-4 border-t border-slate-800 flex items-center justify-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-5 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-500 text-white font-medium text-sm flex items-center gap-2 shadow-lg shadow-purple-600/20 disabled:opacity-50 transition-colors"
                    >
                        <Save class="w-4 h-4" />
                        <span>{{ form.processing ? 'Updating...' : 'Update Limits' }}</span>
                    </button>
                </div>
            </form>
        </div>
</template>

