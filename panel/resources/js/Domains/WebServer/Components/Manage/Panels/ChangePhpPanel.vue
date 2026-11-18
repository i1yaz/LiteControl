<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Cpu, Check, X } from '@lucide/vue';
import { usePhpVersions } from '@shared/Composables/usePhpVersions';
import UiButton from '@shared/Ui/UiButton.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
    installedPhpVersions: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['close']);

const form = useForm({
    php_version: props.domain.php_version || '83',
});

const { phpOptions } = usePhpVersions(() => props.installedPhpVersions);

const submitChange = () => {
    form.post(`/domains/${props.domain.id}/php`, {
        preserveScroll: true,
        onSuccess: () => {
            emit('close');
        },
    });
};
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-5 shadow-xs space-y-5">
        <div class="flex items-center justify-between border-b border-theme-border pb-3">
            <div class="flex items-center gap-2">
                <Cpu class="w-4 h-4 text-theme-success-text" />
                <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary theme-ui-control">
                    Switch PHP Runtime for {{ domain.domain }}
                </h3>
            </div>

            <button @click="emit('close')" class="text-theme-text-muted hover:text-theme-text-primary p-1">
                <X class="w-4 h-4" />
            </button>
        </div>

        <form @submit.prevent="submitChange" class="space-y-4 max-w-md">
            <div>
                <UiSelect
                    id="php_version"
                    v-model="form.php_version"
                    label="Select PHP Version"
                    :error="form.errors.php_version"
                >
                    <option
                        v-for="opt in phpOptions"
                        :key="opt.value"
                        :value="opt.value"
                    >
                        {{ opt.label }} (lsphp{{ opt.value }})
                    </option>
                </UiSelect>
            </div>

            <p class="text-xs text-theme-text-secondary">
                Updates OpenLiteSpeed External Application Handler for this VirtualHost with zero downtime via graceful worker reload.
            </p>

            <div class="flex items-center gap-3 pt-2">
                <UiButton
                    type="submit"
                    variant="primary"
                    :disabled="form.processing"
                    :loading="form.processing"
                >
                    <Check v-if="!form.processing" class="w-3.5 h-3.5" />
                    <span>{{ form.processing ? 'Switching...' : 'Apply PHP Version' }}</span>
                </UiButton>

                <UiButton
                    type="button"
                    variant="secondary"
                    @click="emit('close')"
                >
                    Cancel
                </UiButton>
            </div>
        </form>
    </div>
</template>

