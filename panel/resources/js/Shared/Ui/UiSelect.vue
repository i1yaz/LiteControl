<script setup lang="ts">
import { computed } from 'vue';
import { useTheme } from '@shared/Composables/useTheme';

defineOptions({ inheritAttrs: false });

defineProps({
    modelValue: {
        type: [String, Number, Boolean],
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
    options: {
        type: Array,
        default: () => [],
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

const emit = defineEmits(['update:modelValue']);

const { resolveComponent } = useTheme();
const ThemeOverride = computed(() => resolveComponent('Select'));
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
        <label v-if="label" :for="id" class="block text-xs font-medium text-theme-text-secondary theme-ui-control">
            {{ label }} <span v-if="required" class="text-theme-accent">*</span>
        </label>
        <div class="relative">
            <select
                v-bind="$attrs"
                :id="id"
                :value="modelValue"
                :disabled="disabled"
                :required="required"
                @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
                :class="[
                    'w-full px-3.5 py-2 bg-theme-bg-input border rounded-theme-md text-xs sm:text-sm text-theme-text-primary transition-colors focus:outline-none focus:ring-1 disabled:opacity-50 appearance-none pr-9 cursor-pointer',
                    error
                        ? 'border-theme-danger focus:border-theme-danger focus:ring-theme-danger/30'
                        : 'border-theme-border focus:border-theme-accent focus:ring-theme-accent/30'
                ]"
            >
                <slot>
                    <option
                        v-for="opt in options"
                        :key="typeof opt === 'object' ? opt.value : opt"
                        :value="typeof opt === 'object' ? opt.value : opt"
                        class="bg-theme-bg-elevated text-theme-text-primary"
                    >
                        {{ typeof opt === 'object' ? opt.label : opt }}
                    </option>
                </slot>
            </select>
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-theme-text-muted">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                </svg>
            </div>
        </div>
        <p v-if="hint && !error" class="text-[11px] text-theme-text-muted mt-1">
            {{ hint }}
        </p>
        <p v-if="error" class="text-xs text-theme-danger-text mt-1">
            {{ error }}
        </p>
    </div>
</template>
