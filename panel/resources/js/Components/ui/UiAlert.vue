<script setup lang="ts">
import { computed } from 'vue';
import { useTheme } from '@/Composables/useTheme';

const { resolveComponent } = useTheme();
const concrete = computed(() => resolveComponent('Alert'));
</script>

<template>
    <component :is="concrete" v-if="concrete" v-bind="$attrs">
        <template v-for="(_, slot) in $slots" #[slot]="slotProps">
            <slot :name="slot" v-bind="slotProps" />
        </template>
    </component>
    <div v-else class="p-3 rounded bg-blue-900/50 border border-blue-700 text-blue-200 text-sm" v-bind="$attrs">
        <slot />
    </div>
</template>
