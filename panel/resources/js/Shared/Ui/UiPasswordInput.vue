<script setup lang="ts">
import { shallowRef } from 'vue';
import { Copy, Check } from '@lucide/vue';
import UiInput from '@shared/Ui/UiInput.vue';
import { generateStrongPassword, useClipboard } from '@shared/Composables/usePasswordGenerator';

const props = defineProps({
    id: {
        type: String,
        required: true,
    },
    modelValue: {
        type: String,
        default: '',
    },
    label: {
        type: String,
        default: 'Password',
    },
    placeholder: {
        type: String,
        default: '••••••••••••',
    },
    error: {
        type: String,
        default: '',
    },
    required: {
        type: Boolean,
        default: false,
    },
    allowGenerate: {
        type: Boolean,
        default: true,
    },
    allowCopy: {
        type: Boolean,
        default: true,
    },
    length: {
        type: Number,
        default: 16,
    },
});

const emit = defineEmits(['update:modelValue', 'generated']);

const showPassword = shallowRef(false);
const { copiedKey, copyText } = useClipboard();

const handleGenerate = () => {
    const generated = generateStrongPassword(props.length);
    emit('update:modelValue', generated);
    emit('generated', generated);
    showPassword.value = true;
};
</script>

<template>
    <div>
        <div class="flex items-center justify-between mb-1">
            <label :for="id" class="block text-xs font-medium text-theme-text-secondary">
                {{ label }}
            </label>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    @click="showPassword = !showPassword"
                    class="text-[11px] text-theme-text-muted hover:text-theme-text-primary transition-colors"
                >
                    {{ showPassword ? 'Hide' : 'Show' }}
                </button>
                <button
                    v-if="allowGenerate"
                    type="button"
                    @click="handleGenerate"
                    class="text-[11px] font-medium text-theme-accent hover:underline"
                >
                    Generate Strong
                </button>
                <button
                    v-if="allowCopy && modelValue"
                    type="button"
                    @click="copyText(modelValue, id)"
                    class="inline-flex items-center gap-0.5 text-[11px] text-theme-text-muted hover:text-theme-text-primary transition-colors"
                    title="Copy Password"
                >
                    <Check v-if="copiedKey === id" class="w-3 h-3 text-theme-success" />
                    <Copy v-else class="w-3 h-3" />
                </button>
            </div>
        </div>
        <UiInput
            :id="id"
            :model-value="modelValue"
            :type="showPassword ? 'text' : 'password'"
            :placeholder="placeholder"
            :error="error"
            :required="required"
            @update:model-value="emit('update:modelValue', $event)"
        />
    </div>
</template>
