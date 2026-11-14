<script setup lang="ts">
import { computed } from 'vue';
import {
    LayoutGrid,
    GitFork,
    FileText,
    SlidersHorizontal,
    Lock,
    Folder
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
};
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-xl p-2 shadow-sm">
        <div class="flex items-center gap-1.5 overflow-x-auto no-scrollbar">
            <button
                v-for="tab in tabs"
                :key="tab.id"
                @click="emit('select', tab.id)"
                :class="[
                    'flex items-center gap-2 px-4 py-2 rounded-lg text-xs font-semibold whitespace-nowrap transition-all',
                    activeTab === tab.id
                        ? 'bg-blue-600 text-white shadow-sm shadow-blue-600/30 font-bold'
                        : 'text-theme-text-secondary hover:text-theme-text-primary hover:bg-theme-bg-surface'
                ]"
            >
                <component :is="iconMap[tab.icon] || LayoutGrid" class="w-4 h-4 shrink-0" />
                <span>{{ tab.label }}</span>
            </button>
        </div>
    </div>
</template>

