<script setup lang="ts">
defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },
    id: {
        type: String,
        default: '',
    },
    label: {
        type: String,
        default: '',
    },
    options: {
        type: Array,
        default: () => [], // [{ value: '...', label: '...' }] or array of primitives
    },
    error: {
        type: String,
        default: '',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    required: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="space-y-1">
        <label v-if="label" :for="id" class="block text-xs font-mono font-medium text-[#98a6b8] uppercase tracking-wider">
            {{ label }} <span v-if="required" class="text-[#ff6b35]">*</span>
        </label>
        <div class="relative">
            <select
                :id="id"
                :value="modelValue"
                :disabled="disabled"
                :required="required"
                @change="$emit('update:modelValue', $event.target.value)"
                :class="[
                    'w-full px-3 py-2 bg-[#080a0e] border rounded-sm text-xs font-mono text-[#d6dce7] transition-colors focus:outline-none disabled:opacity-50 appearance-none pr-8 cursor-pointer',
                    error ? 'border-rose-500/80 focus:border-rose-400' : 'border-[#1e2634] focus:border-[#ff6b35]'
                ]"
            >
                <slot>
                    <option
                        v-for="opt in options"
                        :key="typeof opt === 'object' ? opt.value : opt"
                        :value="typeof opt === 'object' ? opt.value : opt"
                        class="bg-[#0e1217] text-white"
                    >
                        {{ typeof opt === 'object' ? opt.label : opt }}
                    </option>
                </slot>
            </select>
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2.5 text-[#586477]">
                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                </svg>
            </div>
        </div>
        <p v-if="error" class="text-[11px] font-mono text-rose-400 mt-1">
            {{ error }}
        </p>
    </div>
</template>
