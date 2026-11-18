<script setup lang="ts">
import { computed } from 'vue';
import UiTabs from '@shared/Ui/UiTabs.vue';
import {
    LayoutGrid,
    GitFork,
    FileText,
    SlidersHorizontal,
    Lock,
    Folder,
    Rocket
} from '@lucide/vue';

const props = defineProps({
    tabs: {
        type: Array,
        required: true,
    },
    activeTab: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(['select']);

const iconMap = {
    LayoutGrid,
    GitFork,
    FileText,
    SlidersHorizontal,
    Lock,
    Folder,
    Rocket,
};

const resolvedTabs = computed(() =>
    (props.tabs as any[]).map((tab) => ({
        ...tab,
        icon: iconMap[tab.icon] || LayoutGrid,
    }))
);
</script>

<template>
    <UiTabs
        :model-value="activeTab"
        :tabs="resolvedTabs"
        @select="emit('select', $event)"
    />
</template>

