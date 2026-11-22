<script setup>
import { computed, ref, watch } from 'vue';
import { AlertTriangle, ShieldAlert } from '@lucide/vue';
import UiModal from '@shared/Ui/UiModal.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiInput from '@shared/Ui/UiInput.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        required: true,
    },
    message: {
        type: String,
        default: '',
    },
    confirmText: {
        type: String,
        default: 'Confirm',
    },
    cancelText: {
        type: String,
        default: 'Cancel',
    },
    variant: {
        type: String,
        default: 'danger', // danger | warning | primary
    },
    processing: {
        type: Boolean,
        default: false,
    },
    requireTypedConfirmation: {
        type: String,
        default: '',
    },
    typedConfirmationLabel: {
        type: String,
        default: '',
    },
    checkboxLabel: {
        type: String,
        default: '',
    },
    checkboxRequired: {
        type: Boolean,
        default: false,
    },
    checkboxInitial: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close', 'confirm']);

const typedValue = ref('');
const checked = ref(false);

watch(() => props.show, (isOpen) => {
    if (isOpen) {
        typedValue.value = '';
        checked.value = props.checkboxInitial;
    }
});

const isConfirmDisabled = computed(() => {
    if (props.processing) return true;
    if (props.requireTypedConfirmation && typedValue.value.trim() !== props.requireTypedConfirmation) {
        return true;
    }
    if (props.checkboxLabel && props.checkboxRequired && !checked.value) {
        return true;
    }
    return false;
});

const iconClasses = computed(() => {
    if (props.variant === 'warning') {
        return 'text-theme-warning-text bg-theme-warning-bg border-theme-warning-border';
    }
    if (props.variant === 'primary') {
        return 'text-theme-accent-text bg-theme-accent-subtle border-theme-accent-border';
    }
    return 'text-theme-danger-text bg-theme-danger-bg border-theme-danger-border';
});

const handleClose = () => {
    if (!props.processing) {
        emit('close');
    }
};

const handleConfirm = () => {
    if (isConfirmDisabled.value) return;
    emit('confirm', { checked: checked.value });
};
</script>

<template>
    <UiModal
        :show="show"
        :title="title"
        max-width="md"
        :closeable="!processing"
        @close="handleClose"
    >
        <div class="space-y-4">
            <div class="flex items-start gap-3.5">
                <div
                    class="shrink-0 w-9 h-9 rounded-theme-md border flex items-center justify-center"
                    :class="iconClasses"
                >
                    <ShieldAlert v-if="variant === 'danger'" class="w-4.5 h-4.5" />
                    <AlertTriangle v-else class="w-4.5 h-4.5" />
                </div>
                <div class="space-y-2 text-sm text-theme-text-secondary leading-relaxed">
                    <p v-if="message" class="whitespace-pre-line">{{ message }}</p>
                    <slot />
                </div>
            </div>

            <div v-if="checkboxLabel" class="pt-1">
                <label class="flex items-start gap-2.5 cursor-pointer select-none text-xs sm:text-sm text-theme-text-primary">
                    <input
                        v-model="checked"
                        type="checkbox"
                        :disabled="processing"
                        class="mt-0.5 rounded border-theme-border bg-theme-bg-input text-theme-accent focus:ring-theme-accent/30"
                    />
                    <span>{{ checkboxLabel }}</span>
                </label>
            </div>

            <div v-if="requireTypedConfirmation" class="pt-1">
                <UiInput
                    id="confirm-action-typed-input"
                    v-model="typedValue"
                    :label="typedConfirmationLabel || `Type '${requireTypedConfirmation}' to confirm`"
                    :placeholder="requireTypedConfirmation"
                    :disabled="processing"
                    autocomplete="off"
                />
            </div>
        </div>

        <template #footer>
            <UiButton
                variant="secondary"
                size="sm"
                type="button"
                :disabled="processing"
                @click="handleClose"
            >
                {{ cancelText }}
            </UiButton>
            <UiButton
                :variant="variant === 'danger' ? 'danger' : variant === 'warning' ? 'warning' : 'primary'"
                size="sm"
                type="button"
                :disabled="isConfirmDisabled"
                :loading="processing"
                @click="handleConfirm"
            >
                {{ confirmText }}
            </UiButton>
        </template>
    </UiModal>
</template>
