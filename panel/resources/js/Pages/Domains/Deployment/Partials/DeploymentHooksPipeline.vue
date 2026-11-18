<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import UiBadge from '@/Shared/Ui/UiBadge.vue';
import UiButton from '@/Shared/Ui/UiButton.vue';
import UiCard from '@/Shared/Ui/UiCard.vue';
import UiInput from '@/Shared/Ui/UiInput.vue';
import UiModal from '@/Shared/Ui/UiModal.vue';

export type HookStage = 'clone' | 'install' | 'activate' | 'cleanup';
export type HookPosition = 'before' | 'after';

export interface DeploymentHook {
    id: number;
    config_id?: number;
    name: string;
    stage: HookStage | 'build';
    position: HookPosition | 'run';
    script: string;
    sort_order: number;
    is_active: boolean;
}

const props = defineProps<{
    domain: {
        id: number;
        name: string;
    };
    hooks: DeploymentHook[];
}>();

interface StageDefinition {
    key: HookStage;
    step: number;
    title: string;
    coreAction: string;
}

const stages: StageDefinition[] = [
    {
        key: 'clone',
        step: 1,
        title: 'Clone Release',
        coreAction: 'Fetch clean archive for target ref (no .git leftovers) & symlink shared paths.',
    },
    {
        key: 'install',
        step: 2,
        title: 'Install Dependencies',
        coreAction: 'Prepare release environment and run configured dependency/build steps.',
    },
    {
        key: 'activate',
        step: 3,
        title: 'Activate Release',
        coreAction: 'Atomic symlink swap, detached PHP-FPM/LSPHP reload & HTTP health check.',
    },
    {
        key: 'cleanup',
        step: 4,
        title: 'Purge Old Releases',
        coreAction: 'Prune expired release directories beyond the retention limit.',
    },
];

const templateVariables = [
    '{{release}}',
    '{{project}}',
    '{{shared}}',
    '{{php}}',
    '{{composer}}',
    '{{sha}}',
    '{{branch}}',
];

const showModal = ref(false);
const editingHook = ref<DeploymentHook | null>(null);
const scriptTextarea = ref<HTMLTextAreaElement | null>(null);

const hookForm = useForm({
    name: '',
    stage: 'install' as HookStage,
    position: 'after' as HookPosition,
    script: '',
    sort_order: 0,
    is_active: true,
});

function hooksFor(stage: HookStage, position: HookPosition): DeploymentHook[] {
    return props.hooks
        .filter((h) => {
            const normalizedStage = h.stage === 'build' ? 'install' : h.stage;
            const normalizedPos = h.position === 'run' ? 'after' : h.position;
            return normalizedStage === stage && normalizedPos === position;
        })
        .sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0) || a.id - b.id);
}

const totalHooksCount = computed(() => props.hooks.length);

function openCreateModal(stage: HookStage, position: HookPosition) {
    editingHook.value = null;
    const existing = hooksFor(stage, position);
    hookForm.reset();
    hookForm.clearErrors();
    hookForm.name = '';
    hookForm.stage = stage;
    hookForm.position = position;
    hookForm.script = '';
    hookForm.sort_order = existing.length;
    hookForm.is_active = true;
    showModal.value = true;
}

function openEditModal(hook: DeploymentHook) {
    editingHook.value = hook;
    hookForm.clearErrors();
    hookForm.name = hook.name;
    hookForm.stage = (hook.stage === 'build' ? 'install' : hook.stage) as HookStage;
    hookForm.position = (hook.position === 'run' ? 'after' : hook.position) as HookPosition;
    hookForm.script = hook.script;
    hookForm.sort_order = hook.sort_order ?? 0;
    hookForm.is_active = hook.is_active ?? true;
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    editingHook.value = null;
}

function insertVariable(variable: string) {
    const el = scriptTextarea.value;
    if (!el) {
        hookForm.script = hookForm.script ? `${hookForm.script} ${variable}` : variable;
        return;
    }

    const start = el.selectionStart ?? hookForm.script.length;
    const end = el.selectionEnd ?? hookForm.script.length;
    const before = hookForm.script.slice(0, start);
    const after = hookForm.script.slice(end);
    hookForm.script = `${before}${variable}${after}`;

    requestAnimationFrame(() => {
        el.focus();
        const cursor = start + variable.length;
        el.setSelectionRange(cursor, cursor);
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

function deleteHook(hook: DeploymentHook) {
    router.delete(`/domains/${props.domain.id}/deploy/hooks/${hook.id}`, {
        preserveScroll: true,
    });
}

function toggleHookActive(hook: DeploymentHook) {
    router.put(
        `/domains/${props.domain.id}/deploy/hooks/${hook.id}`,
        {
            name: hook.name,
            stage: hook.stage,
            position: hook.position,
            script: hook.script,
            sort_order: hook.sort_order,
            is_active: !hook.is_active,
        },
        { preserveScroll: true },
    );
}

function moveHook(stage: HookStage, position: HookPosition, index: number, direction: -1 | 1) {
    const list = hooksFor(stage, position);
    const targetIndex = index + direction;
    if (targetIndex < 0 || targetIndex >= list.length) {
        return;
    }

    const current = list[index];
    const swapWith = list[targetIndex];

    router.put(
        `/domains/${props.domain.id}/deploy/hooks/${current.id}`,
        {
            name: current.name,
            stage: current.stage,
            position: current.position,
            script: current.script,
            sort_order: targetIndex,
            is_active: current.is_active,
        },
        { preserveScroll: true },
    );

    router.put(
        `/domains/${props.domain.id}/deploy/hooks/${swapWith.id}`,
        {
            name: swapWith.name,
            stage: swapWith.stage,
            position: swapWith.position,
            script: swapWith.script,
            sort_order: index,
            is_active: swapWith.is_active,
        },
        { preserveScroll: true },
    );
}
</script>

<template>
    <UiCard>
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--theme-border)] pb-4">
            <div>
                <h3 class="text-base font-semibold text-[var(--theme-text)]">
                    4-Stage Lifecycle Hook Pipeline
                </h3>
                <p class="mt-0.5 text-xs text-[var(--theme-text-muted)]">
                    Attach custom bash scripts before or after each atomic deployment stage.
                </p>
            </div>
            <UiBadge variant="neutral">
                {{ totalHooksCount }} {{ totalHooksCount === 1 ? 'Hook' : 'Hooks' }}
            </UiBadge>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div
                v-for="stage in stages"
                :key="stage.key"
                class="flex flex-col rounded-lg border border-[var(--theme-border)] bg-[var(--theme-surface-muted)] p-4"
            >
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span
                            class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-[var(--theme-primary)] text-xs font-semibold text-white"
                        >
                            {{ stage.step }}
                        </span>
                        <h4 class="text-sm font-semibold text-[var(--theme-text)]">
                            {{ stage.title }}
                        </h4>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <UiButton
                            size="xs"
                            variant="secondary"
                            @click="openCreateModal(stage.key, 'before')"
                        >
                            + Before
                        </UiButton>
                        <UiButton
                            size="xs"
                            variant="secondary"
                            @click="openCreateModal(stage.key, 'after')"
                        >
                            + After
                        </UiButton>
                    </div>
                </div>

                <!-- Before Hooks -->
                <div v-if="hooksFor(stage.key, 'before').length > 0" class="mt-3 space-y-2">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-[var(--theme-text-muted)]">
                        Before
                    </div>
                    <div
                        v-for="(hook, idx) in hooksFor(stage.key, 'before')"
                        :key="hook.id"
                        class="flex items-center justify-between gap-2 rounded-md border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-2"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span
                                    class="truncate text-xs font-medium"
                                    :class="hook.is_active ? 'text-[var(--theme-text)]' : 'text-[var(--theme-text-muted)] line-through'"
                                >
                                    {{ hook.name }}
                                </span>
                                <UiBadge v-if="!hook.is_active" variant="neutral">Paused</UiBadge>
                            </div>
                            <p class="mt-0.5 truncate font-mono text-[11px] text-[var(--theme-text-muted)]">
                                {{ hook.script }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <UiButton
                                size="xs"
                                variant="ghost"
                                :disabled="idx === 0"
                                title="Move Up"
                                @click="moveHook(stage.key, 'before', idx, -1)"
                            >
                                ↑
                            </UiButton>
                            <UiButton
                                size="xs"
                                variant="ghost"
                                :disabled="idx === hooksFor(stage.key, 'before').length - 1"
                                title="Move Down"
                                @click="moveHook(stage.key, 'before', idx, 1)"
                            >
                                ↓
                            </UiButton>
                            <UiButton
                                size="xs"
                                variant="ghost"
                                @click="toggleHookActive(hook)"
                            >
                                {{ hook.is_active ? 'Pause' : 'Enable' }}
                            </UiButton>
                            <UiButton
                                size="xs"
                                variant="ghost"
                                @click="openEditModal(hook)"
                            >
                                Edit
                            </UiButton>
                            <UiButton
                                size="xs"
                                variant="danger"
                                @click="deleteHook(hook)"
                            >
                                Delete
                            </UiButton>
                        </div>
                    </div>
                </div>

                <!-- Locked Core Action -->
                <div class="mt-3 rounded-md border border-dashed border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-2.5">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-semibold text-[var(--theme-text)]">
                            Core Action
                        </span>
                        <UiBadge variant="info">Locked</UiBadge>
                    </div>
                    <p class="mt-1 text-xs text-[var(--theme-text-muted)]">
                        {{ stage.coreAction }}
                    </p>
                </div>

                <!-- After Hooks -->
                <div v-if="hooksFor(stage.key, 'after').length > 0" class="mt-3 space-y-2">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-[var(--theme-text-muted)]">
                        After
                    </div>
                    <div
                        v-for="(hook, idx) in hooksFor(stage.key, 'after')"
                        :key="hook.id"
                        class="flex items-center justify-between gap-2 rounded-md border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-2"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span
                                    class="truncate text-xs font-medium"
                                    :class="hook.is_active ? 'text-[var(--theme-text)]' : 'text-[var(--theme-text-muted)] line-through'"
                                >
                                    {{ hook.name }}
                                </span>
                                <UiBadge v-if="!hook.is_active" variant="neutral">Paused</UiBadge>
                            </div>
                            <p class="mt-0.5 truncate font-mono text-[11px] text-[var(--theme-text-muted)]">
                                {{ hook.script }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <UiButton
                                size="xs"
                                variant="ghost"
                                :disabled="idx === 0"
                                title="Move Up"
                                @click="moveHook(stage.key, 'after', idx, -1)"
                            >
                                ↑
                            </UiButton>
                            <UiButton
                                size="xs"
                                variant="ghost"
                                :disabled="idx === hooksFor(stage.key, 'after').length - 1"
                                title="Move Down"
                                @click="moveHook(stage.key, 'after', idx, 1)"
                            >
                                ↓
                            </UiButton>
                            <UiButton
                                size="xs"
                                variant="ghost"
                                @click="toggleHookActive(hook)"
                            >
                                {{ hook.is_active ? 'Pause' : 'Enable' }}
                            </UiButton>
                            <UiButton
                                size="xs"
                                variant="ghost"
                                @click="openEditModal(hook)"
                            >
                                Edit
                            </UiButton>
                            <UiButton
                                size="xs"
                                variant="danger"
                                @click="deleteHook(hook)"
                            >
                                Delete
                            </UiButton>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </UiCard>

    <UiModal
        :show="showModal"
        :title="editingHook ? 'Edit Deployment Hook' : 'Add Deployment Hook'"
        max-width="2xl"
        @close="closeModal"
    >
        <form class="space-y-4" @submit.prevent="submitHook">
            <UiInput
                v-model="hookForm.name"
                label="Hook Name"
                placeholder="e.g. Run Database Migrations"
                :error="hookForm.errors.name"
                required
            />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-[var(--theme-text)]">
                        Stage
                    </label>
                    <select
                        v-model="hookForm.stage"
                        class="w-full rounded-md border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-2 text-sm text-[var(--theme-text)] focus:border-[var(--theme-primary)] focus:outline-none"
                    >
                        <option v-for="s in stages" :key="s.key" :value="s.key">
                            {{ s.step }}. {{ s.title }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-[var(--theme-text)]">
                        Position
                    </label>
                    <select
                        v-model="hookForm.position"
                        class="w-full rounded-md border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-2 text-sm text-[var(--theme-text)] focus:border-[var(--theme-primary)] focus:outline-none"
                    >
                        <option value="before">Before Stage</option>
                        <option value="after">After Stage</option>
                    </select>
                </div>
            </div>

            <div>
                <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                    <label class="text-xs font-medium text-[var(--theme-text)]">
                        Bash Script
                    </label>
                    <div class="flex flex-wrap items-center gap-1">
                        <button
                            v-for="variable in templateVariables"
                            :key="variable"
                            type="button"
                            class="rounded border border-[var(--theme-border)] bg-[var(--theme-surface-muted)] px-1.5 py-0.5 font-mono text-[11px] text-[var(--theme-text)] transition hover:border-[var(--theme-primary)]"
                            @click="insertVariable(variable)"
                        >
                            {{ variable }}
                        </button>
                    </div>
                </div>
                <textarea
                    ref="scriptTextarea"
                    v-model="hookForm.script"
                    rows="6"
                    placeholder="cd {{release}} && {{php}} artisan migrate --force"
                    class="w-full rounded-md border border-[var(--theme-border)] bg-[var(--theme-surface)] p-3 font-mono text-xs text-[var(--theme-text)] focus:border-[var(--theme-primary)] focus:outline-none"
                    required
                />
                <p v-if="hookForm.errors.script" class="mt-1 text-xs text-red-500">
                    {{ hookForm.errors.script }}
                </p>
            </div>

            <label class="flex items-center gap-2 text-xs text-[var(--theme-text)]">
                <input
                    v-model="hookForm.is_active"
                    type="checkbox"
                    class="rounded border-[var(--theme-border)]"
                />
                <span>Hook is active and will run during deployments</span>
            </label>

            <div class="flex justify-end gap-2 border-t border-[var(--theme-border)] pt-4">
                <UiButton type="button" variant="secondary" @click="closeModal">
                    Cancel
                </UiButton>
                <UiButton type="submit" :disabled="hookForm.processing">
                    {{ editingHook ? 'Save Changes' : 'Add Hook' }}
                </UiButton>
            </div>
        </form>
    </UiModal>
</template>
