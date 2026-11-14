<script setup lang="ts">
import { computed } from 'vue';
import { useTheme } from '@shared/Composables/useTheme';

defineOptions({ inheritAttrs: false });

defineProps({
    title: {
        type: String,
        required: true,
    },
    subtitle: {
        type: String,
        default: '',
    },
});

const { resolveComponent } = useTheme();
const ThemeOverride = computed(() => resolveComponent('PageHeader'));
</script>

<template>
    <component :is="ThemeOverride" v-if="ThemeOverride" v-bind="{ ...$props, ...$attrs }">
        <template v-for="(_, slot) in $slots" #[slot]="slotProps">
            <slot :name="slot" v-bind="slotProps" />
        </template>
    </component>
    <div v-else v-bind="$attrs" class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-white">
                {{ title }}
            </h1>
            <p v-if="subtitle" class="text-sm text-slate-400 mt-1">
                {{ subtitle }}
            </p>
        </div>
        <div v-if="$slots.actions" class="flex items-center gap-3">
            <slot name="actions" />
        </div>
    </div>
</template>
