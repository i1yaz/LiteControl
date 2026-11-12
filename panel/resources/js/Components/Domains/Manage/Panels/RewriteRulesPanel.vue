<script setup>
import { shallowRef, onMounted } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { FileEdit, Save, RefreshCw, X } from '@lucide/vue';
import { REWRITE_TEMPLATES } from '@/Config/websiteManageConfig';

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
    <div class="bg-theme-bg-card border border-theme-border rounded-xl p-5 shadow-sm space-y-5">
        <!-- Header matching user screenshot -->
        <div class="flex items-center justify-between border-b border-theme-border pb-3">
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary flex items-center gap-2">
                    <FileEdit class="w-4 h-4 text-purple-400" />
                    <span>Rewrite Rules (.htaccess)</span>
                </h3>
                <span class="text-[11px] text-theme-text-secondary font-mono mt-0.5 block">
                    {{ domain.document_root }}/.htaccess
                </span>
            </div>

            <button @click="emit('close')" class="text-theme-text-muted hover:text-theme-text-primary p-1">
                <X class="w-4 h-4" />
            </button>
        </div>

        <!-- Select Template Dropdown (Exact match to uploaded reference) -->
        <div class="bg-theme-bg-surface/50 border border-theme-border rounded-xl p-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <label class="text-xs font-bold text-theme-text-primary uppercase tracking-wider whitespace-nowrap">
                Select Template
            </label>

            <div class="w-full sm:w-80 flex items-center gap-2">
                <select
                    v-model="selectedTemplate"
                    @change="applyTemplate"
                    class="w-full px-3 py-2 bg-theme-bg-surface border border-theme-border rounded-lg text-xs text-theme-text-primary focus:outline-none focus:border-blue-500 transition-colors"
                >
                    <option value="" disabled>-- Choose Rewrite Template --</option>
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

        <!-- Editable Code Box (Matching uploaded reference) -->
        <div class="space-y-3">
            <textarea
                v-model="saveForm.content"
                rows="14"
                class="w-full p-4 bg-slate-950 border border-blue-500/50 rounded-xl font-mono text-xs text-blue-300 leading-relaxed focus:outline-none focus:border-blue-500 shadow-inner resize-y selection:bg-blue-600/40"
                placeholder="# Enter OpenLiteSpeed rewrite rules or insert from dropdown above"
                spellcheck="false"
            ></textarea>

            <div class="flex items-center justify-between">
                <span class="text-[11px] text-theme-text-muted">
                    OpenLiteSpeed supports standard Apache mod_rewrite directives.
                </span>

                <button
                    @click="saveHtaccess"
                    :disabled="saveForm.processing"
                    class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold rounded-lg shadow-sm shadow-blue-600/30 transition-all disabled:opacity-50"
                >
                    <Save class="w-3.5 h-3.5" />
                    <span>{{ saveForm.processing ? 'Saving...' : 'Save Rewrite Rules' }}</span>
                </button>
            </div>
        </div>
    </div>
</template>

