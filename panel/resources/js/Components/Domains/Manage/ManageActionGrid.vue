<script setup>
import { computed } from 'vue';
import {
    Globe,
    FileSpreadsheet,
    FileWarning,
    FileCode,
    FileEdit,
    Cpu,
    ShieldCheck,
    KeyRound,
    FolderOpen,
    UserPlus,
    UserMinus,
    ExternalLink
} from 'lucide-vue-next';

const props = defineProps({
    actions: {
        type: Array,
        required: true,
    },
    activeAction: {
        type: Object,
        default: null,
    },
    domain: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['trigger']);

const iconMap = {
    Globe,
    FileSpreadsheet,
    FileWarning,
    FileCode,
    FileEdit,
    Cpu,
    ShieldCheck,
    KeyRound,
    FolderOpen,
    UserPlus,
    UserMinus,
};

const handleActionClick = (action) => {
    if (action.type === 'route' && action.getUrl) {
        window.location.href = action.getUrl(props.domain);
        return;
    }
    emit('trigger', action);
};
</script>

<template>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        <div
            v-for="action in actions"
            :key="action.id"
            @click="handleActionClick(action)"
            :class="[
                'p-4 rounded-xl border transition-all cursor-pointer flex items-center gap-3.5 group',
                activeAction?.id === action.id
                    ? 'bg-blue-600/10 border-blue-500 shadow-sm shadow-blue-500/20'
                    : 'bg-theme-bg-card border-theme-border hover:border-theme-border-hover hover:bg-theme-bg-surface/60'
            ]"
        >
            <div
                :class="[
                    'w-10 h-10 rounded-lg flex items-center justify-center shrink-0 border transition-colors',
                    activeAction?.id === action.id
                        ? 'bg-blue-600 text-white border-blue-500'
                        : 'bg-theme-bg-surface text-theme-accent border-theme-border group-hover:border-theme-accent/50'
                ]"
            >
                <component :is="iconMap[action.icon] || Globe" class="w-5 h-5" />
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-bold text-theme-text-primary group-hover:text-theme-accent transition-colors truncate">
                        {{ action.title }}
                    </h4>
                    <ExternalLink v-if="action.type === 'route'" class="w-3.5 h-3.5 text-theme-text-muted shrink-0 ml-1" />
                </div>
                <p class="text-[11px] text-theme-text-secondary truncate mt-0.5">
                    {{ action.description }}
                </p>
            </div>
        </div>
    </div>
</template>

