<script setup lang="ts">
import { computed } from 'vue';
import { useTheme } from '@/Composables/useTheme';
import UiConfirmDialog from '@/Components/ui/UiConfirmDialog.vue';

const props = defineProps({
    isAuth: {
        type: Boolean,
        default: false,
    },
});

const { activeThemeModule } = useTheme();

const layoutComponent = computed(() => {
    if (!activeThemeModule.value?.layouts) return null;
    return props.isAuth
        ? activeThemeModule.value.layouts.auth
        : activeThemeModule.value.layouts.default;
});
</script>

<template>
    <component :is="layoutComponent" v-if="layoutComponent">
        <slot />
    </component>
    <div v-else>
        <slot />
    </div>
    <UiConfirmDialog />
</template>
