<script setup>
import { shallowRef } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { FileCode, Save, RefreshCw, AlertCircle, Check } from 'lucide-vue-next';

const props = defineProps({
    currentVersion: String,
    iniData: Object,
    installedVersions: Array,
});

const page = usePage();
const isAdmin = page.props.auth?.user?.role === 'admin';

const selectedVersion = shallowRef(props.currentVersion);

const form = useForm({
    version: props.currentVersion,
    directives: { ...(props.iniData?.directives || {}) },
});

const switchVersion = (ver) => {
    router.get('/php/ini', { version: ver });
};

const submit = () => {
    form.post('/php/ini');
};
</script>

<template>
            <Head title="MultiPHP INI Editor" />

        <div class="max-w-4xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <FileCode class="w-6 h-6 text-purple-400" />
                        <span>MultiPHP INI Editor</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        Configure runtime directives such as memory limits, upload limits, and execution timeouts per PHP version.
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

            <div v-if="!isAdmin" class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300 text-xs flex items-center gap-2">
                <AlertCircle class="w-4 h-4 shrink-0" />
                <span>You are in read-only mode. Only system administrators can commit global PHP INI changes.</span>
            </div>

            <!-- Editor Form -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm">
                <div class="text-xs font-mono text-slate-400 pb-4 mb-4 border-b border-slate-800 flex items-center justify-between">
                    <span>Config File: {{ iniData?.ini_path || `/usr/local/lsws/lsphp${currentVersion}/etc/php/${currentVersion}/litespeed/php.ini` }}</span>
                    <span class="text-purple-400 font-semibold">Active Engine: lsphp{{ currentVersion }}</span>
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">memory_limit</label>
                            <input
                                v-model="form.directives.memory_limit"
                                :disabled="!isAdmin"
                                type="text"
                                placeholder="256M"
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-purple-500 disabled:opacity-60"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">upload_max_filesize</label>
                            <input
                                v-model="form.directives.upload_max_filesize"
                                :disabled="!isAdmin"
                                type="text"
                                placeholder="64M"
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-purple-500 disabled:opacity-60"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">post_max_size</label>
                            <input
                                v-model="form.directives.post_max_size"
                                :disabled="!isAdmin"
                                type="text"
                                placeholder="64M"
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-purple-500 disabled:opacity-60"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">max_execution_time</label>
                            <input
                                v-model="form.directives.max_execution_time"
                                :disabled="!isAdmin"
                                type="text"
                                placeholder="300"
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-purple-500 disabled:opacity-60"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">max_input_vars</label>
                            <input
                                v-model="form.directives.max_input_vars"
                                :disabled="!isAdmin"
                                type="text"
                                placeholder="3000"
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-purple-500 disabled:opacity-60"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">display_errors</label>
                            <select
                                v-model="form.directives.display_errors"
                                :disabled="!isAdmin"
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-purple-500 disabled:opacity-60"
                            >
                                <option value="Off">Off (Production)</option>
                                <option value="On">On (Development)</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">disable_functions</label>
                        <input
                            v-model="form.directives.disable_functions"
                            :disabled="!isAdmin"
                            type="text"
                            placeholder="exec,passthru,shell_exec,system,proc_open,popen"
                            class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 font-mono text-xs focus:outline-none focus:border-purple-500 disabled:opacity-60"
                        />
                    </div>

                    <div v-if="isAdmin" class="pt-4 flex items-center justify-end gap-3 border-t border-slate-800">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="inline-flex items-center gap-2 px-5 py-2 rounded-lg bg-purple-600 hover:bg-purple-500 disabled:opacity-50 text-sm font-medium text-white transition-colors shadow-sm shadow-purple-600/30"
                        >
                            <Save class="w-4 h-4" />
                            <span>{{ form.processing ? 'Saving...' : 'Save Configuration' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
</template>

