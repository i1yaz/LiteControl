<script setup>
import { computed, onMounted, onUnmounted, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
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
                        <div class="absolute inset-0 bg-[#06080b]/80 backdrop-blur-md" />
                    </div>
                </Transition>

                <Transition
                    enter-active-class="ease-out duration-250"
                    enter-from-class="opacity-0 translate-y-3 scale-98"
                    enter-to-class="opacity-100 translate-y-0 scale-100"
                    leave-active-class="ease-in duration-150"
                    leave-from-class="opacity-100 translate-y-0 scale-100"
                    leave-to-class="opacity-0 translate-y-3 scale-98"
                >
                    <div
                        v-show="show"
                        class="bg-[#0c1017] border border-[#1e2634] shadow-[0_20px_50px_rgba(0,0,0,0.8)] overflow-hidden transition-all sm:w-full sm:mx-auto z-10 rounded-sm relative before:absolute before:top-0 before:left-0 before:w-16 before:h-[2px] before:bg-[#ff6b35]"
                        :class="maxWidthClass"
                    >
                        <slot v-if="show" />
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
