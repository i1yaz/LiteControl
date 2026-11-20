<script setup lang="ts">
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import UiModal from '@shared/Ui/UiModal.vue';
import UiPasswordInput from '@shared/Ui/UiPasswordInput.vue';
import UiButton from '@shared/Ui/UiButton.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    account: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['close']);

const form = useForm({
    password: '',
});

watch(
    () => props.show,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
    }
);

const submit = () => {
    if (!props.account) return;
    form.patch(`/ftp/${props.account.id}/password`, {
        preserveScroll: true,
        onSuccess: () => {
            emit('close');
        },
    });
};
</script>

<template>
    <UiModal :show="show" title="Change FTP Password" max-width="sm" @close="emit('close')">
        <form @submit.prevent="submit" class="space-y-4">
            <p class="text-xs text-theme-text-secondary">
                Updating password for <strong class="text-theme-text-primary">{{ account?.username }}</strong>
            </p>

            <UiPasswordInput
                id="rotate_ftp_password"
                v-model="form.password"
                label="New Password"
                :error="form.errors.password"
                required
            />

            <div class="pt-4 flex items-center justify-end gap-3 border-t border-theme-border">
                <UiButton type="button" variant="secondary" @click="emit('close')">
                    Cancel
                </UiButton>
                <UiButton type="submit" variant="primary" :disabled="form.processing" :loading="form.processing">
                    {{ form.processing ? 'Updating...' : 'Save Password' }}
                </UiButton>
            </div>
        </form>
    </UiModal>
</template>
