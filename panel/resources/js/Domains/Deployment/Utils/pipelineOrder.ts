export type DeploymentHookSlot =
    | 'before_clone'
    | 'after_clone'
    | 'after_install'
    | 'after_activate'
    | 'after_purge';

export type PipelineStageKey = 'clone' | 'install' | 'activate' | 'purge';

export interface PipelineStage {
    step: number;
    key: PipelineStageKey;
    title: string;
    description: string;
    locked: boolean;
    editable: boolean;
    script?: string | null;
}

export interface PipelineSlotMeta {
    key: DeploymentHookSlot;
    label: string;
    allows_release_placeholder: boolean;
}

export interface PipelinePlaceholderMeta {
    token: string;
    description: string;
    available_in_before_clone: boolean;
}

export interface DeploymentHook {
    id: number;
    config_id?: number;
    name: string;
    slot: DeploymentHookSlot;
    script: string;
    sort_order: number;
    is_active: boolean;
}

export interface DeploymentPipelinePayload {
    stages: PipelineStage[];
    slots: PipelineSlotMeta[];
    placeholders: PipelinePlaceholderMeta[];
    install_script: string | null;
    hooks: DeploymentHook[];
}

export interface FlatPipelineStageItem {
    type: 'stage';
    key: string;
    stage: PipelineStage;
}

export interface FlatPipelineHookItem {
    type: 'hook';
    key: string;
    hook: DeploymentHook;
}

export type FlatPipelineItem = FlatPipelineStageItem | FlatPipelineHookItem;

export interface HookReorderEntry {
    id: number;
    slot: DeploymentHookSlot;
    sort_order: number;
}

export const CANONICAL_SLOTS: readonly DeploymentHookSlot[] = [
    'before_clone',
    'after_clone',
    'after_install',
    'after_activate',
    'after_purge',
];

export const STAGE_TO_FOLLOWING_SLOT: Record<PipelineStageKey, DeploymentHookSlot> = {
    clone: 'after_clone',
    install: 'after_install',
    activate: 'after_activate',
    purge: 'after_purge',
};

export const DEFAULT_STAGES: readonly PipelineStage[] = [
    {
        step: 1,
        key: 'clone',
        title: 'Clone Release',
        description: 'Fetch clean archive for target ref (no .git leftovers) & symlink shared paths.',
        locked: true,
        editable: false,
    },
    {
        step: 2,
        key: 'install',
        title: 'Install Dependencies',
        description: 'Run optional dependency installation and build commands inside the release directory.',
        locked: true,
        editable: true,
        script: null,
    },
    {
        step: 3,
        key: 'activate',
        title: 'Activate Release',
        description: 'Atomic symlink swap, detached PHP-FPM/LSPHP reload & HTTP health check.',
        locked: true,
        editable: false,
    },
    {
        step: 4,
        key: 'purge',
        title: 'Purge Old Releases',
        description: 'Prune expired release directories beyond the retention limit.',
        locked: true,
        editable: false,
    },
];

export const DEFAULT_SLOTS: readonly PipelineSlotMeta[] = [
    { key: 'before_clone', label: 'Before Clone Release', allows_release_placeholder: false },
    { key: 'after_clone', label: 'After Clone Release', allows_release_placeholder: true },
    { key: 'after_install', label: 'After Install Dependencies', allows_release_placeholder: true },
    { key: 'after_activate', label: 'After Activate Release', allows_release_placeholder: true },
    { key: 'after_purge', label: 'After Purge Old Releases', allows_release_placeholder: true },
];

export const DEFAULT_PLACEHOLDERS: readonly PipelinePlaceholderMeta[] = [
    {
        token: '{{release}}',
        description: 'Absolute path to the active release directory (not available in before_clone).',
        available_in_before_clone: false,
    },
    {
        token: '{{project}}',
        description: 'Absolute path to the domain project root directory.',
        available_in_before_clone: true,
    },
    {
        token: '{{shared}}',
        description: 'Absolute path to the persistent shared directory.',
        available_in_before_clone: true,
    },
    {
        token: '{{php}}',
        description: 'Configured PHP CLI binary path for this domain.',
        available_in_before_clone: true,
    },
    {
        token: '{{composer}}',
        description: 'Configured Composer command using the domain PHP binary.',
        available_in_before_clone: true,
    },
    {
        token: '{{branch}}',
        description: 'Git branch being deployed.',
        available_in_before_clone: true,
    },
    {
        token: '{{sha}}',
        description: 'Target commit SHA (available once resolved).',
        available_in_before_clone: true,
    },
];

export function referencesReleasePlaceholder(script: string): boolean {
    return /\{\{\s*release\s*\}\}/i.test(script);
}

/**
 * Build the flat top-to-bottom pipeline list interleaving the 4 locked default stages
 * with custom hooks ordered by canonical slot, sort_order ASC, id ASC.
 */
export function buildFlatPipeline(
    stages: readonly PipelineStage[],
    hooks: readonly DeploymentHook[],
): FlatPipelineItem[] {
    const resolvedStages = stages.length === 4 ? stages : DEFAULT_STAGES;
    const grouped: Record<DeploymentHookSlot, DeploymentHook[]> = {
        before_clone: [],
        after_clone: [],
        after_install: [],
        after_activate: [],
        after_purge: [],
    };

    for (const hook of hooks) {
        const slot: DeploymentHookSlot = CANONICAL_SLOTS.includes(hook.slot)
            ? hook.slot
            : 'after_purge';
        grouped[slot].push({ ...hook, slot });
    }

    for (const slot of CANONICAL_SLOTS) {
        grouped[slot].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0) || a.id - b.id);
    }

    const items: FlatPipelineItem[] = [];

    for (const hook of grouped.before_clone) {
        items.push({ type: 'hook', key: `hook-${hook.id}`, hook });
    }

    for (const stage of resolvedStages) {
        items.push({ type: 'stage', key: `stage-${stage.key}`, stage });

        const followingSlot = STAGE_TO_FOLLOWING_SLOT[stage.key];
        for (const hook of grouped[followingSlot]) {
            items.push({ type: 'hook', key: `hook-${hook.id}`, hook });
        }
    }

    return items;
}

/**
 * Pure, deterministic mapper that converts a rendered flat pipeline list back into
 * the `{ id, slot, sort_order }` payload expected by PUT /deploy/hooks/reorder.
 */
export function mapFlatPipelineToReorderPayload(
    items: readonly FlatPipelineItem[],
): HookReorderEntry[] {
    let currentSlot: DeploymentHookSlot = 'before_clone';
    const counters: Record<DeploymentHookSlot, number> = {
        before_clone: 0,
        after_clone: 0,
        after_install: 0,
        after_activate: 0,
        after_purge: 0,
    };

    const payload: HookReorderEntry[] = [];

    for (const item of items) {
        if (item.type === 'stage') {
            currentSlot = STAGE_TO_FOLLOWING_SLOT[item.stage.key];
            continue;
        }

        payload.push({
            id: item.hook.id,
            slot: currentSlot,
            sort_order: counters[currentSlot],
        });
        counters[currentSlot] += 1;
    }

    return payload;
}

/**
 * Return a new flat pipeline list with each hook's `slot` and `sort_order`
 * synchronized to its current position in the list.
 */
export function syncHooksFromFlatPipeline(
    items: readonly FlatPipelineItem[],
): FlatPipelineItem[] {
    const mapped = mapFlatPipelineToReorderPayload(items);
    const byId = new Map<number, HookReorderEntry>();
    for (const entry of mapped) {
        byId.set(entry.id, entry);
    }

    return items.map((item) => {
        if (item.type === 'stage') {
            return item;
        }

        const entry = byId.get(item.hook.id);
        if (!entry) {
            return item;
        }

        return {
            ...item,
            hook: {
                ...item.hook,
                slot: entry.slot,
                sort_order: entry.sort_order,
            },
        };
    });
}

/**
 * Move a custom hook item from `fromIndex` to `toIndex` inside the flat list.
 * Locked stage items cannot be moved.
 */
export function reorderFlatPipelineByIndex(
    items: readonly FlatPipelineItem[],
    fromIndex: number,
    toIndex: number,
): FlatPipelineItem[] {
    if (fromIndex < 0 || fromIndex >= items.length) {
        return [...items];
    }

    const movingItem = items[fromIndex];
    if (movingItem.type !== 'hook') {
        return [...items];
    }

    const clampedTarget = Math.max(0, Math.min(items.length - 1, toIndex));
    if (clampedTarget === fromIndex) {
        return [...items];
    }

    const next = [...items];
    const [removed] = next.splice(fromIndex, 1);
    next.splice(clampedTarget, 0, removed);

    return syncHooksFromFlatPipeline(next);
}

/**
 * Keyboard-accessible helper to move a custom hook up (-1) or down (+1) by one position
 * in the flat pipeline (including across locked stage boundaries).
 */
export function moveHookInFlatPipeline(
    items: readonly FlatPipelineItem[],
    hookId: number,
    direction: -1 | 1,
): FlatPipelineItem[] {
    const index = items.findIndex((item) => item.type === 'hook' && item.hook.id === hookId);
    if (index === -1) {
        return [...items];
    }

    const targetIndex = index + direction;
    if (targetIndex < 0 || targetIndex >= items.length) {
        return [...items];
    }

    return reorderFlatPipelineByIndex(items, index, targetIndex);
}

export function extractApiErrorMessage(
    payload: unknown,
    fallback = 'Unable to update deployment pipeline order.',
): string {
    if (payload && typeof payload === 'object') {
        const record = payload as Record<string, unknown>;
        if (record.errors && typeof record.errors === 'object') {
            const values = Object.values(record.errors as Record<string, unknown>);
            for (const value of values) {
                if (Array.isArray(value) && typeof value[0] === 'string' && value[0].trim() !== '') {
                    return value[0];
                }
                if (typeof value === 'string' && value.trim() !== '') {
                    return value;
                }
            }
        }

        if (typeof record.message === 'string' && record.message.trim() !== '') {
            return record.message;
        }
    }

    if (payload instanceof Error && payload.message.trim() !== '') {
        return payload.message;
    }

    return fallback;
}

export type ReorderCommitResult =
    | { ok: true; items: FlatPipelineItem[] }
    | { ok: false; items: FlatPipelineItem[]; error: string };

/**
 * Persist a reordered flat pipeline list with clean rollback on API failure.
 */
export async function commitPipelineReorder(
    previousItems: readonly FlatPipelineItem[],
    nextItems: readonly FlatPipelineItem[],
    persistFn: (
        payload: HookReorderEntry[],
    ) => Promise<DeploymentPipelinePayload | null | undefined>,
): Promise<ReorderCommitResult> {
    const payload = mapFlatPipelineToReorderPayload(nextItems);

    try {
        const serverPipeline = await persistFn(payload);
        if (serverPipeline) {
            return {
                ok: true,
                items: buildFlatPipeline(serverPipeline.stages, serverPipeline.hooks),
            };
        }

        return {
            ok: true,
            items: syncHooksFromFlatPipeline(nextItems),
        };
    } catch (err: unknown) {
        return {
            ok: false,
            items: [...previousItems],
            error: extractApiErrorMessage(err),
        };
    }
}
