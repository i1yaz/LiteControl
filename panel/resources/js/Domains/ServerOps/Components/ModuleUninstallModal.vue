<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import UiAlert from '@shared/Ui/UiAlert.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiModal from '@shared/Ui/UiModal.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    module: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['close', 'submitted']);

const form = useForm({
    purge_data: false,
});

watch(
    () => props.show,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
    }
);

const isMailCore = computed(() => props.module?.key === 'mail_core');
const isMailFilter = computed(() => props.module?.key === 'mail_filter');

const modalTitle = computed(() => {
    if (!props.module) return 'Uninstall Module';
    return `Uninstall ${props.module.title || props.module.key}`;
});

const submit = () => {
    if (!props.module) return;

    form.post(`/modules/${props.module.key}/remove`, {
        preserveScroll: true,
        onSuccess: () => {
            emit('submitted', props.module.key);
            emit('close');
        },
    });
};
</script>

<template>
    <UiModal :show="show" :title="modalTitle" max-width="md" @close="emit('close')">
        <form id="module-uninstall-form" class="space-y-4" @submit.prevent="submit">
            <template v-if="isMailCore">
                <UiAlert variant="warning">
                    Stopping and disabling the core mail stack leaves <code>/var/vmail</code> intact by default so
                    existing mailboxes are preserved.
                </UiAlert>

                <label
                    for="mail-core-purge-data"
                    class="flex items-start gap-3 p-3 rounded-theme-md border border-theme-danger-border bg-theme-danger-bg text-xs text-theme-danger-text cursor-pointer"
                >
                    <input
                        id="mail-core-purge-data"
                        v-model="form.purge_data"
                        type="checkbox"
                        class="mt-0.5 rounded border-theme-danger focus:ring-theme-danger"
                    />
                    <span>
                        <strong class="block font-semibold">Also wipe all mail data (--purge-data)</strong>
                        Permanently deletes <code>/var/vmail</code> and all stored mailbox messages. This cannot be
                        undone.
                    </span>
                </label>
            </template>

            <template v-else-if="isMailFilter">
                <UiAlert variant="info">
                    Uninstalling the anti-spam filter demotes Rspamd back to DKIM-Light + outbound ratelimit mode
                    without removing the Rspamd package or touching your DKIM keys.
                </UiAlert>
            </template>

            <template v-else>
                <p class="text-sm text-theme-text-secondary">
                    Are you sure you want to uninstall
                    <strong class="text-theme-text-primary">{{ module?.title || module?.key }}</strong>?
                </p>
            </template>
        </form>

        <template #footer>
            <UiButton variant="secondary" size="sm" :disabled="form.processing" @click="emit('close')">
                Cancel
            </UiButton>
            <UiButton
                type="submit"
                form="module-uninstall-form"
                variant="danger"
                size="sm"
                :loading="form.processing"
            >
                {{ isMailCore && form.purge_data ? 'Uninstall & Purge Data' : 'Uninstall' }}
            </UiButton>
        </template>
    </UiModal>
</template>
