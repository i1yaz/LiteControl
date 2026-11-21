<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import {
    Plus,
    Trash2,
    Sparkles,
    Save,
} from '@lucide/vue';
import UiModal from '@shared/Ui/UiModal.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';

interface TemplateRecordItem {
    name: string;
    type: string;
    ttl: number;
    priority: number | null;
    content: string;
}

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    template: {
        type: Object as () => Record<string, any> | null,
        default: null,
    },
    allowedRecordTypes: {
        type: Array as () => string[],
        default: () => ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR'],
    },
    supportedPlaceholders: {
        type: Array as () => string[],
        default: () => ['{{DOMAIN}}', '{{IPV4}}', '{{IPV6}}', '{{NS1}}', '{{NS2}}'],
    },
});

const emit = defineEmits(['close']);

const defaultStandardRecords = (): TemplateRecordItem[] => [
    { name: '@', type: 'NS', ttl: 3600, priority: null, content: '{{NS1}}' },
    { name: '@', type: 'NS', ttl: 3600, priority: null, content: '{{NS2}}' },
    { name: '@', type: 'A', ttl: 3600, priority: null, content: '{{IPV4}}' },
    { name: 'www', type: 'A', ttl: 3600, priority: null, content: '{{IPV4}}' },
    { name: 'mail', type: 'A', ttl: 3600, priority: null, content: '{{IPV4}}' },
    { name: '@', type: 'AAAA', ttl: 3600, priority: null, content: '{{IPV6}}' },
    { name: 'www', type: 'AAAA', ttl: 3600, priority: null, content: '{{IPV6}}' },
    { name: 'mail', type: 'AAAA', ttl: 3600, priority: null, content: '{{IPV6}}' },
    { name: '@', type: 'MX', ttl: 3600, priority: 10, content: 'mail.{{DOMAIN}}.' },
    { name: '@', type: 'TXT', ttl: 3600, priority: null, content: '"v=spf1 a mx ip4:{{IPV4}} ~all"' },
];

const form = useForm({
    name: '',
    description: '',
    is_default: false,
    records: defaultStandardRecords(),
});

const isEditMode = computed(() => Boolean(props.template && props.template.id));

watch(
    () => [props.show, props.template],
    ([isOpen]) => {
        if (!isOpen) {
            return;
        }
        form.clearErrors();

        if (props.template) {
            form.name = String(props.template.name || '');
            form.description = String(props.template.description || '');
            form.is_default = Boolean(props.template.is_default);
            form.records = Array.isArray(props.template.records) && props.template.records.length > 0
                ? props.template.records.map((r: Record<string, any>) => ({
                      name: String(r.name || '@'),
                      type: String(r.type || 'A'),
                      ttl: Number(r.ttl || 3600),
                      priority: r.priority !== null && r.priority !== undefined ? Number(r.priority) : null,
                      content: String(r.content || ''),
                  }))
                : defaultStandardRecords();
        } else {
            form.name = '';
            form.description = '';
            form.is_default = false;
            form.records = defaultStandardRecords();
        }
    }
);

const typeOptions = computed(() =>
    props.allowedRecordTypes.map((t) => ({ value: t, label: t }))
);

const addRecordRow = () => {
    form.records.push({
        name: '@',
        type: 'A',
        ttl: 3600,
        priority: null,
        content: '{{IPV4}}',
    });
};

const removeRecordRow = (index: number) => {
    if (form.records.length <= 1) {
        return;
    }
    form.records.splice(index, 1);
};

const loadStandardPreset = () => {
    form.records = defaultStandardRecords();
};

const recordError = (index: number, field: string): string | undefined => {
    const errors = form.errors as Record<string, string>;
    return errors[`records.${index}.${field}`];
};

const submit = () => {
    if (isEditMode.value && props.template?.id) {
        form.put(`/dns/templates/${props.template.id}`, {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
    } else {
        form.post('/dns/templates', {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        });
    }
};
</script>

<template>
    <UiModal
        :show="show"
        :title="isEditMode ? __('Edit DNS Zone Template') : __('Create DNS Zone Template')"
        max-width="2xl"
        @close="emit('close')"
    >
        <form @submit.prevent="submit" class="space-y-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <UiInput
                    id="dns-template-name"
                    v-model="form.name"
                    :label="__('Template Name')"
                    placeholder="Standard Web & Mail"
                    :error="form.errors.name"
                    required
                />

                <UiInput
                    id="dns-template-description"
                    v-model="form.description"
                    :label="__('Description (Optional)')"
                    placeholder="Default web, mail, and SPF records"
                    :error="form.errors.description"
                />
            </div>

            <label class="flex items-center gap-2.5 text-xs font-medium text-theme-text-primary cursor-pointer">
                <input
                    v-model="form.is_default"
                    type="checkbox"
                    class="rounded border-theme-border text-theme-accent focus:ring-theme-accent"
                />
                <span>{{ __('Set as the default template pre-selected when creating new forward zones') }}</span>
            </label>

            <!-- Supported Placeholders Banner -->
            <div class="p-3 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle space-y-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xs font-semibold text-theme-text-secondary flex items-center gap-1.5">
                        <Sparkles class="w-3.5 h-3.5 text-theme-accent" />
                        <span>{{ __('Deterministic Placeholders (Resolved at Zone Creation)') }}</span>
                    </span>
                    <button
                        type="button"
                        @click="loadStandardPreset"
                        class="text-xs font-semibold text-theme-accent hover:underline cursor-pointer"
                    >
                        {{ __('Reset to Standard Web & Mail Preset') }}
                    </button>
                </div>
                <div class="flex flex-wrap items-center gap-1.5">
                    <UiBadge
                        v-for="token in supportedPlaceholders"
                        :key="token"
                        variant="neutral"
                    >
                        <span class="font-mono text-[11px]">{{ token }}</span>
                    </UiBadge>
                    <span
                        class="text-[11px] text-theme-text-muted"
                        v-text="__('Records containing {{IPV6}} are automatically skipped if no IPv6 address is provided at zone creation.')"
                    />
                </div>
            </div>

            <!-- Template Records Table -->
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-theme-text-secondary">
                        {{ __('Template Record Definitions') }} ({{ form.records.length }})
                    </span>
                    <UiButton
                        type="button"
                        variant="secondary"
                        size="sm"
                        @click="addRecordRow"
                    >
                        <Plus class="w-3.5 h-3.5" />
                        <span>{{ __('Add Record Row') }}</span>
                    </UiButton>
                </div>

                <div class="max-h-96 overflow-y-auto space-y-2.5 pr-1">
                    <div
                        v-for="(rec, idx) in form.records"
                        :key="idx"
                        class="grid grid-cols-1 sm:grid-cols-12 gap-2.5 p-3 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle items-start"
                    >
                        <div class="sm:col-span-3">
                            <UiInput
                                :id="`tpl-rec-name-${idx}`"
                                v-model="rec.name"
                                :label="__('Name / Host')"
                                placeholder="@ or www"
                                :error="recordError(idx, 'name')"
                                required
                            />
                        </div>

                        <div class="sm:col-span-2">
                            <UiSelect
                                :id="`tpl-rec-type-${idx}`"
                                v-model="rec.type"
                                :label="__('Type')"
                                :options="typeOptions"
                                :error="recordError(idx, 'type')"
                                required
                            />
                        </div>

                        <div class="sm:col-span-2">
                            <UiInput
                                :id="`tpl-rec-ttl-${idx}`"
                                v-model="rec.ttl"
                                type="number"
                                :label="__('TTL (s)')"
                                placeholder="3600"
                                :error="recordError(idx, 'ttl')"
                                required
                            />
                        </div>

                        <div v-if="rec.type === 'MX'" class="sm:col-span-1">
                            <UiInput
                                :id="`tpl-rec-prio-${idx}`"
                                v-model="rec.priority"
                                type="number"
                                :label="__('Prio')"
                                placeholder="10"
                                :error="recordError(idx, 'priority')"
                            />
                        </div>

                        <div :class="rec.type === 'MX' ? 'sm:col-span-3' : 'sm:col-span-4'">
                            <UiInput
                                :id="`tpl-rec-content-${idx}`"
                                v-model="rec.content"
                                :label="__('Content / Target Placeholder')"
                                placeholder="{{IPV4}} or mail.{{DOMAIN}}."
                                :error="recordError(idx, 'content')"
                                required
                            />
                        </div>

                        <div class="sm:col-span-1 flex sm:justify-end pt-6">
                            <UiButton
                                type="button"
                                variant="danger"
                                size="sm"
                                :disabled="form.records.length <= 1"
                                @click="removeRecordRow(idx)"
                            >
                                <Trash2 class="w-4 h-4" />
                            </UiButton>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-theme-border-subtle">
                <UiButton
                    type="button"
                    variant="secondary"
                    :disabled="form.processing"
                    @click="emit('close')"
                >
                    {{ __('Cancel') }}
                </UiButton>
                <UiButton
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    :disabled="form.processing"
                >
                    <Save class="w-4 h-4" />
                    <span>
                        {{
                            form.processing
                                ? __('Saving Template...')
                                : isEditMode
                                  ? __('Save Template Changes')
                                  : __('Create Zone Template')
                        }}
                    </span>
                </UiButton>
            </div>
        </form>
    </UiModal>
</template>
