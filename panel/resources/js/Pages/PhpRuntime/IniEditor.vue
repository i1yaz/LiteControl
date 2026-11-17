<script setup lang="ts">
import { shallowRef, ref } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { FileCode, Save, RefreshCw, AlertCircle, CheckCircle2, AlertTriangle, Code2, SlidersHorizontal } from '@lucide/vue';

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

const mode = ref<'basic' | 'advanced'>('basic');
const rawContent = ref('');
const initialRawContent = ref('');
const rawIniPath = ref('');
const rawLoading = ref(false);
const rawSaving = ref(false);
const rawSuccessMsg = ref('');
const rawErrorMsg = ref('');

const loadRawIni = async () => {
    rawLoading.value = true;
    rawErrorMsg.value = '';
    rawSuccessMsg.value = '';
    try {
        const res = await axios.get('/php/ini/raw', { params: { version: props.currentVersion } });
        rawContent.value = res.data?.data?.content ?? '';
        initialRawContent.value = rawContent.value;
        rawIniPath.value = res.data?.data?.ini_path ?? '';
    } catch (err: any) {
        rawErrorMsg.value = err?.response?.data?.message || 'Failed to load raw php.ini.';
    } finally {
        rawLoading.value = false;
    }
};

const switchMode = (target: 'basic' | 'advanced') => {
    mode.value = target;
    if (target === 'advanced' && !rawContent.value && !rawLoading.value) {
        loadRawIni();
    }
};

const saveRawIni = async () => {
    rawSaving.value = true;
    rawErrorMsg.value = '';
    rawSuccessMsg.value = '';
    try {
        const res = await axios.post('/php/ini/raw', {
            version: props.currentVersion,
            content: rawContent.value,
        });
        initialRawContent.value = rawContent.value;
        rawSuccessMsg.value = res.data?.message || 'php.ini validated and saved successfully.';
    } catch (err: any) {
        rawErrorMsg.value = err?.response?.data?.message || 'Failed to validate or save php.ini.';
    } finally {
        rawSaving.value = false;
    }
};
</script>

<template>
            <Head :title="__('MultiPHP INI Editor')" />

        <div class="max-w-4xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <FileCode class="w-6 h-6 text-purple-400" />
                        <span>{{ __('MultiPHP INI Editor') }}</span>
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
                <span>{{ __('You are in read-only mode. Only system administrators can commit global PHP INI changes.') }}</span>
            </div>

            <!-- Editor Form -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 shadow-sm">
                <div class="text-xs font-mono text-slate-400 pb-4 mb-4 border-b border-slate-800 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <span>Config File: {{ rawIniPath || iniData?.ini_path || `/usr/local/lsws/lsphp${currentVersion}/etc/php/${currentVersion}/litespeed/php.ini` }}</span>
                        <span class="text-purple-400 font-semibold ml-3">Active Engine: lsphp{{ currentVersion }}</span>
                    </div>

                    <div class="inline-flex items-center gap-1 p-1 bg-slate-950 border border-slate-800 rounded-lg font-sans self-start sm:self-auto">
                        <button
                            type="button"
                            @click="switchMode('basic')"
                            :class="[
                                'inline-flex items-center gap-1.5 px-3 py-1 rounded-md text-xs font-semibold transition-colors',
                                mode === 'basic'
                                    ? 'bg-purple-600 text-white shadow-sm'
                                    : 'text-slate-400 hover:text-white'
                            ]"
                        >
                            <SlidersHorizontal class="w-3.5 h-3.5" />
                            <span>{{ __('Basic Mode') }}</span>
                        </button>
                        <button
                            v-if="isAdmin"
                            type="button"
                            @click="switchMode('advanced')"
                            :class="[
                                'inline-flex items-center gap-1.5 px-3 py-1 rounded-md text-xs font-semibold transition-colors',
                                mode === 'advanced'
                                    ? 'bg-purple-600 text-white shadow-sm'
                                    : 'text-slate-400 hover:text-white'
                            ]"
                        >
                            <Code2 class="w-3.5 h-3.5" />
                            <span>{{ __('Advanced Mode') }}</span>
                        </button>
                    </div>
                </div>

                <form v-if="mode === 'basic'" @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">{{ __('memory_limit') }}</label>
                            <input
                                v-model="form.directives.memory_limit"
                                :disabled="!isAdmin"
                                type="text"
                                :placeholder="__('256M')"
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-purple-500 disabled:opacity-60"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">{{ __('upload_max_filesize') }}</label>
                            <input
                                v-model="form.directives.upload_max_filesize"
                                :disabled="!isAdmin"
                                type="text"
                                :placeholder="__('64M')"
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-purple-500 disabled:opacity-60"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">{{ __('post_max_size') }}</label>
                            <input
                                v-model="form.directives.post_max_size"
                                :disabled="!isAdmin"
                                type="text"
                                :placeholder="__('64M')"
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-purple-500 disabled:opacity-60"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">{{ __('max_execution_time') }}</label>
                            <input
                                v-model="form.directives.max_execution_time"
                                :disabled="!isAdmin"
                                type="text"
                                placeholder="300"
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-purple-500 disabled:opacity-60"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">{{ __('max_input_vars') }}</label>
                            <input
                                v-model="form.directives.max_input_vars"
                                :disabled="!isAdmin"
                                type="text"
                                placeholder="3000"
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-purple-500 disabled:opacity-60"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">{{ __('display_errors') }}</label>
                            <select
                                v-model="form.directives.display_errors"
                                :disabled="!isAdmin"
                                class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-purple-500 disabled:opacity-60"
                            >
                                <option value="Off">{{ __('Off (Production)') }}</option>
                                <option value="On">{{ __('On (Development)') }}</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">{{ __('disable_functions') }}</label>
                        <input
                            v-model="form.directives.disable_functions"
                            :disabled="!isAdmin"
                            type="text"
                            :placeholder="__('exec,passthru,shell_exec,system,proc_open,popen')"
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

                <!-- Advanced Mode: Full php.ini Text Editor -->
                <div v-else class="space-y-4">
                    <div class="p-3 bg-blue-500/10 border border-blue-500/20 rounded-lg text-xs text-blue-300 flex items-start gap-2">
                        <CheckCircle2 class="w-4 h-4 text-blue-400 shrink-0 mt-0.5" />
                        <div class="leading-relaxed">
                            <span class="font-semibold">{{ __('Syntax Validation Guard:') }}</span>
                            Changes are tested with <code class="px-1 py-0.5 rounded bg-blue-900/40 text-blue-200 font-mono">lsphp{{ currentVersion }} -c</code> before saving. If syntax errors are detected, the save is aborted and your live <code class="px-1 py-0.5 rounded bg-blue-900/40 text-blue-200 font-mono">php.ini</code> remains untouched.
                        </div>
                    </div>

                    <div v-if="rawSuccessMsg" class="p-3 bg-emerald-500/10 border border-emerald-500/30 rounded-lg text-xs text-emerald-400 flex items-center gap-2">
                        <CheckCircle2 class="w-4 h-4 shrink-0" />
                        <span>{{ rawSuccessMsg }}</span>
                    </div>

                    <div v-if="rawErrorMsg" class="p-3 bg-rose-500/10 border border-rose-500/30 rounded-lg text-xs text-rose-300 flex items-start gap-2 whitespace-pre-wrap font-mono">
                        <AlertTriangle class="w-4 h-4 text-rose-400 shrink-0 mt-0.5" />
                        <div class="leading-relaxed">{{ rawErrorMsg }}</div>
                    </div>

                    <textarea
                        v-model="rawContent"
                        :disabled="rawLoading || rawSaving"
                        rows="20"
                        class="w-full p-4 bg-slate-950 border border-slate-800 focus:border-purple-500 rounded-lg font-mono text-xs text-slate-200 leading-relaxed focus:outline-none resize-y selection:bg-purple-600/30 disabled:opacity-60 transition-colors"
                        placeholder="; Loading php.ini..."
                        spellcheck="false"
                    ></textarea>

                    <div class="pt-4 flex items-center justify-between gap-3 border-t border-slate-800">
                        <button
                            type="button"
                            @click="loadRawIni"
                            :disabled="rawLoading || rawSaving"
                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium transition-colors disabled:opacity-50"
                        >
                            <RefreshCw class="w-3.5 h-3.5" :class="{ 'animate-spin': rawLoading }" />
                            <span>{{ __('Reload') }}</span>
                        </button>

                        <div class="flex items-center gap-2">
                            <button
                                v-if="rawContent !== initialRawContent"
                                type="button"
                                @click="rawContent = initialRawContent"
                                :disabled="rawSaving"
                                class="px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium transition-colors"
                            >
                                {{ __('Reset Changes') }}
                            </button>

                            <button
                                type="button"
                                @click="saveRawIni"
                                :disabled="rawSaving || rawLoading || !rawContent"
                                class="inline-flex items-center gap-2 px-5 py-2 rounded-lg bg-purple-600 hover:bg-purple-500 disabled:opacity-50 text-sm font-medium text-white transition-colors shadow-sm shadow-purple-600/30"
                            >
                                <Save v-if="!rawSaving" class="w-4 h-4" />
                                <RefreshCw v-else class="w-4 h-4 animate-spin" />
                                <span>{{ rawSaving ? 'Validating & Saving...' : 'Validate & Save' }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
</template>

