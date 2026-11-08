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
    <div class="bg-[#0c1017] border border-[#1e2634] rounded-sm p-4 sm:p-5 shadow-lg">
        <div v-if="title || $slots.header" class="flex items-center justify-between pb-3 mb-4 border-b border-[#191f2a]">
            <div>
                <h3 v-if="title" class="text-sm font-bold font-mono text-white uppercase tracking-wider">
                    {{ title }}
                </h3>
                <p v-if="subtitle" class="text-[10px] font-mono text-[#586477] mt-0.5">
                    {{ subtitle }}
                </p>
            </div>
            <div v-if="$slots.header">
                <slot name="header" />
            </div>
        </div>

        <slot />

        <div v-if="$slots.footer" class="mt-4 pt-3 border-t border-[#191f2a]">
            <slot name="footer" />
        </div>
    </div>
</template>
