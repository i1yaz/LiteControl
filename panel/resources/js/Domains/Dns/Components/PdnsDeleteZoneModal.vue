<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { AlertTriangle, Trash2 } from '@lucide/vue';
import UiModal from '@shared/Ui/UiModal.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    zone: {
        type: Object as () => Record<string, any> | null,
        default: null,
    },
});

const emit = defineEmits(['close']);

const form = useForm({
    confirm_name: '',
});

watch(
    () => [props.show, props.zone?.id],
    ([isOpen]) => {
        if (isOpen) {
            form.reset();
            form.clearErrors();
        }
    }
);

const expectedName = computed(() => {
    if (!props.zone) {
        return '';
    }
    return String(props.zone.ascii_name || props.zone.name || '').replace(/\.$/, '');
});

const isConfirmMatched = computed(() => {
    if (!props.zone) {
        return false;
    }
    const typed = form.confirm_name.trim().toLowerCase().replace(/\.$/, '');
    const ascii = expectedName.value.toLowerCase();
    const display = String(props.zone.display_name || '').toLowerCase().replace(/\.$/, '');

    return typed !== '' && (typed === ascii || typed === display);
});

const submitDelete = () => {
    if (!props.zone) {
        return;
    }

    const encodedId = encodeURIComponent(String(props.zone.id || props.zone.name));
    form.delete(`/dns/zones/${encodedId}`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('close');
        },
    });
};
</script>

<template>
    <UiModal
        :show="show"
        :title="__('Delete Authoritative DNS Zone')"
        max-width="md"
        @close="emit('close')"
    >
        <form v-if="zone" @submit.prevent="submitDelete" class="space-y-4">
            <UiAlert variant="danger">
                <div class="flex items-start gap-2.5">
                    <AlertTriangle class="w-4 h-4 shrink-0 mt-0.5" />
                    <div class="space-y-1 text-xs">
                        <div class="font-semibold">
                            {{ __('Permanent Zone Deletion') }}
                        </div>
                        <p>
                            {{ __('Deleting this zone immediately removes all authoritative RRSets, comments, and zone metadata from PowerDNS. Domains delegated to this server will stop resolving.') }}
                        </p>
                    </div>
                </div>
            </UiAlert>

            <div class="p-3 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle text-xs space-y-1">
                <div class="flex items-center justify-between">
                    <span class="text-theme-text-secondary">{{ __('Target Zone:') }}</span>
                    <span class="font-mono font-semibold text-theme-text-primary">{{ zone.name }}</span>
                </div>
                <div v-if="zone.is_idn" class="flex items-center justify-between">
                    <span class="text-theme-text-secondary">{{ __('IDN Display:') }}</span>
                    <span class="font-mono text-theme-text-primary">{{ zone.display_name }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-theme-text-secondary">{{ __('Mode / Serial:') }}</span>
                    <span class="font-mono text-theme-text-primary">{{ zone.kind }} ({{ zone.serial || '—' }})</span>
                </div>
            </div>

            <UiInput
                id="pdns-delete-confirm-name"
                v-model="form.confirm_name"
                :label="__('Type the zone name to confirm deletion')"
                :placeholder="expectedName"
                :hint="__('Enter ') + expectedName + __(' (with or without trailing dot) to unlock deletion.')"
                :error="form.errors.confirm_name"
                required
            />

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-theme-border-subtle">
                <UiButton
                    type="button"
                    variant="secondary"
                    :disabled="form.processing"
                    @click="emit('close')"
                >
                    {{ __('Cancel') }}
                </UiButton>
                <UiButton
                    type="submit"
                    variant="danger"
                    :loading="form.processing"
                    :disabled="form.processing || !isConfirmMatched"
                >
                    <Trash2 v-if="!form.processing" class="w-4 h-4" />
                    <span>{{ form.processing ? __('Deleting...') : __('Delete Zone') }}</span>
                </UiButton>
            </div>
        </form>
    </UiModal>
</template>
