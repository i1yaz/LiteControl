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
    type: {
        type: String,
        default: 'text',
    },
    placeholder: {
        type: String,
        default: '',
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
            <input
                :id="id"
                :type="type"
                :value="modelValue"
                :placeholder="placeholder"
                :disabled="disabled"
                :required="required"
                @input="$emit('update:modelValue', $event.target.value)"
                :class="[
                    'w-full px-3 py-2 bg-[#080a0e] border rounded-sm text-xs font-mono text-[#d6dce7] placeholder-[#4e5a6c] transition-colors focus:outline-none disabled:opacity-50',
                    error ? 'border-rose-500/80 focus:border-rose-400' : 'border-[#1e2634] focus:border-[#ff6b35]'
                ]"
            />
        </div>
        <p v-if="error" class="text-[11px] font-mono text-rose-400 mt-1">
            {{ error }}
        </p>
    </div>
</template>
