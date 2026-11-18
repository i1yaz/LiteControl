<script setup lang="ts">
import { computed } from 'vue';
import { useTheme } from '@shared/Composables/useTheme';
import UiConfirmDialog from '@shared/Ui/UiConfirmDialog.vue';
import PhpInstallStatusBar from '@shared/Components/PhpInstallStatusBar.vue';
import ModuleInstallStatusBar from '@shared/Components/ModuleInstallStatusBar.vue';

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
    <PhpInstallStatusBar v-if="!isAuth" />
    <ModuleInstallStatusBar v-if="!isAuth" />
    <component :is="layoutComponent" v-if="layoutComponent">
        <slot />
    </component>
    <div v-else>
        <slot />
    </div>
    <UiConfirmDialog />
</template>
