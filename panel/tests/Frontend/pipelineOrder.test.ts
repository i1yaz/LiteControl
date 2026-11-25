import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    buildFlatPipeline,
    commitPipelineReorder,
    DEFAULT_STAGES,
    extractApiErrorMessage,
    mapFlatPipelineToReorderPayload,
    moveHookInFlatPipeline,
    referencesReleasePlaceholder,
    reorderFlatPipelineByIndex,
    type DeploymentHook,
    type DeploymentHookSlot,
} from '../../resources/js/Domains/Deployment/Utils/pipelineOrder.ts';

function makeHook(
    id: number,
    slot: DeploymentHookSlot,
    sortOrder = 0,
    script = `echo hook-${id}`,
): DeploymentHook {
    return {
        id,
        name: `Hook ${id}`,
        slot,
        script,
        sort_order: sortOrder,
        is_active: true,
    };
}

describe('pipelineOrder pure utilities', () => {
    it('builds flat pipeline in exact execution order across all 5 slots and 4 locked stages', () => {
        const hooks: DeploymentHook[] = [
            makeHook(5, 'after_purge', 1),
            makeHook(4, 'after_purge', 0),
            makeHook(3, 'after_activate', 0),
            makeHook(2, 'after_install', 0),
            makeHook(1, 'after_clone', 0),
            makeHook(0, 'before_clone', 0),
        ];

        const flat = buildFlatPipeline(DEFAULT_STAGES, hooks);

        assert.deepEqual(
            flat.map((item) => item.key),
            [
                'hook-0',
                'stage-clone',
                'hook-1',
                'stage-install',
                'hook-2',
                'stage-activate',
                'hook-3',
                'stage-purge',
                'hook-4',
                'hook-5',
            ],
        );
    });

    it('maps flat pipeline with hooks in all 5 slots back to deterministic (slot, sort_order) payloads', () => {
        const hooks: DeploymentHook[] = [
            makeHook(10, 'before_clone', 0),
            makeHook(11, 'before_clone', 1),
            makeHook(20, 'after_clone', 0),
            makeHook(30, 'after_install', 0),
            makeHook(31, 'after_install', 1),
            makeHook(32, 'after_install', 2),
            makeHook(40, 'after_activate', 0),
            makeHook(50, 'after_purge', 0),
            makeHook(51, 'after_purge', 1),
        ];

        const flat = buildFlatPipeline(DEFAULT_STAGES, hooks);
        const payload = mapFlatPipelineToReorderPayload(flat);

        assert.deepEqual(payload, [
            { id: 10, slot: 'before_clone', sort_order: 0 },
            { id: 11, slot: 'before_clone', sort_order: 1 },
            { id: 20, slot: 'after_clone', sort_order: 0 },
            { id: 30, slot: 'after_install', sort_order: 0 },
            { id: 31, slot: 'after_install', sort_order: 1 },
            { id: 32, slot: 'after_install', sort_order: 2 },
            { id: 40, slot: 'after_activate', sort_order: 0 },
            { id: 50, slot: 'after_purge', sort_order: 0 },
            { id: 51, slot: 'after_purge', sort_order: 1 },
        ]);
    });

    it('handles completely empty slots and zero hooks cleanly', () => {
        const emptyFlat = buildFlatPipeline(DEFAULT_STAGES, []);
        assert.deepEqual(
            emptyFlat.map((item) => item.key),
            ['stage-clone', 'stage-install', 'stage-activate', 'stage-purge'],
        );
        assert.deepEqual(mapFlatPipelineToReorderPayload(emptyFlat), []);

        // Only hooks in after_install and after_purge; other 3 slots empty
        const sparseFlat = buildFlatPipeline(DEFAULT_STAGES, [
            makeHook(7, 'after_install', 5),
            makeHook(8, 'after_purge', 9),
        ]);
        assert.deepEqual(mapFlatPipelineToReorderPayload(sparseFlat), [
            { id: 7, slot: 'after_install', sort_order: 0 },
            { id: 8, slot: 'after_purge', sort_order: 0 },
        ]);
    });

    it('reorders multiple hooks within the same slot and across locked stage boundaries', () => {
        const flat = buildFlatPipeline(DEFAULT_STAGES, [
            makeHook(1, 'after_install', 0),
            makeHook(2, 'after_install', 1),
            makeHook(3, 'after_install', 2),
        ]);
        // flat indices: 0:clone, 1:install, 2:hook-1, 3:hook-2, 4:hook-3, 5:activate, 6:purge

        // Move hook-3 above hook-1 inside after_install
        const reorderedSameSlot = reorderFlatPipelineByIndex(flat, 4, 2);
        assert.deepEqual(mapFlatPipelineToReorderPayload(reorderedSameSlot), [
            { id: 3, slot: 'after_install', sort_order: 0 },
            { id: 1, slot: 'after_install', sort_order: 1 },
            { id: 2, slot: 'after_install', sort_order: 2 },
        ]);

        // Drag hook-3 before Stage 1 (clone) -> moves into before_clone
        const movedBeforeClone = reorderFlatPipelineByIndex(reorderedSameSlot, 2, 0);
        assert.deepEqual(mapFlatPipelineToReorderPayload(movedBeforeClone), [
            { id: 3, slot: 'before_clone', sort_order: 0 },
            { id: 1, slot: 'after_install', sort_order: 0 },
            { id: 2, slot: 'after_install', sort_order: 1 },
        ]);

        // Drag hook-2 after Stage 4 (purge) -> moves into after_purge
        const movedAfterPurge = reorderFlatPipelineByIndex(movedBeforeClone, 4, 6);
        assert.deepEqual(mapFlatPipelineToReorderPayload(movedAfterPurge), [
            { id: 3, slot: 'before_clone', sort_order: 0 },
            { id: 1, slot: 'after_install', sort_order: 0 },
            { id: 2, slot: 'after_purge', sort_order: 0 },
        ]);
    });

    it('prevents moving locked default stages', () => {
        const flat = buildFlatPipeline(DEFAULT_STAGES, [makeHook(1, 'after_purge', 0)]);
        const attempted = reorderFlatPipelineByIndex(flat, 0, 3);
        assert.deepEqual(
            attempted.map((item) => item.key),
            flat.map((item) => item.key),
        );
    });

    it('supports keyboard Move Up / Move Down across stage boundaries', () => {
        let flat = buildFlatPipeline(DEFAULT_STAGES, [makeHook(99, 'after_purge', 0)]);
        // Start: [clone, install, activate, purge, hook-99]

        flat = moveHookInFlatPipeline(flat, 99, -1);
        assert.deepEqual(mapFlatPipelineToReorderPayload(flat), [
            { id: 99, slot: 'after_activate', sort_order: 0 },
        ]);

        flat = moveHookInFlatPipeline(flat, 99, -1);
        assert.deepEqual(mapFlatPipelineToReorderPayload(flat), [
            { id: 99, slot: 'after_install', sort_order: 0 },
        ]);

        flat = moveHookInFlatPipeline(flat, 99, -1);
        assert.deepEqual(mapFlatPipelineToReorderPayload(flat), [
            { id: 99, slot: 'after_clone', sort_order: 0 },
        ]);

        flat = moveHookInFlatPipeline(flat, 99, -1);
        assert.deepEqual(mapFlatPipelineToReorderPayload(flat), [
            { id: 99, slot: 'before_clone', sort_order: 0 },
        ]);

        // Moving further up at index 0 is a safe no-op
        const atTop = moveHookInFlatPipeline(flat, 99, -1);
        assert.deepEqual(mapFlatPipelineToReorderPayload(atTop), [
            { id: 99, slot: 'before_clone', sort_order: 0 },
        ]);

        // Moving down returns it to after_clone
        const movedDown = moveHookInFlatPipeline(atTop, 99, 1);
        assert.deepEqual(mapFlatPipelineToReorderPayload(movedDown), [
            { id: 99, slot: 'after_clone', sort_order: 0 },
        ]);
    });

    it('detects {{release}} placeholder regardless of spacing or casing', () => {
        assert.equal(referencesReleasePlaceholder('cd {{release}} && php artisan migrate'), true);
        assert.equal(referencesReleasePlaceholder('echo {{ RELEASE }}'), true);
        assert.equal(referencesReleasePlaceholder('echo {{project}}/shared'), false);
    });

    it('rolls back cleanly to previous items and surfaces backend validation error on reorder failure', async () => {
        const previousItems = buildFlatPipeline(DEFAULT_STAGES, [
            makeHook(1, 'after_clone', 0, '{{php}} {{release}}/artisan migrate --force'),
        ]);
        // Attempt to move the {{release}} hook above Stage 1 (into before_clone)
        const attemptedItems = moveHookInFlatPipeline(previousItems, 1, -1);

        assert.deepEqual(mapFlatPipelineToReorderPayload(attemptedItems), [
            { id: 1, slot: 'before_clone', sort_order: 0 },
        ]);

        const backendError =
            'Hooks in the before_clone slot cannot reference {{release}} because the release directory does not exist before cloning.';

        const result = await commitPipelineReorder(previousItems, attemptedItems, async () => {
            throw {
                message: 'The given data was invalid.',
                errors: {
                    'hooks.0.slot': [backendError],
                },
            };
        });

        assert.equal(result.ok, false);
        if (!result.ok) {
            assert.equal(result.error, backendError);
            assert.deepEqual(result.items, previousItems);
            assert.deepEqual(mapFlatPipelineToReorderPayload(result.items), [
                { id: 1, slot: 'after_clone', sort_order: 0 },
            ]);
        }
    });

    it('commits reordered state using server pipeline response on success', async () => {
        const previousItems = buildFlatPipeline(DEFAULT_STAGES, [
            makeHook(1, 'after_install', 0),
            makeHook(2, 'after_install', 1),
        ]);
        const nextItems = moveHookInFlatPipeline(previousItems, 2, -1);

        let receivedPayload: unknown = null;
        const result = await commitPipelineReorder(previousItems, nextItems, async (payload) => {
            receivedPayload = payload;
            return {
                stages: [...DEFAULT_STAGES],
                slots: [],
                placeholders: [],
                install_script: null,
                hooks: [
                    makeHook(2, 'after_install', 0),
                    makeHook(1, 'after_install', 1),
                ],
            };
        });

        assert.equal(result.ok, true);
        assert.deepEqual(receivedPayload, [
            { id: 2, slot: 'after_install', sort_order: 0 },
            { id: 1, slot: 'after_install', sort_order: 1 },
        ]);
        assert.deepEqual(mapFlatPipelineToReorderPayload(result.items), [
            { id: 2, slot: 'after_install', sort_order: 0 },
            { id: 1, slot: 'after_install', sort_order: 1 },
        ]);
    });

    it('extracts error messages from Laravel validation payloads and plain Error objects', () => {
        assert.equal(
            extractApiErrorMessage({ errors: { hooks: ['All hooks required.'] } }),
            'All hooks required.',
        );
        assert.equal(
            extractApiErrorMessage({ message: 'Forbidden' }),
            'Forbidden',
        );
        assert.equal(
            extractApiErrorMessage(new Error('Network offline')),
            'Network offline',
        );
        assert.equal(
            extractApiErrorMessage(null, 'Fallback message'),
            'Fallback message',
        );
    });
});
