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
    <div class="space-y-1.5">
        <label v-if="label" :for="id" class="block text-xs font-medium text-slate-300">
            {{ label }} <span v-if="required" class="text-sky-400">*</span>
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
                    'w-full px-3.5 py-2.5 bg-slate-950 border rounded-lg text-sm text-slate-100 placeholder-slate-500 transition-colors focus:outline-none focus:ring-2 disabled:opacity-50',
                    error
                        ? 'border-rose-500/80 focus:border-rose-500 focus:ring-rose-500/20'
                        : 'border-slate-800 focus:border-sky-500 focus:ring-sky-500/20'
                ]"
            />
        </div>
        <p v-if="error" class="text-xs text-rose-400 mt-1">
            {{ error }}
        </p>
    </div>
</template>
