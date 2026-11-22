<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import UiModal from '@shared/Ui/UiModal.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
import CopyableCodeBlock from '@domains/Mail/Components/CopyableCodeBlock.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close']);

const challenge = ref(null);
const loadingChallenge = ref(false);
const challengeError = ref('');

const form = useForm({
    domain: '',
    verify_dns_txt: true,
    default_quota_mb: 1024,
    max_accounts: 50,
    outbound_hourly_limit: 200,
});

watch(() => props.show, (visible) => {
    if (visible) {
        form.reset();
        form.clearErrors();
        form.verify_dns_txt = true;
        challenge.value = null;
        challengeError.value = '';
    }
});

watch(() => form.domain, (newVal) => {
    if (challenge.value && challenge.value.domain !== newVal.trim().toLowerCase()) {
        challenge.value = null;
    }
    challengeError.value = '';
});

const generateChallenge = async () => {
    const trimmed = form.domain.trim();
    if (!trimmed) {
        challengeError.value = 'Enter a domain name first.';
        return;
    }

    loadingChallenge.value = true;
    challengeError.value = '';
    form.clearErrors('domain');

    try {
        const response = await fetch(`/email/domains/dns-challenge?domain=${encodeURIComponent(trimmed)}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const payload = await response.json();

        if (!response.ok) {
            challenge.value = null;
            challengeError.value = payload?.errors?.domain?.[0] || payload?.message || 'Unable to generate DNS TXT verification token.';
            return;
        }

        challenge.value = payload;
        form.domain = payload.domain;
    } catch {
        challenge.value = null;
        challengeError.value = 'Network error while generating DNS TXT verification token.';
    } finally {
        loadingChallenge.value = false;
    }
};

const submit = () => {
    form.verify_dns_txt = true;
    form.post('/email/domains', {
        preserveScroll: true,
        onSuccess: () => {
            emit('close');
        },
    });
};

const handleClose = () => {
    if (!form.processing && !loadingChallenge.value) {
        emit('close');
    }
};
</script>

<template>
    <UiModal
        :show="show"
        title="Verify Standalone Mail Domain (DNS TXT)"
        max-width="lg"
        :closeable="!form.processing && !loadingChallenge"
        @close="handleClose"
    >
        <form id="verify-txt-domain-form" class="space-y-4" @submit.prevent="submit">
            <UiAlert variant="info">
                <p class="text-xs">
                    Use DNS TXT verification to enable email hosting for a domain whose website is hosted elsewhere. After verification, point
                    <code class="font-mono">mail.{{ form.domain || 'yourdomain.com' }}</code>
                    A/AAAA records to this server before requesting an HTTP-01 ACME TLS certificate.
                </p>
            </UiAlert>

            <div class="flex flex-col sm:flex-row sm:items-end gap-2">
                <div class="flex-1">
                    <UiInput
                        id="verify-txt-domain-input"
                        v-model="form.domain"
                        label="Domain Name"
                        placeholder="example.org"
                        :error="form.errors.domain || challengeError"
                        :aria-describedby="(form.errors.domain || challengeError) ? 'verify-txt-domain-error' : undefined"
                        required
                    />
                </div>
                <UiButton
                    type="button"
                    variant="secondary"
                    :loading="loadingChallenge"
                    :disabled="!form.domain.trim() || form.processing"
                    @click="generateChallenge"
                >
                    Generate TXT Token
                </UiButton>
            </div>

            <div v-if="challenge" class="space-y-3 rounded-theme-md border border-theme-border-subtle bg-theme-bg-surface/40 p-3" aria-live="polite">
                <p class="text-xs text-theme-text-secondary">
                    Publish the following <strong>TXT</strong> record at your DNS provider, then click <strong>Verify DNS TXT</strong> below:
                </p>

                <CopyableCodeBlock
                    label="TXT Record Host"
                    :value="challenge.record_name"
                />

                <CopyableCodeBlock
                    label="TXT Record Value"
                    :value="challenge.value"
                />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1">
                <UiInput
                    id="verify-txt-default-quota"
                    v-model.number="form.default_quota_mb"
                    type="number"
                    min="0"
                    label="Default Quota (MB)"
                    hint="0 = unlimited"
                    :error="form.errors.default_quota_mb"
                />
                <UiInput
                    id="verify-txt-max-accounts"
                    v-model.number="form.max_accounts"
                    type="number"
                    min="0"
                    label="Max Mailboxes"
                    hint="0 = unlimited"
                    :error="form.errors.max_accounts"
                />
                <UiInput
                    id="verify-txt-outbound-limit"
                    v-model.number="form.outbound_hourly_limit"
                    type="number"
                    min="1"
                    label="Outbound / Hour"
                    hint="Default: 200"
                    :error="form.errors.outbound_hourly_limit"
                />
            </div>
        </form>

        <template #footer>
            <UiButton variant="ghost" :disabled="form.processing" @click="handleClose">
                Cancel
            </UiButton>
            <UiButton
                type="submit"
                form="verify-txt-domain-form"
                variant="primary"
                :loading="form.processing"
                :disabled="!form.domain.trim() || !challenge"
            >
                Verify DNS TXT
            </UiButton>
        </template>
    </UiModal>
</template>
