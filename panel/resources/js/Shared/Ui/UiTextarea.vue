<script setup lang="ts">
import { computed } from 'vue';
import { useTheme } from '@shared/Composables/useTheme';

defineOptions({ inheritAttrs: false });

defineProps({
    modelValue: {
        type: String,
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
    rows: {
        type: [Number, String],
        default: 4,
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
    mono: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['update:modelValue']);

const { resolveComponent } = useTheme();
const ThemeOverride = computed(() => resolveComponent('Textarea'));
</script>

<template>
    <component
        :is="ThemeOverride"
        v-if="ThemeOverride"
        v-bind="{ ...$props, ...$attrs }"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <template v-for="(_, slot) in $slots" #[slot]="slotProps">
            <slot :name="slot" v-bind="slotProps" />
        </template>
    </component>
    <div v-else class="space-y-1.5">
        <div v-if="label || $slots['label-right']" class="flex items-center justify-between">
            <label v-if="label" :for="id" class="block text-xs font-medium text-theme-text-secondary theme-ui-control">
                {{ label }} <span v-if="required" class="text-theme-accent">*</span>
            </label>
            <slot name="label-right" />
        </div>
        <textarea
            v-bind="$attrs"
            :id="id"
            :rows="rows"
            :value="modelValue"
            :placeholder="placeholder"
            :disabled="disabled"
            :required="required"
            @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
            :class="[
                'w-full px-3.5 py-2.5 bg-theme-bg-input border rounded-theme-md text-xs text-theme-text-primary placeholder-theme-text-muted transition-colors focus:outline-none focus:ring-1 disabled:opacity-50',
                mono ? 'font-mono' : 'font-sans',
                error
                    ? 'border-theme-danger focus:border-theme-danger focus:ring-theme-danger/30'
                    : 'border-theme-border focus:border-theme-accent focus:ring-theme-accent/30'
            ]"
        ></textarea>
        <p v-if="hint && !error" class="text-[11px] text-theme-text-muted mt-1">
            {{ hint }}
        </p>
        <p v-if="error" class="text-xs text-theme-danger-text mt-1">
            {{ error }}
        </p>
    </div>
</template>
