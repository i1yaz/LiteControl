<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import UiModal from '@shared/Ui/UiModal.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiToggle from '@shared/Ui/UiToggle.vue';
import UiButton from '@shared/Ui/UiButton.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    domain: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['close']);

const form = useForm({
    default_quota_mb: 1024,
    max_accounts: 50,
    outbound_hourly_limit: 200,
    active: true,
});

watch(
    () => [props.show, props.domain],
    ([visible, target]) => {
        if (visible && target) {
            form.default_quota_mb = target.default_quota_mb ?? 1024;
            form.max_accounts = target.max_accounts ?? 50;
            form.outbound_hourly_limit = target.outbound_hourly_limit ?? 200;
            form.active = Boolean(target.active);
            form.clearErrors();
        }
    },
    { immediate: true },
);

const submit = () => {
    if (!props.domain) {
        return;
    }

    form.put(`/email/domains/${props.domain.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            emit('close');
        },
    });
};

const handleClose = () => {
    if (!form.processing) {
        emit('close');
    }
};
</script>

<template>
    <UiModal
        :show="show"
        :title="domain ? `Edit Mail Domain — ${domain.domain}` : 'Edit Mail Domain'"
        max-width="md"
        :closeable="!form.processing"
        @close="handleClose"
    >
        <form id="edit-mail-domain-form" class="space-y-4" @submit.prevent="submit">
            <UiInput
                id="edit-mail-default-quota"
                v-model.number="form.default_quota_mb"
                type="number"
                min="0"
                label="Default Mailbox Quota (MB)"
                hint="Applied as the default when creating new mailboxes (0 = unlimited)."
                :error="form.errors.default_quota_mb"
                required
            />

            <UiInput
                id="edit-mail-max-accounts"
                v-model.number="form.max_accounts"
                type="number"
                min="0"
                label="Max Mailboxes"
                hint="Maximum number of mailboxes allowed on this domain (0 = unlimited)."
                :error="form.errors.max_accounts"
                required
            />

            <UiInput
                id="edit-mail-outbound-limit"
                v-model.number="form.outbound_hourly_limit"
                type="number"
                min="1"
                label="Outbound Hourly Limit"
                hint="Maximum outbound messages per hour across this domain."
                :error="form.errors.outbound_hourly_limit"
                required
            />

            <div class="pt-2 border-t border-theme-border-subtle">
                <UiToggle
                    v-model="form.active"
                    label="Domain Active"
                    description="Disabling suspends mail delivery and authentication for this domain."
                />
            </div>
        </form>

        <template #footer>
            <UiButton variant="ghost" :disabled="form.processing" @click="handleClose">
                Cancel
            </UiButton>
            <UiButton
                type="submit"
                form="edit-mail-domain-form"
                variant="primary"
                :loading="form.processing"
            >
                Save Changes
            </UiButton>
        </template>
    </UiModal>
</template>
