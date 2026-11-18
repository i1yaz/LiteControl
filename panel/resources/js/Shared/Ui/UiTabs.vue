<script setup lang="ts">
import { computed } from 'vue';
import { useTheme } from '@shared/Composables/useTheme';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    tabs: {
        type: Array as () => Array<{ id: string; label: string; icon?: any }>,
        required: true,
    },
});

const emit = defineEmits(['update:modelValue', 'select']);

const { resolveComponent } = useTheme();
const ThemeOverride = computed(() => resolveComponent('Tabs'));

const selectTab = (id: string) => {
    emit('update:modelValue', id);
    emit('select', id);
};
</script>

<template>
    <component
        :is="ThemeOverride"
        v-if="ThemeOverride"
        v-bind="{ ...$props, ...$attrs }"
        @update:model-value="selectTab"
        @select="selectTab"
    />
    <div v-else v-bind="$attrs" class="flex items-center gap-2 border-b border-theme-border pb-3 overflow-x-auto">
        <button
            v-for="tab in tabs"
            :key="tab.id"
            type="button"
            @click="selectTab(tab.id)"
            :class="[
                'px-3.5 py-2 rounded-theme-md text-xs sm:text-sm font-medium theme-ui-control transition-all flex items-center gap-2 whitespace-nowrap cursor-pointer border',
                modelValue === tab.id
                    ? 'bg-theme-accent text-theme-text-on-accent border-theme-accent font-semibold shadow-xs'
                    : 'bg-theme-bg-card text-theme-text-secondary hover:text-theme-text-primary hover:bg-theme-bg-surface border-theme-border'
            ]"
        >
            <component v-if="tab.icon" :is="tab.icon" class="w-4 h-4 shrink-0" />
            <span>{{ tab.label }}</span>
        </button>
    </div>
</template>
