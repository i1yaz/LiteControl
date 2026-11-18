<script setup lang="ts">
import { computed, onMounted, onUnmounted, watch } from 'vue';
import { X } from '@lucide/vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: '' },
    maxWidth: { type: String, default: 'md' },
    closeable: { type: Boolean, default: true },
});

const emit = defineEmits(['close']);

const maxWidthClass = computed(() => {
    return {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
        '4xl': 'sm:max-w-4xl',
        '6xl': 'sm:max-w-6xl',
    }[props.maxWidth] || 'sm:max-w-md';
});

const close = () => {
    if (props.closeable) emit('close');
};

const closeOnEscape = (e) => {
    if (e.key === 'Escape' && props.show && props.closeable) close();
};

onMounted(() => document.addEventListener('keydown', closeOnEscape));
onUnmounted(() => {
    document.removeEventListener('keydown', closeOnEscape);
    document.body.style.overflow = '';
});

watch(() => props.show, (val) => {
    document.body.style.overflow = val ? 'hidden' : '';
});
</script>

<template>
    <Teleport to="body">
        <Transition leave-active-class="duration-200">
            <div
                v-show="show"
                class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0 flex items-center justify-center"
                scroll-region
            >
                <Transition
                    enter-active-class="ease-out duration-250"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="ease-in duration-200"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div v-show="show" class="fixed inset-0 transform transition-all" @click="close">
                        <div class="absolute inset-0 bg-theme-bg-overlay backdrop-blur-md" />
                    </div>
                </Transition>

                <Transition
                    enter-active-class="ease-out duration-250"
                    enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-active-class="ease-in duration-200"
                    leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                >
                    <div
                        v-show="show"
                        :class="[
                            'relative bg-theme-bg-elevated border border-theme-border rounded-theme-lg text-theme-text-primary shadow-2xl transform transition-all w-full p-6 z-10 font-sans',
                            maxWidthClass
                        ]"
                    >
                        <div v-if="title || $slots.header || closeable" class="flex items-center justify-between pb-3 mb-4 border-b border-theme-border-subtle">
                            <div>
                                <h3 v-if="title" class="text-sm font-bold font-mono text-theme-text-primary uppercase tracking-wider">
                                    {{ title }}
                                </h3>
                                <div v-if="$slots.header">
                                    <slot name="header" />
                                </div>
                            </div>
                            <button
                                v-if="closeable"
                                @click="close"
                                class="p-1 rounded-theme-sm text-theme-text-muted hover:text-theme-text-primary hover:bg-theme-bg-surface transition-colors cursor-pointer"
                            >
                                <X class="w-4 h-4" />
                            </button>
                        </div>

                        <slot />

                        <div v-if="$slots.footer" class="mt-5 pt-3 border-t border-theme-border-subtle flex justify-end gap-2">
                            <slot name="footer" />
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
