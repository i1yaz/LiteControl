<script setup>
defineProps({
    title: {
        type: String,
        default: '',
    },
    subtitle: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-5 sm:p-6 shadow-sm backdrop-blur">
        <div v-if="title || $slots.header" class="flex items-center justify-between pb-4 mb-5 border-b border-slate-800/80">
            <div>
                <h3 v-if="title" class="text-base font-semibold text-white">
                    {{ title }}
                </h3>
                <p v-if="subtitle" class="text-xs text-slate-400 mt-0.5">
                    {{ subtitle }}
                </p>
            </div>
            <div v-if="$slots.header">
                <slot name="header" />
            </div>
        </div>

        <slot />

        <div v-if="$slots.footer" class="mt-5 pt-4 border-t border-slate-800/80">
            <slot name="footer" />
        </div>
    </div>
</template>
