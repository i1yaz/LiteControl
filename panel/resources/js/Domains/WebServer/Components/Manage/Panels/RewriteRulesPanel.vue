<script setup lang="ts">
import { shallowRef, onMounted } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { FileEdit, Save, RefreshCw, X } from '@lucide/vue';
import { REWRITE_TEMPLATES } from '@domains/WebServer/Config/websiteManageConfig';
import UiButton from '@shared/Ui/UiButton.vue';
import UiTextarea from '@shared/Ui/UiTextarea.vue';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close']);

const selectedTemplate = shallowRef('');
const content = shallowRef('');
const loading = shallowRef(false);

const saveForm = useForm({
    content: '',
});

const loadHtaccess = async () => {
    loading.value = true;
    try {
        const res = await fetch(`/domains/${props.domain.id}/htaccess`);
        const json = await res.json();
        if (json.status === 'success') {
            content.value = json.content || '';
            saveForm.content = content.value;
        }
    } catch (e) {
        console.error('Failed to load .htaccess', e);
    } finally {
        loading.value = false;
    }
};

const applyTemplate = () => {
    if (!selectedTemplate.value) return;
    const template = REWRITE_TEMPLATES.find(t => t.id === selectedTemplate.value);
    if (!template) return;

    // Non-destructive append/insert: preserve existing rules
    const existing = saveForm.content.trim();
    if (existing) {
        saveForm.content = `${existing}\n\n${template.snippet}\n`;
    } else {
        saveForm.content = `${template.snippet}\n`;
    }

    selectedTemplate.value = '';
};

const saveHtaccess = () => {
    saveForm.post(`/domains/${props.domain.id}/htaccess`, {
        preserveScroll: true,
    });
};

onMounted(() => {
    loadHtaccess();
});
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-5 shadow-xs space-y-5">
        <!-- Header -->
        <div class="flex items-center justify-between border-b border-theme-border pb-3">
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary theme-ui-control flex items-center gap-2">
                    <FileEdit class="w-4 h-4 text-theme-accent" />
                    <span>{{ __('Rewrite Rules (.htaccess)') }}</span>
                </h3>
                <span class="text-[11px] text-theme-text-secondary font-mono mt-0.5 block">
                    {{ domain.document_root }}/.htaccess
                </span>
            </div>

            <button @click="emit('close')" class="text-theme-text-muted hover:text-theme-text-primary p-1">
                <X class="w-4 h-4" />
            </button>
        </div>

        <!-- Select Template Dropdown -->
        <div class="bg-theme-bg-surface/50 border border-theme-border rounded-theme-lg p-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <label class="text-xs font-bold text-theme-text-primary uppercase tracking-wider theme-ui-control whitespace-nowrap">
                Select Template
            </label>

            <div class="w-full sm:w-80 flex items-center gap-2">
                <select
                    v-model="selectedTemplate"
                    @change="applyTemplate"
                    class="w-full px-3 py-2 bg-theme-bg-input border border-theme-border rounded-theme-md text-xs text-theme-text-primary focus:outline-none focus:border-theme-accent transition-colors"
                >
                    <option value="" disabled>{{ __('-- Choose Rewrite Template --') }}</option>
                    <option
                        v-for="tpl in REWRITE_TEMPLATES"
                        :key="tpl.id"
                        :value="tpl.id"
                    >
                        {{ tpl.label }}
                    </option>
                </select>
            </div>
        </div>

        <!-- Editable Code Box -->
        <div class="space-y-3">
            <UiTextarea
                v-model="saveForm.content"
                :rows="14"
                mono
                placeholder="# Enter OpenLiteSpeed rewrite rules or insert from dropdown above"
                spellcheck="false"
            />

            <div class="flex items-center justify-between">
                <span class="text-[11px] text-theme-text-muted">
                    OpenLiteSpeed supports standard Apache mod_rewrite directives.
                </span>

                <UiButton
                    variant="primary"
                    @click="saveHtaccess"
                    :disabled="saveForm.processing"
                    :loading="saveForm.processing"
                >
                    <Save v-if="!saveForm.processing" class="w-3.5 h-3.5" />
                    <span>{{ saveForm.processing ? 'Saving...' : 'Save Rewrite Rules' }}</span>
                </UiButton>
            </div>
        </div>
    </div>
</template>

