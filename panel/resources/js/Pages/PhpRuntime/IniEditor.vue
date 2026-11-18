<script setup lang="ts">
import { shallowRef, ref, computed } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { FileCode, Save, RefreshCw, Code2, SlidersHorizontal } from '@lucide/vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiTabs from '@shared/Ui/UiTabs.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';
import UiTextarea from '@shared/Ui/UiTextarea.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';

const props = defineProps({
    currentVersion: String,
    iniData: Object,
    installedVersions: Array,
});

const page = usePage();
const isAdmin = (page.props.auth as any)?.user?.role === 'admin';

const selectedVersion = shallowRef(props.currentVersion);

const versionTabs = computed(() =>
    ((props.installedVersions || []) as any[]).map((v) => ({
        id: String(v.version),
        label: `PHP ${v.version}`,
    }))
);

const form = useForm({
    version: props.currentVersion,
    directives: { ...(props.iniData?.directives || {}) },
});

const switchVersion = (ver: string) => {
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
        <UiPageHeader
            :title="__('MultiPHP INI Editor')"
            subtitle="Configure runtime directives such as memory limits, upload limits, and execution timeouts per PHP version."
        >
            <template #icon>
                <FileCode class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Version Selector Tabs -->
        <UiTabs
            :model-value="String(currentVersion || '')"
            :tabs="versionTabs"
            @select="switchVersion"
        />

        <UiAlert v-if="!isAdmin" variant="warning">
            {{ __('You are in read-only mode. Only system administrators can commit global PHP INI changes.') }}
        </UiAlert>

        <!-- Editor Form -->
        <UiCard>
            <div class="text-xs font-mono text-theme-text-secondary pb-4 mb-4 border-b border-theme-border-subtle flex flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span>Config File: {{ rawIniPath || iniData?.ini_path || `/usr/local/lsws/lsphp${currentVersion}/etc/php/${currentVersion}/litespeed/php.ini` }}</span>
                    <span class="text-theme-accent font-semibold">Active Engine: lsphp{{ currentVersion }}</span>
                </div>

                <div class="inline-flex items-center gap-1 p-1 bg-theme-bg-input border border-theme-border rounded-theme-md font-sans self-start">
                    <button
                        type="button"
                        @click="switchMode('basic')"
                        :class="[
                            'inline-flex items-center gap-1.5 px-3 py-1 rounded-theme-sm text-xs font-semibold transition-colors cursor-pointer',
                            mode === 'basic'
                                ? 'bg-theme-accent text-theme-text-on-accent shadow-xs'
                                : 'text-theme-text-secondary hover:text-theme-text-primary'
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
                            'inline-flex items-center gap-1.5 px-3 py-1 rounded-theme-sm text-xs font-semibold transition-colors cursor-pointer',
                            mode === 'advanced'
                                ? 'bg-theme-accent text-theme-text-on-accent shadow-xs'
                                : 'text-theme-text-secondary hover:text-theme-text-primary'
                        ]"
                    >
                        <Code2 class="w-3.5 h-3.5" />
                        <span>{{ __('Advanced Mode') }}</span>
                    </button>
                </div>
            </div>

            <form v-if="mode === 'basic'" @submit.prevent="submit" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <UiInput
                        v-model="form.directives.memory_limit"
                        :label="__('memory_limit')"
                        :disabled="!isAdmin"
                        :placeholder="__('256M')"
                    />

                    <UiInput
                        v-model="form.directives.upload_max_filesize"
                        :label="__('upload_max_filesize')"
                        :disabled="!isAdmin"
                        :placeholder="__('64M')"
                    />

                    <UiInput
                        v-model="form.directives.post_max_size"
                        :label="__('post_max_size')"
                        :disabled="!isAdmin"
                        :placeholder="__('64M')"
                    />

                    <UiInput
                        v-model="form.directives.max_execution_time"
                        :label="__('max_execution_time')"
                        :disabled="!isAdmin"
                        placeholder="300"
                    />

                    <UiInput
                        v-model="form.directives.max_input_vars"
                        :label="__('max_input_vars')"
                        :disabled="!isAdmin"
                        placeholder="3000"
                    />

                    <UiSelect
                        v-model="form.directives.display_errors"
                        :label="__('display_errors')"
                        :disabled="!isAdmin"
                    >
                        <option value="Off">{{ __('Off (Production)') }}</option>
                        <option value="On">{{ __('On (Development)') }}</option>
                    </UiSelect>
                </div>

                <UiInput
                    v-model="form.directives.disable_functions"
                    :label="__('disable_functions')"
                    :disabled="!isAdmin"
                    :placeholder="__('exec,passthru,shell_exec,system,proc_open,popen')"
                    class="font-mono"
                />

                <div v-if="isAdmin" class="pt-4 flex items-center justify-end gap-3 border-t border-theme-border-subtle">
                    <UiButton
                        type="submit"
                        variant="primary"
                        :disabled="form.processing"
                        :loading="form.processing"
                    >
                        <Save v-if="!form.processing" class="w-4 h-4" />
                        <span>{{ form.processing ? 'Saving...' : 'Save Configuration' }}</span>
                    </UiButton>
                </div>
            </form>

            <!-- Advanced Mode: Full php.ini Text Editor -->
            <div v-else class="space-y-4">
                <UiAlert variant="info">
                    <div class="leading-relaxed">
                        <span class="font-semibold">{{ __('Syntax Validation Guard:') }}</span>
                        Changes are tested with <code class="px-1 py-0.5 rounded-theme-sm bg-theme-bg-surface text-theme-text-primary font-mono">lsphp{{ currentVersion }} -c</code> before saving. If syntax errors are detected, the save is aborted and your live <code class="px-1 py-0.5 rounded-theme-sm bg-theme-bg-surface text-theme-text-primary font-mono">php.ini</code> remains untouched.
                    </div>
                </UiAlert>

                <UiAlert v-if="rawSuccessMsg" variant="success">
                    {{ rawSuccessMsg }}
                </UiAlert>

                <UiAlert v-if="rawErrorMsg" variant="danger">
                    <div class="whitespace-pre-wrap font-mono leading-relaxed">{{ rawErrorMsg }}</div>
                </UiAlert>

                <UiTextarea
                    v-model="rawContent"
                    :disabled="rawLoading || rawSaving"
                    :rows="20"
                    mono
                    placeholder="; Loading php.ini..."
                    spellcheck="false"
                />

                <div class="pt-4 flex items-center justify-between gap-3 border-t border-theme-border-subtle">
                    <UiButton
                        type="button"
                        variant="secondary"
                        size="sm"
                        @click="loadRawIni"
                        :disabled="rawLoading || rawSaving"
                    >
                        <RefreshCw class="w-3.5 h-3.5" :class="{ 'animate-spin': rawLoading }" />
                        <span>{{ __('Reload') }}</span>
                    </UiButton>

                    <div class="flex items-center gap-2">
                        <UiButton
                            v-if="rawContent !== initialRawContent"
                            type="button"
                            variant="secondary"
                            size="sm"
                            @click="rawContent = initialRawContent"
                            :disabled="rawSaving"
                        >
                            {{ __('Reset Changes') }}
                        </UiButton>

                        <UiButton
                            type="button"
                            variant="primary"
                            @click="saveRawIni"
                            :disabled="rawSaving || rawLoading || !rawContent"
                            :loading="rawSaving"
                        >
                            <Save v-if="!rawSaving" class="w-4 h-4" />
                            <span>{{ rawSaving ? 'Validating & Saving...' : 'Validate & Save' }}</span>
                        </UiButton>
                    </div>
                </div>
            </div>
        </UiCard>
    </div>
</template>


