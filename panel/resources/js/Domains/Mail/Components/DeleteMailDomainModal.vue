<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import UiModal from '@shared/Ui/UiModal.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
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

const confirmDomain = ref('');

const form = useForm({
    delete_ssl: true,
});

watch(
    () => [props.show, props.domain],
    ([visible]) => {
        if (visible) {
            confirmDomain.value = '';
            form.delete_ssl = true;
            form.clearErrors();
        }
    },
    { immediate: true },
);

const canConfirm = computed(() => {
    if (!props.domain?.domain) {
        return false;
    }

    return confirmDomain.value.trim().toLowerCase() === props.domain.domain.toLowerCase();
});

const submit = () => {
    if (!props.domain || !canConfirm.value) {
        return;
    }

    form.delete(`/email/domains/${props.domain.id}`, {
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
        :title="domain ? `Delete Mail Domain — ${domain.domain}` : 'Delete Mail Domain'"
        max-width="md"
        :closeable="!form.processing"
        @close="handleClose"
    >
        <form id="delete-mail-domain-form" class="space-y-4" @submit.prevent="submit">
            <UiAlert variant="danger">
                This action is permanent and cannot be undone. Deleting
                <strong class="font-mono">{{ domain?.domain }}</strong>
                will permanently remove:
                <ul class="mt-2 list-disc list-inside space-y-1 text-xs">
                    <li>
                        All mailboxes ({{ domain?.accounts_count ?? 0 }}), forwarders/aliases, and stored email data under
                        <code class="font-mono">/var/vmail/{{ domain?.domain }}</code>
                    </li>
                    <li>All DKIM signing keys and private key files</li>
                    <li>Postfix, Dovecot, and Rspamd configuration entries for this domain</li>
                </ul>
            </UiAlert>

            <div v-if="!domain?.uses_shared_mail_host" class="pt-1">
                <UiToggle
                    v-model="form.delete_ssl"
                    label="Delete standalone mail SSL certificate"
                    :description="`Also remove the Let's Encrypt certificate and OpenLiteSpeed ACME mapping for ${domain?.mail_hostname || `mail.${domain?.domain ?? ''}`}.`"
                />
            </div>
            <p v-else class="text-xs text-theme-text-muted">
                This domain uses the server's shared mail hostname (<code class="font-mono">{{ domain.mail_hostname }}</code>). The shared server TLS certificate will not be removed.
            </p>

            <UiInput
                id="confirm-delete-mail-domain"
                v-model="confirmDomain"
                type="text"
                :label="`Type ${domain?.domain ?? ''} to confirm`"
                :placeholder="domain?.domain ?? ''"
                autocomplete="off"
                required
            />
        </form>

        <template #footer>
            <UiButton variant="ghost" :disabled="form.processing" @click="handleClose">
                Cancel
            </UiButton>
            <UiButton
                type="submit"
                form="delete-mail-domain-form"
                variant="danger"
                :loading="form.processing"
                :disabled="!canConfirm || form.processing"
            >
                Delete Mail Domain
            </UiButton>
        </template>
    </UiModal>
</template>
