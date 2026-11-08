<script setup>
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
        default: () => [],
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
            <select
                :id="id"
                :value="modelValue"
                :disabled="disabled"
                :required="required"
                @change="$emit('update:modelValue', $event.target.value)"
                :class="[
                    'w-full px-3.5 py-2.5 bg-slate-950 border rounded-lg text-sm text-slate-100 transition-colors focus:outline-none focus:ring-2 disabled:opacity-50 appearance-none pr-9 cursor-pointer',
                    error
                        ? 'border-rose-500/80 focus:border-rose-500 focus:ring-rose-500/20'
                        : 'border-slate-800 focus:border-sky-500 focus:ring-sky-500/20'
                ]"
            >
                <slot>
                    <option
                        v-for="opt in options"
                        :key="typeof opt === 'object' ? opt.value : opt"
                        :value="typeof opt === 'object' ? opt.value : opt"
                        class="bg-slate-900 text-slate-100"
                    >
                        {{ typeof opt === 'object' ? opt.label : opt }}
                    </option>
                </slot>
            </select>
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-slate-400">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                </svg>
            </div>
        </div>
        <p v-if="error" class="text-xs text-rose-400 mt-1">
            {{ error }}
        </p>
    </div>
</template>
