<script setup lang="ts">
import { computed } from 'vue';
import { useTheme } from '@shared/Composables/useTheme';

defineOptions({ inheritAttrs: false });

defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    label: {
        type: String,
        default: '',
    },
    description: {
        type: String,
        default: '',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue']);

const { resolveComponent } = useTheme();
const ThemeOverride = computed(() => resolveComponent('Toggle'));
</script>

<template>
    <component
        :is="ThemeOverride"
        v-if="ThemeOverride"
        v-bind="{ ...$props, ...$attrs }"
        @update:model-value="emit('update:modelValue', $event)"
    />
    <div v-else v-bind="$attrs" class="flex items-center justify-between gap-4">
        <div v-if="label || description">
            <span v-if="label" class="text-xs sm:text-sm text-theme-text-primary block font-medium">
                {{ label }}
            </span>
            <span v-if="description" class="text-[11px] text-theme-text-muted block mt-0.5">
                {{ description }}
            </span>
        </div>
        <label class="relative inline-flex items-center cursor-pointer shrink-0">
            <input
                type="checkbox"
                :checked="modelValue"
                :disabled="disabled"
                @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
                class="sr-only peer"
            />
            <div
                class="w-9 h-5 bg-theme-bg-surface border border-theme-border peer-focus:outline-none rounded-theme-badge peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[3px] after:left-[3px] after:bg-theme-text-secondary peer-checked:after:bg-theme-text-on-accent after:rounded-theme-badge after:h-3.5 after:w-3.5 after:transition-all peer-checked:bg-theme-accent peer-checked:border-theme-accent peer-disabled:opacity-50"
            ></div>
        </label>
    </div>
</template>
