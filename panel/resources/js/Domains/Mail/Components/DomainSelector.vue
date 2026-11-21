<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import UiSelect from '@shared/Ui/UiSelect.vue';

const props = defineProps({
    domains: {
        type: Array,
        default: () => [],
    },
    modelValue: {
        type: String,
        default: '',
    },
    routeUrl: {
        type: String,
        required: true,
    },
    queryParam: {
        type: String,
        default: 'domain',
    },
    label: {
        type: String,
        default: 'Domain',
    },
    id: {
        type: String,
        default: 'mail-domain-selector',
    },
    includeAllOption: {
        type: Boolean,
        default: false,
    },
    allLabel: {
        type: String,
        default: 'All Domains',
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue', 'change']);

const options = computed(() => {
    const mapped = props.domains.map((item) => {
        if (typeof item === 'string') {
            return { value: item, label: item };
        }
        return {
            value: item.domain ?? String(item.id ?? ''),
            label: item.domain ?? String(item.id ?? ''),
        };
    });

    if (props.includeAllOption) {
        return [{ value: '', label: props.allLabel }, ...mapped];
    }

    return mapped;
});

const handleChange = (value) => {
    emit('update:modelValue', value);
    emit('change', value);

    const params = value ? { [props.queryParam]: value } : {};
    router.get(props.routeUrl, params, {
        preserveState: true,
        replace: true,
    });
};
</script>

<template>
    <UiSelect
        :id="id"
        :label="label"
        :model-value="modelValue"
        :options="options"
        :disabled="disabled || options.length === 0"
        @update:model-value="handleChange"
    />
</template>
