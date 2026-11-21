<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import UiModal from '@shared/Ui/UiModal.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
import CopyableCodeBlock from '@domains/Mail/Components/CopyableCodeBlock.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    availableWebDomains: {
        type: Array,
        default: () => [],
    },
    canAdminOverride: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close']);

const mode = ref('webserver');
const challenge = ref(null);
const loadingChallenge = ref(false);
const challengeError = ref('');

const form = useForm({
    domain: '',
    admin_override: false,
    verify_dns_txt: false,
    default_quota_mb: 1024,
    max_accounts: 50,
    outbound_hourly_limit: 200,
});

const webDomainOptions = computed(() => [
    { value: '', label: props.availableWebDomains.length ? 'Select a hosted domain...' : 'No unconfigured hosted domains available' },
    ...props.availableWebDomains.map((d) => ({ value: d, label: d })),
]);

watch(() => props.show, (visible) => {
    if (visible) {
        if (props.availableWebDomains.length > 0) {
            mode.value = 'webserver';
        } else {
            mode.value = 'dns_txt';
        }
        form.reset();
        form.clearErrors();
        form.admin_override = mode.value === 'admin_override';
        form.verify_dns_txt = mode.value === 'dns_txt';
        form.domain = mode.value === 'webserver' && props.availableWebDomains.length === 1
            ? props.availableWebDomains[0]
            : '';
        challenge.value = null;
        challengeError.value = '';
    }
});

watch(mode, (newMode) => {
    form.admin_override = newMode === 'admin_override';
    form.verify_dns_txt = newMode === 'dns_txt';
    form.domain = '';
    form.clearErrors('domain');
    challenge.value = null;
    challengeError.value = '';
});

watch(() => form.domain, (newVal) => {
    if (mode.value === 'dns_txt' && challenge.value && challenge.value.domain !== newVal.trim().toLowerCase()) {
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
        title="Enable Mail Domain"
        max-width="lg"
        :closeable="!form.processing"
        @close="handleClose"
    >
        <form id="enable-mail-domain-form" class="space-y-4" @submit.prevent="submit">
            <div class="flex flex-wrap items-center gap-2 border-b border-theme-border-subtle pb-3">
                <button
                    type="button"
                    class="px-3 py-1.5 text-xs font-medium rounded-theme-md transition-colors cursor-pointer"
                    :class="mode === 'webserver'
                        ? 'bg-theme-accent text-theme-text-on-accent'
                        : 'bg-theme-bg-surface text-theme-text-secondary hover:text-theme-text-primary'"
                    @click="mode = 'webserver'"
                >
                    Hosted Web Domain
                </button>
                <button
                    type="button"
                    class="px-3 py-1.5 text-xs font-medium rounded-theme-md transition-colors cursor-pointer"
                    :class="mode === 'dns_txt'
                        ? 'bg-theme-accent text-theme-text-on-accent'
                        : 'bg-theme-bg-surface text-theme-text-secondary hover:text-theme-text-primary'"
                    @click="mode = 'dns_txt'"
                >
                    Standalone Mail Domain (DNS TXT)
                </button>
                <button
                    v-if="canAdminOverride"
                    type="button"
                    class="px-3 py-1.5 text-xs font-medium rounded-theme-md transition-colors cursor-pointer"
                    :class="mode === 'admin_override'
                        ? 'bg-theme-accent text-theme-text-on-accent'
                        : 'bg-theme-bg-surface text-theme-text-secondary hover:text-theme-text-primary'"
                    @click="mode = 'admin_override'"
                >
                    Admin Override
                </button>
            </div>

            <UiAlert v-if="mode === 'admin_override'" variant="warning">
                <p class="text-xs">
                    <strong>External-web domain notice:</strong> Domains enabled via Admin Override without a local web vhost use the server's fallback TLS certificate until a dedicated mail certificate is configured.
                </p>
            </UiAlert>

            <UiAlert v-if="mode === 'dns_txt'" variant="info">
                <p class="text-xs">
                    Prove ownership of an external domain by publishing a DNS TXT record. Once verified, you can request a dedicated HTTP-01 ACME TLS certificate for
                    <code class="font-mono">mail.{{ form.domain || 'yourdomain.com' }}</code>.
                </p>
            </UiAlert>

            <div v-if="mode === 'webserver'">
                <UiSelect
                    id="enable-mail-domain-select"
                    v-model="form.domain"
                    label="Hosted Domain"
                    :options="webDomainOptions"
                    :error="form.errors.domain"
                    :aria-describedby="form.errors.domain ? 'enable-mail-domain-error' : undefined"
                    required
                />
            </div>

            <div v-else-if="mode === 'dns_txt'" class="space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-end gap-2">
                    <div class="flex-1">
                        <UiInput
                            id="enable-mail-dns-txt-input"
                            v-model="form.domain"
                            label="Domain Name"
                            placeholder="example.org"
                            :error="form.errors.domain || challengeError"
                            :aria-describedby="(form.errors.domain || challengeError) ? 'enable-mail-domain-error' : undefined"
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

                <div
                    v-if="challenge"
                    class="space-y-3 rounded-theme-md border border-theme-border-subtle bg-theme-bg-surface/40 p-3"
                    aria-live="polite"
                >
                    <p class="text-xs text-theme-text-secondary">
                        Add this <strong>TXT</strong> record at your DNS provider, then click <strong>Verify DNS TXT</strong>:
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
            </div>

            <div v-else>
                <UiInput
                    id="enable-mail-domain-input"
                    v-model="form.domain"
                    label="Domain Name"
                    placeholder="example.org"
                    hint="Enter a valid FQDN. Reserved and public-suffix domains (e.g., gmail.com) are blocked."
                    :error="form.errors.domain"
                    :aria-describedby="form.errors.domain ? 'enable-mail-domain-error' : undefined"
                    required
                />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1">
                <UiInput
                    id="enable-mail-default-quota"
                    v-model.number="form.default_quota_mb"
                    type="number"
                    min="0"
                    label="Default Quota (MB)"
                    hint="0 = unlimited"
                    :error="form.errors.default_quota_mb"
                />
                <UiInput
                    id="enable-mail-max-accounts"
                    v-model.number="form.max_accounts"
                    type="number"
                    min="0"
                    label="Max Mailboxes"
                    hint="0 = unlimited"
                    :error="form.errors.max_accounts"
                />
                <UiInput
                    id="enable-mail-outbound-limit"
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
                form="enable-mail-domain-form"
                variant="primary"
                :loading="form.processing"
                :disabled="!form.domain || (mode === 'dns_txt' && !challenge)"
            >
                {{ mode === 'dns_txt' ? 'Verify DNS TXT' : 'Enable Mail Domain' }}
            </UiButton>
        </template>
    </UiModal>
</template>
