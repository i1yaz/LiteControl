<script setup lang="ts">
import { shallowRef, ref, onMounted } from 'vue';
import { FileCode, RefreshCw, Save } from '@lucide/vue';
import axios from 'axios';
import UiButton from '@shared/Ui/UiButton.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
import UiTextarea from '@shared/Ui/UiTextarea.vue';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close']);

const conf = ref('');
const initialConf = ref('');
const loading = ref(false);
const saving = ref(false);
const errorMsg = ref('');
const successMsg = ref('');

const loadConf = async () => {
    loading.value = true;
    errorMsg.value = '';
    successMsg.value = '';
    try {
        const res = await axios.get(`/domains/${props.domain.id}/vhost-conf`);
        if (res.data.status === 'success') {
            conf.value = res.data.conf || '';
            initialConf.value = res.data.conf || '';
        }
    } catch (e) {
        errorMsg.value = e.response?.data?.message || 'Failed to load VirtualHost configuration.';
    } finally {
        loading.value = false;
    }
};

const saveConf = async () => {
    saving.value = true;
    errorMsg.value = '';
    successMsg.value = '';
    try {
        const res = await axios.post(`/domains/${props.domain.id}/vhost-conf`, {
            conf: conf.value,
        });
        if (res.data.status === 'success') {
            successMsg.value = res.data.message || 'Configuration syntax verified and applied successfully!';
            initialConf.value = conf.value;
        }
    } catch (e) {
        errorMsg.value = e.response?.data?.message || 'Validation failed. Changes were rolled back automatically.';
    } finally {
        saving.value = false;
    }
};

onMounted(() => {
    loadConf();
});
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-5 shadow-xs space-y-4">
        <!-- Header -->
        <div class="flex items-center justify-between border-b border-theme-border pb-3">
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary theme-ui-control flex items-center gap-2">
                    <FileCode class="w-4 h-4 text-theme-accent" />
                    <span>{{ __('Virtual Host Configuration') }}</span>
                </h3>
                <span class="text-[11px] text-theme-text-secondary font-mono mt-0.5 block">
                    {{ domain.domain }} (vhconf.conf)
                </span>
            </div>

            <div class="flex items-center gap-2">
                <UiButton
                    size="xs"
                    variant="secondary"
                    @click="loadConf"
                    :disabled="loading || saving"
                    :title="__('Reload Configuration')"
                >
                    <RefreshCw class="w-3.5 h-3.5" :class="{ 'animate-spin': loading }" />
                </UiButton>

                <UiButton
                    size="xs"
                    variant="secondary"
                    @click="emit('close')"
                >
                    Close
                </UiButton>
            </div>
        </div>

        <!-- Status notice / info banner -->
        <UiAlert variant="info">
            <div class="leading-relaxed">
                <span class="font-semibold">{{ __('Syntax Validation & Auto-Rollback Guard:') }}</span>
                Changes are tested with <code class="px-1 py-0.5 rounded-theme-sm bg-theme-bg-surface border border-theme-border text-theme-text-primary font-mono">{{ __('openlitespeed -t') }}</code> before reloading. If syntax errors occur, changes are automatically aborted with zero downtime.
            </div>
        </UiAlert>

        <!-- Success Message -->
        <UiAlert v-if="successMsg" variant="success">
            <span>{{ successMsg }}</span>
        </UiAlert>

        <!-- Error Message -->
        <UiAlert v-if="errorMsg" variant="danger">
            <div class="leading-relaxed whitespace-pre-wrap font-mono">{{ errorMsg }}</div>
        </UiAlert>

        <!-- Editable Directive Box -->
        <div class="space-y-3">
            <UiTextarea
                v-model="conf"
                :disabled="loading || saving"
                :rows="16"
                mono
                placeholder="# Loading VirtualHost directives..."
                spellcheck="false"
            />

            <div class="flex items-center justify-between">
                <span class="text-[11px] text-theme-text-muted">
                    Directives apply to OpenLiteSpeed vHost configuration.
                </span>

                <div class="flex items-center gap-2">
                    <UiButton
                        v-if="conf !== initialConf"
                        size="sm"
                        variant="secondary"
                        @click="conf = initialConf"
                        :disabled="saving"
                    >
                        Reset Changes
                    </UiButton>

                    <UiButton
                        size="sm"
                        variant="primary"
                        @click="saveConf"
                        :disabled="saving || loading || !conf"
                    >
                        <Save v-if="!saving" class="w-3.5 h-3.5" />
                        <RefreshCw v-else class="w-3.5 h-3.5 animate-spin" />
                        <span>{{ saving ? 'Testing & Applying...' : 'Save & Reload' }}</span>
                    </UiButton>
                </div>
            </div>
        </div>
    </div>
</template>

