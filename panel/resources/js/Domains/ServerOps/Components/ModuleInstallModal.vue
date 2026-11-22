<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import UiAlert from '@shared/Ui/UiAlert.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiModal from '@shared/Ui/UiModal.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    module: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['close', 'submitted']);

const form = useForm({
    hostname: '',
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

const isWebmail = computed(() => props.module?.key === 'mail_webmail');

const modalTitle = computed(() => {
    if (!props.module) return 'Install Module';
    return `Install ${props.module.title || props.module.key}`;
});

const hostnamePlaceholder = computed(() => (isWebmail.value ? 'webmail.example.com' : 'mail.example.com'));

const hostnameHint = computed(() =>
    isWebmail.value
        ? 'Server-level FQDN where SnappyMail will be served via OpenLiteSpeed (--hostname).'
        : 'Fully qualified mail server hostname used for Postfix HELO/EHLO and TLS (--hostname).'
);

const submit = () => {
    if (!props.module) return;

    form.post(`/modules/${props.module.key}/install`, {
        preserveScroll: true,
        onSuccess: () => {
            emit('submitted', props.module.key);
            emit('close');
        },
    });
};
</script>

<template>
    <UiModal :show="show" :title="modalTitle" max-width="md" @close="emit('close')">
        <form id="module-install-form" class="space-y-4" @submit.prevent="submit">
            <UiAlert variant="info">
                <template v-if="isWebmail">
                    SnappyMail will be provisioned in an isolated OpenLiteSpeed vhost on the hostname below.
                </template>
                <template v-else>
                    Installs Postfix, Dovecot, Rspamd-Light (DKIM &amp; outbound ratelimit), and postsrsd.
                </template>
            </UiAlert>

            <UiInput
                id="module-install-hostname"
                v-model="form.hostname"
                label="Server Hostname (FQDN)"
                :placeholder="hostnamePlaceholder"
                :hint="hostnameHint"
                :error="form.errors.hostname"
                aria-describedby="module-install-hostname-hint"
                required
            />
        </form>

        <template #footer>
            <UiButton variant="secondary" size="sm" :disabled="form.processing" @click="emit('close')">
                Cancel
            </UiButton>
            <UiButton
                type="submit"
                form="module-install-form"
                variant="primary"
                size="sm"
                :loading="form.processing"
            >
                Install Module
            </UiButton>
        </template>
    </UiModal>
</template>
