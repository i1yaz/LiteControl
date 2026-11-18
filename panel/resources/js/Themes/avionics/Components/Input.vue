<script setup lang="ts">
defineOptions({ inheritAttrs: false });

defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },
    id: {
        type: String,
        default: '',
    },
    label: {
        type: String,
        default: '',
    },
    hint: {
        type: String,
        default: '',
    },
    type: {
        type: String,
        default: 'text',
    },
    placeholder: {
        type: String,
        default: '',
    },
    error: {
        type: String,
        default: '',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    required: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="space-y-1">
        <div v-if="label || $slots['label-right']" class="flex items-center justify-between">
            <label v-if="label" :for="id" class="block text-xs font-mono font-medium text-theme-text-secondary uppercase tracking-wider">
                {{ label }} <span v-if="required" class="text-theme-accent">*</span>
            </label>
            <slot name="label-right" />
        </div>
        <div class="relative">
            <input
                v-bind="$attrs"
                :id="id"
                :type="type"
                :value="modelValue"
                :placeholder="placeholder"
                :disabled="disabled"
                :required="required"
                @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
                :class="[
                    'w-full px-3 py-2 bg-theme-bg-input border rounded-theme-md text-xs font-mono text-theme-text-primary placeholder-theme-text-muted transition-colors focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed',
                    error ? 'border-theme-danger focus:border-theme-danger' : 'border-theme-border focus:border-theme-accent'
                ]"
            />
        </div>
        <p v-if="hint && !error" class="text-[11px] font-mono text-theme-text-muted mt-1">
            {{ hint }}
        </p>
        <p v-if="error" class="text-[11px] font-mono text-theme-danger-text mt-1">
            {{ error }}
        </p>
    </div>
</template>
