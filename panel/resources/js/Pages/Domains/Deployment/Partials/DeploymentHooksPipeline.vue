<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowDown,
    ArrowUp,
    Check,
    ChevronDown,
    ChevronUp,
    GripVertical,
    Lock,
    Pause,
    Pencil,
    Play,
    Plus,
    Terminal,
    Trash2,
    User,
} from '@lucide/vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiModal from '@shared/Ui/UiModal.vue';
import {
    buildFlatPipeline,
    commitPipelineReorder,
    DEFAULT_PLACEHOLDERS,
    DEFAULT_SLOTS,
    DEFAULT_STAGES,
    extractApiErrorMessage,
    moveHookInFlatPipeline,
    referencesReleasePlaceholder,
    reorderFlatPipelineByIndex,
    type DeploymentHook,
    type DeploymentHookSlot,
    type DeploymentPipelinePayload,
    type FlatPipelineItem,
    type HookReorderEntry,
    type PipelinePlaceholderMeta,
    type PipelineSlotMeta,
    type PipelineStage,
} from '@domains/Deployment/Utils/pipelineOrder';

const props = defineProps<{
    domain: {
        id: number;
        name: string;
    };
    pipeline: DeploymentPipelinePayload;
}>();

const stages = computed<PipelineStage[]>(() =>
    props.pipeline?.stages?.length === 4 ? props.pipeline.stages : [...DEFAULT_STAGES],
);

const slots = computed<PipelineSlotMeta[]>(() =>
    props.pipeline?.slots?.length ? props.pipeline.slots : [...DEFAULT_SLOTS],
);

const placeholders = computed<PipelinePlaceholderMeta[]>(() =>
    props.pipeline?.placeholders?.length ? props.pipeline.placeholders : [...DEFAULT_PLACEHOLDERS],
);

const slotLabels = computed<Record<DeploymentHookSlot, string>>(() => {
    const map = {} as Record<DeploymentHookSlot, string>;
    for (const s of slots.value) {
        map[s.key] = s.label;
    }
    return map;
});

const flatItems = ref<FlatPipelineItem[]>(
    buildFlatPipeline(stages.value, props.pipeline?.hooks ?? []),
);

const savedInstallScript = ref<string>(props.pipeline?.install_script ?? '');
const installScriptDraft = ref<string>(props.pipeline?.install_script ?? '');
const isInstallEditorOpen = ref<boolean>(false);
const isSavingInstallScript = ref<boolean>(false);
const installScriptError = ref<string>('');
const installScriptSaved = ref<boolean>(false);
const installTextarea = ref<HTMLTextAreaElement | null>(null);

const isReordering = ref<boolean>(false);
const reorderError = ref<string>('');
const reorderSaved = ref<boolean>(false);

const draggingIndex = ref<number | null>(null);
const dropTargetIndex = ref<number | null>(null);
const dropTargetPosition = ref<'before' | 'after' | null>(null);
const dropAtBottomActive = ref<boolean>(false);

watch(
    () => props.pipeline,
    (nextPipeline) => {
        const nextStages = nextPipeline?.stages?.length === 4 ? nextPipeline.stages : [...DEFAULT_STAGES];
        flatItems.value = buildFlatPipeline(nextStages, nextPipeline?.hooks ?? []);
        const nextScript = nextPipeline?.install_script ?? '';
        savedInstallScript.value = nextScript;
        if (!isSavingInstallScript.value) {
            installScriptDraft.value = nextScript;
        }
    },
    { deep: true },
);

const customHooksCount = computed(
    () => flatItems.value.filter((item) => item.type === 'hook').length,
);

const installScriptPresets = [
    {
        label: 'Composer (Prod)',
        script: '{{composer}} install --no-dev --optimize-autoloader --no-interaction',
    },
    {
        label: 'NPM Build',
        script: 'npm ci && npm run build',
    },
    {
        label: 'Composer + NPM',
        script: '{{composer}} install --no-dev --optimize-autoloader --no-interaction\nnpm ci && npm run build',
    },
];

const hasInstallScript = computed(() => savedInstallScript.value.trim().length > 0);
const isInstallDraftDirty = computed(() => installScriptDraft.value !== savedInstallScript.value);

function csrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function insertIntoTextarea(
    el: HTMLTextAreaElement | null,
    currentValue: string,
    token: string,
    setter: (value: string) => void,
) {
    if (!el) {
        setter(currentValue ? `${currentValue} ${token}` : token);
        return;
    }

    const start = el.selectionStart ?? currentValue.length;
    const end = el.selectionEnd ?? currentValue.length;
    const before = currentValue.slice(0, start);
    const after = currentValue.slice(end);
    setter(`${before}${token}${after}`);

    requestAnimationFrame(() => {
        el.focus();
        const cursor = start + token.length;
        el.setSelectionRange(cursor, cursor);
    });
}

function applyInstallPreset(script: string) {
    installScriptDraft.value = script;
    installScriptError.value = '';
    installScriptSaved.value = false;
    isInstallEditorOpen.value = true;
}

function insertInstallPlaceholder(token: string) {
    insertIntoTextarea(installTextarea.value, installScriptDraft.value, token, (val) => {
        installScriptDraft.value = val;
    });
}

function cancelInstallEdit() {
    installScriptDraft.value = savedInstallScript.value;
    installScriptError.value = '';
    isInstallEditorOpen.value = false;
}

async function saveInstallScript(overrideValue?: string) {
    if (isSavingInstallScript.value) {
        return;
    }

    const valueToSave = overrideValue !== undefined ? overrideValue : installScriptDraft.value;
    isSavingInstallScript.value = true;
    installScriptError.value = '';
    installScriptSaved.value = false;

    try {
        const response = await fetch(`/domains/${props.domain.id}/deploy/pipeline/install-script`, {
            method: 'PUT',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                install_script: valueToSave,
            }),
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            installScriptError.value = extractApiErrorMessage(
                payload,
                'Unable to save Stage 2 install script.',
            );
            return;
        }

        const persisted = typeof payload.install_script === 'string' ? payload.install_script : '';
        savedInstallScript.value = persisted;
        installScriptDraft.value = persisted;
        installScriptSaved.value = true;
        setTimeout(() => {
            installScriptSaved.value = false;
        }, 2500);
    } catch (err) {
        installScriptError.value = extractApiErrorMessage(
            err,
            'Network error while saving Stage 2 install script.',
        );
    } finally {
        isSavingInstallScript.value = false;
    }
}

async function persistReorder(previousItems: FlatPipelineItem[], nextItems: FlatPipelineItem[]) {
    if (isReordering.value) {
        return;
    }

    isReordering.value = true;
    reorderError.value = '';
    reorderSaved.value = false;
    flatItems.value = nextItems;

    const result = await commitPipelineReorder(
        previousItems,
        nextItems,
        async (hooksPayload: HookReorderEntry[]) => {
            const response = await fetch(`/domains/${props.domain.id}/deploy/hooks/reorder`, {
                method: 'PUT',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ hooks: hooksPayload }),
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw data;
            }

            return (data?.pipeline as DeploymentPipelinePayload | undefined) ?? null;
        },
    );

    flatItems.value = result.items;
    isReordering.value = false;

    if (!result.ok) {
        reorderError.value = result.error;
        return;
    }

    reorderSaved.value = true;
    setTimeout(() => {
        reorderSaved.value = false;
    }, 2000);
}

// Drag-and-drop handlers
function clearDragState() {
    draggingIndex.value = null;
    dropTargetIndex.value = null;
    dropTargetPosition.value = null;
    dropAtBottomActive.value = false;
}

function onHookDragStart(index: number, event: DragEvent) {
    const item = flatItems.value[index];
    if (!item || item.type !== 'hook' || isReordering.value) {
        event.preventDefault();
        return;
    }

    draggingIndex.value = index;
    reorderError.value = '';
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(item.hook.id));
    }
}

function onItemDragOver(index: number, event: DragEvent) {
    if (draggingIndex.value === null) {
        return;
    }

    event.preventDefault();
    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
    }

    const targetEl = event.currentTarget as HTMLElement | null;
    if (!targetEl) {
        return;
    }

    const rect = targetEl.getBoundingClientRect();
    const midpoint = rect.top + rect.height / 2;
    dropTargetIndex.value = index;
    dropTargetPosition.value = event.clientY < midpoint ? 'before' : 'after';
    dropAtBottomActive.value = false;
}

function onItemDrop(index: number, event: DragEvent) {
    event.preventDefault();
    const fromIndex = draggingIndex.value;
    const position = dropTargetPosition.value ?? 'after';
    clearDragState();

    if (fromIndex === null) {
        return;
    }

    const desiredInsertion = position === 'before' ? index : index + 1;
    const toIndex = desiredInsertion > fromIndex ? desiredInsertion - 1 : desiredInsertion;
    if (toIndex === fromIndex) {
        return;
    }

    const previousItems = [...flatItems.value];
    const nextItems = reorderFlatPipelineByIndex(previousItems, fromIndex, toIndex);
    void persistReorder(previousItems, nextItems);
}

function onBottomZoneDragOver(event: DragEvent) {
    if (draggingIndex.value === null) {
        return;
    }
    event.preventDefault();
    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
    }
    dropTargetIndex.value = null;
    dropTargetPosition.value = null;
    dropAtBottomActive.value = true;
}

function onBottomZoneDrop(event: DragEvent) {
    event.preventDefault();
    const fromIndex = draggingIndex.value;
    clearDragState();

    if (fromIndex === null) {
        return;
    }

    const toIndex = flatItems.value.length - 1;
    if (toIndex === fromIndex) {
        return;
    }

    const previousItems = [...flatItems.value];
    const nextItems = reorderFlatPipelineByIndex(previousItems, fromIndex, toIndex);
    void persistReorder(previousItems, nextItems);
}

function moveHookByDirection(hookId: number, direction: -1 | 1) {
    if (isReordering.value) {
        return;
    }

    const previousItems = [...flatItems.value];
    const nextItems = moveHookInFlatPipeline(previousItems, hookId, direction);
    if (nextItems.every((item, idx) => item.key === previousItems[idx]?.key)) {
        return;
    }

    void persistReorder(previousItems, nextItems);
}

// Add / Edit Hook Modal
const showModal = ref(false);
const editingHook = ref<DeploymentHook | null>(null);
const hookScriptTextarea = ref<HTMLTextAreaElement | null>(null);

const hookForm = useForm({
    name: '',
    slot: 'after_purge' as DeploymentHookSlot,
    script: '',
    is_active: true,
});

const hasBeforeCloneReleaseWarning = computed(
    () => hookForm.slot === 'before_clone' && referencesReleasePlaceholder(hookForm.script),
);

function openCreateModal() {
    editingHook.value = null;
    hookForm.reset();
    hookForm.clearErrors();
    hookForm.name = '';
    hookForm.slot = 'after_purge';
    hookForm.script = '';
    hookForm.is_active = true;
    showModal.value = true;
}

function openEditModal(hook: DeploymentHook) {
    editingHook.value = hook;
    hookForm.clearErrors();
    hookForm.name = hook.name;
    hookForm.slot = hook.slot;
    hookForm.script = hook.script;
    hookForm.is_active = hook.is_active ?? true;
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    editingHook.value = null;
}

function insertHookPlaceholder(token: string) {
    insertIntoTextarea(hookScriptTextarea.value, hookForm.script, token, (val) => {
        hookForm.script = val;
    });
}

function submitHook() {
    if (editingHook.value) {
        hookForm.put(`/domains/${props.domain.id}/deploy/hooks/${editingHook.value.id}`, {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
        return;
    }

    hookForm.post(`/domains/${props.domain.id}/deploy/hooks`, {
        preserveScroll: true,
        onSuccess: () => closeModal(),
    });
}

function toggleHookActive(hook: DeploymentHook) {
    router.put(
        `/domains/${props.domain.id}/deploy/hooks/${hook.id}`,
        {
            name: hook.name,
            slot: hook.slot,
            script: hook.script,
            is_active: !hook.is_active,
        },
        { preserveScroll: true },
    );
}

function deleteHook(hook: DeploymentHook) {
    router.delete(`/domains/${props.domain.id}/deploy/hooks/${hook.id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <UiCard>
        <!-- Pipeline Header -->
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-theme-border-subtle pb-4">
            <div class="space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-base font-semibold text-theme-text-primary">
                        4-Stage Deployment Pipeline
                    </h3>
                    <UiBadge variant="neutral">
                        {{ customHooksCount }} {{ customHooksCount === 1 ? 'Custom Hook' : 'Custom Hooks' }}
                    </UiBadge>
                    <UiBadge v-if="reorderSaved" variant="success">
                        <Check class="h-3 w-3" />
                        Order Saved
                    </UiBadge>
                    <UiBadge v-else-if="isReordering" variant="info">
                        Saving order...
                    </UiBadge>
                </div>
                <p class="text-xs text-theme-text-secondary">
                    Steps run strictly top-to-bottom. Drag custom hooks or use the arrow buttons to position them around the 4 locked stages.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <UiButton
                    variant="primary"
                    size="sm"
                    @click="openCreateModal"
                >
                    <Plus class="h-4 w-4" />
                    Add Hook
                </UiButton>
            </div>
        </div>

        <!-- Reorder Error Banner (e.g., {{release}} dragged into before_clone) -->
        <div v-if="reorderError" class="mt-4">
            <UiAlert variant="danger">
                <div class="flex items-start justify-between gap-3">
                    <span>{{ reorderError }}</span>
                    <button
                        type="button"
                        class="shrink-0 text-xs font-semibold underline opacity-80 hover:opacity-100 cursor-pointer"
                        @click="reorderError = ''"
                    >
                        Dismiss
                    </button>
                </div>
            </UiAlert>
        </div>

        <!-- Linear Top-to-Bottom Pipeline -->
        <div class="mt-5 space-y-2.5" role="list" aria-label="Deployment pipeline execution order">
            <template v-for="(item, index) in flatItems" :key="item.key">
                <!-- Drop indicator BEFORE item -->
                <div
                    v-if="dropTargetIndex === index && dropTargetPosition === 'before'"
                    class="h-1 rounded-theme-badge bg-theme-accent transition-all"
                />

                <!-- Locked Default Stage Card -->
                <div
                    v-if="item.type === 'stage'"
                    role="listitem"
                    class="rounded-theme-md border border-theme-border-subtle bg-theme-bg-surface p-4 transition-colors"
                    @dragover="onItemDragOver(index, $event)"
                    @drop="onItemDrop(index, $event)"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex items-start gap-3 min-w-0 flex-1">
                            <span
                                class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-theme-badge bg-theme-accent text-xs font-semibold text-theme-text-on-accent"
                            >
                                {{ item.stage.step }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h4 class="text-sm font-semibold text-theme-text-primary">
                                        {{ item.stage.title }}
                                    </h4>
                                    <UiBadge variant="info">
                                        <Lock class="h-3 w-3" />
                                        Default Stage
                                    </UiBadge>
                                    <UiBadge
                                        v-if="item.stage.editable"
                                        :variant="hasInstallScript ? 'success' : 'neutral'"
                                    >
                                        {{ hasInstallScript ? 'Script Configured' : 'Optional' }}
                                    </UiBadge>
                                </div>
                                <p class="mt-1 text-xs text-theme-text-secondary">
                                    {{ item.stage.description }}
                                </p>
                            </div>
                        </div>

                        <div v-if="item.stage.editable" class="flex shrink-0 items-center gap-2">
                            <UiBadge v-if="installScriptSaved" variant="success">
                                <Check class="h-3 w-3" />
                                Saved
                            </UiBadge>
                            <UiButton
                                size="xs"
                                variant="secondary"
                                :aria-expanded="isInstallEditorOpen"
                                @click="isInstallEditorOpen = !isInstallEditorOpen"
                            >
                                <Terminal class="h-3.5 w-3.5" />
                                {{ isInstallEditorOpen ? 'Hide Script' : (hasInstallScript ? 'Edit Script' : 'Configure Script') }}
                                <ChevronUp v-if="isInstallEditorOpen" class="h-3.5 w-3.5" />
                                <ChevronDown v-else class="h-3.5 w-3.5" />
                            </UiButton>
                        </div>
                    </div>

                    <!-- Stage 2: Collapsed Summary or Inline Bash Script Editor -->
                    <div v-if="item.stage.editable" class="mt-3 border-t border-theme-border-subtle pt-3">
                        <!-- Collapsed Preview -->
                        <div v-if="!isInstallEditorOpen">
                            <div
                                v-if="hasInstallScript"
                                class="rounded-theme-sm border border-theme-border bg-theme-bg-card px-3 py-2 font-mono text-xs text-theme-text-primary whitespace-pre-wrap break-all"
                            >
                                {{ savedInstallScript }}
                            </div>
                            <p
                                v-else
                                class="text-xs italic text-theme-text-muted"
                            >
                                No install script configured — step will be skipped
                            </p>
                        </div>

                        <!-- Expanded Inline Editor -->
                        <div v-else class="space-y-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="inline-flex items-center gap-1.5 text-xs text-theme-text-secondary">
                                    <User class="h-3.5 w-3.5 text-theme-accent" />
                                    <span>Executes under the <strong class="font-semibold text-theme-text-primary">Domain user</strong> inside the release directory.</span>
                                </div>

                                <!-- Quick-Fill Preset Buttons -->
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="text-[11px] font-medium text-theme-text-muted">Quick fill:</span>
                                    <button
                                        v-for="preset in installScriptPresets"
                                        :key="preset.label"
                                        type="button"
                                        class="rounded-theme-sm border border-theme-border bg-theme-bg-card px-2 py-0.5 text-[11px] font-medium text-theme-text-secondary transition hover:border-theme-accent hover:text-theme-text-primary cursor-pointer"
                                        @click="applyInstallPreset(preset.script)"
                                    >
                                        {{ preset.label }}
                                    </button>
                                </div>
                            </div>

                            <!-- Placeholders -->
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="text-[11px] font-medium text-theme-text-muted">Placeholders:</span>
                                <button
                                    v-for="placeholder in placeholders"
                                    :key="placeholder.token"
                                    type="button"
                                    :title="placeholder.description"
                                    class="rounded-theme-sm border border-theme-border bg-theme-bg-card px-1.5 py-0.5 font-mono text-[11px] text-theme-text-primary transition hover:border-theme-accent cursor-pointer"
                                    @click="insertInstallPlaceholder(placeholder.token)"
                                >
                                    {{ placeholder.token }}
                                </button>
                            </div>

                            <textarea
                                ref="installTextarea"
                                v-model="installScriptDraft"
                                rows="5"
                                placeholder="{{composer}} install --no-dev --optimize-autoloader --no-interaction&#10;npm ci && npm run build"
                                class="w-full rounded-theme-md border border-theme-border bg-theme-bg-input p-3 font-mono text-xs text-theme-text-primary placeholder:text-theme-text-muted focus:border-theme-accent focus:outline-none focus:ring-2 focus:ring-theme-accent/20"
                            />

                            <p v-if="!installScriptDraft.trim()" class="text-xs italic text-theme-text-muted">
                                No install script configured — step will be skipped
                            </p>

                            <UiAlert v-if="installScriptError" variant="danger">
                                {{ installScriptError }}
                            </UiAlert>

                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <UiButton
                                    v-if="hasInstallScript || installScriptDraft.trim().length > 0"
                                    size="xs"
                                    variant="ghost"
                                    :disabled="isSavingInstallScript"
                                    @click="saveInstallScript('')"
                                >
                                    Clear Script
                                </UiButton>
                                <span v-else />

                                <div class="flex items-center gap-2">
                                    <UiButton
                                        size="xs"
                                        variant="secondary"
                                        :disabled="isSavingInstallScript"
                                        @click="cancelInstallEdit"
                                    >
                                        Cancel
                                    </UiButton>
                                    <UiButton
                                        size="xs"
                                        variant="primary"
                                        :loading="isSavingInstallScript"
                                        :disabled="isSavingInstallScript || (!isInstallDraftDirty && hasInstallScript)"
                                        @click="saveInstallScript()"
                                    >
                                        Save Install Script
                                    </UiButton>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Draggable Custom Hook Card -->
                <div
                    v-else
                    role="listitem"
                    draggable="true"
                    class="group flex flex-wrap items-center justify-between gap-3 rounded-theme-md border border-theme-border bg-theme-bg-card px-3.5 py-3 transition-all"
                    :class="[
                        draggingIndex === index ? 'opacity-50 border-theme-accent' : 'hover:border-theme-border-strong',
                        !item.hook.is_active ? 'opacity-75' : '',
                    ]"
                    @dragstart="onHookDragStart(index, $event)"
                    @dragover="onItemDragOver(index, $event)"
                    @drop="onItemDrop(index, $event)"
                    @dragend="clearDragState"
                >
                    <div class="flex items-start gap-2.5 min-w-0 flex-1">
                        <span
                            class="mt-0.5 shrink-0 cursor-grab text-theme-text-muted transition group-hover:text-theme-text-primary active:cursor-grabbing"
                            title="Drag to reorder hook"
                            aria-hidden="true"
                        >
                            <GripVertical class="h-4 w-4" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="truncate text-sm font-medium"
                                    :class="item.hook.is_active ? 'text-theme-text-primary' : 'text-theme-text-muted line-through'"
                                >
                                    {{ item.hook.name }}
                                </span>
                                <UiBadge variant="primary">
                                    {{ slotLabels[item.hook.slot] ?? item.hook.slot }}
                                </UiBadge>
                                <UiBadge v-if="!item.hook.is_active" variant="neutral">
                                    Paused
                                </UiBadge>
                                <UiBadge
                                    v-if="item.hook.slot === 'before_clone' && referencesReleasePlaceholder(item.hook.script)"
                                    variant="warning"
                                >
                                    <AlertTriangle class="h-3 w-3" />
                                    Uses &#123;&#123;release&#125;&#125; before clone
                                </UiBadge>
                            </div>
                            <p class="mt-1 truncate font-mono text-xs text-theme-text-muted">
                                {{ item.hook.script }}
                            </p>
                        </div>
                    </div>

                    <!-- Keyboard-Accessible Controls + Actions -->
                    <div class="flex shrink-0 items-center gap-1">
                        <UiButton
                            size="xs"
                            variant="ghost"
                            :disabled="index === 0 || isReordering"
                            :aria-label="`Move ${item.hook.name} up`"
                            title="Move Up"
                            @click="moveHookByDirection(item.hook.id, -1)"
                        >
                            <ArrowUp class="h-3.5 w-3.5" />
                        </UiButton>
                        <UiButton
                            size="xs"
                            variant="ghost"
                            :disabled="index === flatItems.length - 1 || isReordering"
                            :aria-label="`Move ${item.hook.name} down`"
                            title="Move Down"
                            @click="moveHookByDirection(item.hook.id, 1)"
                        >
                            <ArrowDown class="h-3.5 w-3.5" />
                        </UiButton>
                        <UiButton
                            size="xs"
                            variant="ghost"
                            :aria-label="item.hook.is_active ? `Pause ${item.hook.name}` : `Enable ${item.hook.name}`"
                            :title="item.hook.is_active ? 'Pause Hook' : 'Enable Hook'"
                            @click="toggleHookActive(item.hook)"
                        >
                            <Pause v-if="item.hook.is_active" class="h-3.5 w-3.5" />
                            <Play v-else class="h-3.5 w-3.5" />
                        </UiButton>
                        <UiButton
                            size="xs"
                            variant="ghost"
                            :aria-label="`Edit ${item.hook.name}`"
                            title="Edit Hook"
                            @click="openEditModal(item.hook)"
                        >
                            <Pencil class="h-3.5 w-3.5" />
                        </UiButton>
                        <UiButton
                            size="xs"
                            variant="danger-soft"
                            :aria-label="`Delete ${item.hook.name}`"
                            title="Delete Hook"
                            @click="deleteHook(item.hook)"
                        >
                            <Trash2 class="h-3.5 w-3.5" />
                        </UiButton>
                    </div>
                </div>

                <!-- Drop indicator AFTER item -->
                <div
                    v-if="dropTargetIndex === index && dropTargetPosition === 'after'"
                    class="h-1 rounded-theme-badge bg-theme-accent transition-all"
                />
            </template>

            <!-- Explicit Bottom Drop Zone (After Stage 4 / after_purge) -->
            <div
                v-if="draggingIndex !== null"
                class="rounded-theme-md border border-dashed px-3 py-2 text-center text-xs transition-colors"
                :class="dropAtBottomActive
                    ? 'border-theme-accent bg-theme-accent-subtle text-theme-accent-text font-medium'
                    : 'border-theme-border text-theme-text-muted'"
                @dragover="onBottomZoneDragOver"
                @dragleave="dropAtBottomActive = false"
                @drop="onBottomZoneDrop"
            >
                Drop here to run after Purge Old Releases
            </div>
        </div>
    </UiCard>

    <!-- Add / Edit Custom Hook Modal -->
    <UiModal
        :show="showModal"
        :title="editingHook ? 'Edit Deployment Hook' : 'Add Deployment Hook'"
        max-width="2xl"
        @close="closeModal"
    >
        <form class="space-y-4" @submit.prevent="submitHook">
            <div class="flex items-center gap-2 rounded-theme-md border border-theme-border-subtle bg-theme-bg-surface px-3 py-2 text-xs text-theme-text-secondary">
                <User class="h-4 w-4 shrink-0 text-theme-accent" />
                <span>
                    Custom hooks execute under the <strong class="font-semibold text-theme-text-primary">Domain user</strong>.
                    <template v-if="!editingHook">
                        New hooks are appended to the bottom of the pipeline (<strong class="font-semibold text-theme-text-primary">After Purge Old Releases</strong>) and can be dragged anywhere afterward.
                    </template>
                </span>
            </div>

            <UiInput
                v-model="hookForm.name"
                label="Hook Name *"
                placeholder="e.g. Run Database Migrations"
                :error="hookForm.errors.name"
                required
            />

            <!-- Accessible Slot Selector when editing an existing hook -->
            <div v-if="editingHook">
                <label
                    for="hook-slot-select"
                    class="mb-1.5 block text-xs font-medium text-theme-text-primary"
                >
                    Pipeline Position
                </label>
                <select
                    id="hook-slot-select"
                    v-model="hookForm.slot"
                    class="w-full rounded-theme-md border border-theme-border bg-theme-bg-input px-3 py-2 text-sm text-theme-text-primary focus:border-theme-accent focus:outline-none focus:ring-2 focus:ring-theme-accent/20"
                >
                    <option v-for="slot in slots" :key="slot.key" :value="slot.key">
                        {{ slot.label }}
                    </option>
                </select>
                <p v-if="hookForm.errors.slot" class="mt-1 text-xs text-theme-danger-text">
                    {{ hookForm.errors.slot }}
                </p>
            </div>

            <!-- Warning when {{release}} is used in before_clone -->
            <UiAlert v-if="hasBeforeCloneReleaseWarning" variant="warning">
                <span class="font-medium">
                    <code>&#123;&#123;release&#125;&#125;</code> is not available in <strong>Before Clone Release</strong> because the release directory has not been created yet. Remove <code>&#123;&#123;release&#125;&#125;</code> or move this hook after Stage 1.
                </span>
            </UiAlert>

            <div>
                <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                    <label
                        for="hook-script-textarea"
                        class="text-xs font-medium text-theme-text-primary"
                    >
                        Bash Script *
                    </label>
                    <div class="flex flex-wrap items-center gap-1">
                        <button
                            v-for="placeholder in placeholders"
                            :key="placeholder.token"
                            type="button"
                            :title="placeholder.description"
                            class="rounded-theme-sm border px-1.5 py-0.5 font-mono text-[11px] transition cursor-pointer"
                            :class="hookForm.slot === 'before_clone' && !placeholder.available_in_before_clone
                                ? 'border-theme-warning-border bg-theme-warning-bg text-theme-warning-text'
                                : 'border-theme-border bg-theme-bg-surface text-theme-text-primary hover:border-theme-accent'"
                            @click="insertHookPlaceholder(placeholder.token)"
                        >
                            {{ placeholder.token }}
                        </button>
                    </div>
                </div>
                <textarea
                    id="hook-script-textarea"
                    ref="hookScriptTextarea"
                    v-model="hookForm.script"
                    rows="6"
                    placeholder="cd {{release}} && {{php}} artisan migrate --force"
                    class="w-full rounded-theme-md border border-theme-border bg-theme-bg-input p-3 font-mono text-xs text-theme-text-primary placeholder:text-theme-text-muted focus:border-theme-accent focus:outline-none focus:ring-2 focus:ring-theme-accent/20"
                    :class="hasBeforeCloneReleaseWarning ? 'border-theme-warning-border' : ''"
                    required
                />
                <p v-if="hookForm.errors.script" class="mt-1 text-xs text-theme-danger-text">
                    {{ hookForm.errors.script }}
                </p>
            </div>

            <label class="flex items-center gap-2 text-xs text-theme-text-primary cursor-pointer">
                <input
                    v-model="hookForm.is_active"
                    type="checkbox"
                    class="rounded-theme-sm border-theme-border"
                />
                <span>Hook is active and will run during deployments</span>
            </label>

            <div class="flex justify-end gap-2 border-t border-theme-border-subtle pt-4">
                <UiButton type="button" variant="secondary" @click="closeModal">
                    Cancel
                </UiButton>
                <UiButton
                    type="submit"
                    :loading="hookForm.processing"
                    :disabled="hookForm.processing || hasBeforeCloneReleaseWarning"
                >
                    {{ editingHook ? 'Save Changes' : 'Add Hook' }}
                </UiButton>
            </div>
        </form>
    </UiModal>
</template>
