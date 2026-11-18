<script setup lang="ts">
import { computed } from 'vue';
import { AlertTriangle, Info, ShieldAlert } from '@lucide/vue';
import UiModal from '@shared/Ui/UiModal.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import { useDialog } from '@shared/Composables/useDialog';

const { dialogState, resolveDialog } = useDialog();

const iconComponent = computed(() => {
    switch (dialogState.variant) {
        case 'danger':
            return ShieldAlert;
        case 'warning':
            return AlertTriangle;
        default:
            return Info;
    }
});

const iconClasses = computed(() => {
    switch (dialogState.variant) {
        case 'danger':
            return 'text-theme-danger-text bg-theme-danger-bg border-theme-danger-border';
        case 'warning':
            return 'text-theme-warning-text bg-theme-warning-bg border-theme-warning-border';
        default:
            return 'text-theme-accent-text bg-theme-accent-subtle border-theme-accent-border';
    }
});

const confirmButtonVariant = computed(() => {
    return dialogState.variant === 'danger' ? 'danger' : 'primary';
});

const handleConfirm = () => {
    resolveDialog(dialogState.mode === 'confirm' ? true : undefined);
};

const handleCancel = () => {
    resolveDialog(dialogState.mode === 'confirm' ? false : undefined);
};
</script>

<template>
    <UiModal
        :show="dialogState.show"
        :title="dialogState.title"
        max-width="md"
        @close="handleCancel"
    >
        <div class="flex items-start gap-3.5">
            <div
                class="shrink-0 w-9 h-9 rounded-theme-md border flex items-center justify-center"
                :class="iconClasses"
            >
                <component :is="iconComponent" class="w-4.5 h-4.5" />
            </div>
            <p class="text-sm text-theme-text-secondary leading-relaxed whitespace-pre-line pt-0.5">
                {{ dialogState.message }}
            </p>
        </div>

        <div class="flex items-center justify-end gap-2.5 mt-6 pt-4 border-t border-theme-border-subtle">
            <UiButton
                v-if="dialogState.mode === 'confirm'"
                variant="secondary"
                size="sm"
                @click="handleCancel"
            >
                {{ dialogState.cancelText }}
            </UiButton>
            <UiButton
                :variant="confirmButtonVariant"
                size="sm"
                @click="handleConfirm"
            >
                {{ dialogState.confirmText }}
            </UiButton>
        </div>
    </UiModal>
</template>
