<script setup>
import { computed } from 'vue';
import { useTheme } from '@/Composables/useTheme';

const { resolveComponent } = useTheme();
const concrete = computed(() => resolveComponent('DataTable'));
</script>

<template>
    <component :is="concrete" v-if="concrete" v-bind="$attrs">
        <template v-for="(_, slot) in $slots" #[slot]="slotProps">
            <slot :name="slot" v-bind="slotProps" />
        </template>
    </component>
    <div v-else class="overflow-x-auto" v-bind="$attrs">
        <table class="w-full">
            <slot />
        </table>
    </div>
</template>
