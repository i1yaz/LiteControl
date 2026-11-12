<script setup>
import { computed } from 'vue';
import { AlertTriangle, Info, ShieldAlert } from '@lucide/vue';
import UiModal from '@/Components/ui/UiModal.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import { useDialog } from '@/Composables/useDialog';

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
            return 'text-red-400 bg-red-500/10 border-red-500/20';
        case 'warning':
            return 'text-amber-400 bg-amber-500/10 border-amber-500/20';
        default:
            return 'text-sky-400 bg-sky-500/10 border-sky-500/20';
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
                class="shrink-0 w-9 h-9 rounded-lg border flex items-center justify-center"
                :class="iconClasses"
            >
                <component :is="iconComponent" class="w-4.5 h-4.5" />
            </div>
            <p class="text-sm leading-relaxed opacity-90 whitespace-pre-line pt-0.5">
                {{ dialogState.message }}
            </p>
        </div>

        <div class="flex items-center justify-end gap-2.5 mt-6 pt-4 border-t border-current/10">
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

