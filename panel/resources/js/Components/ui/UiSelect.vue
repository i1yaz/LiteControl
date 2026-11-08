<script setup>
import { computed } from 'vue';
import { useTheme } from '@/Composables/useTheme';

const { resolveComponent } = useTheme();
const concrete = computed(() => resolveComponent('Select'));
</script>

<template>
    <component :is="concrete" v-if="concrete" v-bind="$attrs">
        <template v-for="(_, slot) in $slots" #[slot]="slotProps">
            <slot :name="slot" v-bind="slotProps" />
        </template>
    </component>
    <select v-else v-bind="$attrs" class="px-3 py-2 border rounded bg-slate-800 text-white">
        <slot />
    </select>
</template>
