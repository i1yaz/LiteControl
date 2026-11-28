<script setup>
import { computed, ref, watch } from 'vue';
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
    canUseSharedMailHost: {
        type: Boolean,
        default: false,
    },
    serverMailHostname: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['close']);

const challenge = ref(null);
const loadingChallenge = ref(false);
const challengeError = ref('');

const form = useForm({
    domain: '',
    mail_subdomain: 'mail',
    use_shared_mail_host: false,
    verify_dns_txt: true,
    default_quota_mb: 1024,
    max_accounts: 50,
    outbound_hourly_limit: 200,
});

const canUseSharedHost = computed(() => props.canUseSharedMailHost && Boolean(props.serverMailHostname));

const cleanDomain = computed(() => form.domain.trim().toLowerCase());

const cleanSubdomain = computed(() =>
    form.mail_subdomain.trim().toLowerCase().replace(/^\.+|\.+$/g, ''),
);

const resolvedMailHost = computed(() => {
    if (form.use_shared_mail_host && props.serverMailHostname) {
        return props.serverMailHostname;
    }

    const base = cleanDomain.value || 'example.org';

    return cleanSubdomain.value ? `${cleanSubdomain.value}.${base}` : base;
});

watch(() => props.show, (visible) => {
    if (visible) {
        form.reset();
        form.clearErrors();
        form.mail_subdomain = 'mail';
        form.use_shared_mail_host = false;
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
        challengeError.value = 'Enter an email domain name first.';
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
                    Use DNS TXT verification to enable email hosting for a domain whose website is hosted elsewhere. Enter the <strong>base email domain</strong> (after <code class="font-mono">@</code>, e.g. <code class="font-mono">i1yas.top</code>) and choose your mail subdomain below.
                </p>
            </UiAlert>

            <div class="flex flex-col sm:flex-row sm:items-end gap-2">
                <div class="flex-1">
                    <UiInput
                        id="verify-txt-domain-input"
                        v-model="form.domain"
                        label="Email Domain"
                        placeholder="example.org"
                        hint="The domain used in email addresses (e.g. user@example.org). Do not prefix with mail."
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

            <!-- Mail Hostname Configuration -->
            <div class="space-y-3 rounded-theme-md border border-theme-border-subtle bg-theme-bg-surface/30 p-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xs font-semibold text-theme-text-primary">
                        Mail Hostname (MX / IMAP / SMTP / TLS)
                    </span>
                    <div v-if="canUseSharedHost" class="flex items-center gap-1.5">
                        <button
                            type="button"
                            class="px-2.5 py-1 text-[11px] font-medium rounded-theme-sm transition-colors cursor-pointer"
                            :class="!form.use_shared_mail_host
                                ? 'bg-theme-accent text-theme-text-on-accent'
                                : 'bg-theme-bg-surface text-theme-text-secondary hover:text-theme-text-primary'"
                            @click="form.use_shared_mail_host = false"
                        >
                            Custom Subdomain
                        </button>
                        <button
                            type="button"
                            class="px-2.5 py-1 text-[11px] font-medium rounded-theme-sm transition-colors cursor-pointer"
                            :class="form.use_shared_mail_host
                                ? 'bg-theme-accent text-theme-text-on-accent'
                                : 'bg-theme-bg-surface text-theme-text-secondary hover:text-theme-text-primary'"
                            @click="form.use_shared_mail_host = true"
                        >
                            Server Shared Host (Admin)
                        </button>
                    </div>
                </div>

                <div v-if="!form.use_shared_mail_host" class="space-y-1.5">
                    <label for="verify-txt-subdomain-input" class="block text-xs font-medium text-theme-text-secondary">
                        Mail Subdomain Prefix
                    </label>
                    <div class="flex items-stretch rounded-theme-md border border-theme-border bg-theme-bg-base overflow-hidden focus-within:border-theme-accent">
                        <input
                            id="verify-txt-subdomain-input"
                            v-model="form.mail_subdomain"
                            type="text"
                            maxlength="63"
                            placeholder="mail"
                            class="w-32 sm:w-40 px-3 py-2 text-xs font-mono bg-transparent text-theme-text-primary focus:outline-none"
                        />
                        <span class="inline-flex items-center px-3 py-2 text-xs font-mono text-theme-text-muted bg-theme-bg-surface border-l border-theme-border-subtle truncate">
                            .{{ cleanDomain || 'example.org' }}
                        </span>
                    </div>
                    <p class="text-[11px] text-theme-text-muted">
                        After verification, point <code class="font-mono">{{ resolvedMailHost }}</code> A/AAAA records to this server before requesting an HTTP-01 ACME TLS certificate.
                    </p>
                    <p v-if="form.errors.mail_subdomain" class="text-xs text-theme-danger">
                        {{ form.errors.mail_subdomain }}
                    </p>
                </div>

                <div v-else class="space-y-1">
                    <p class="text-xs text-theme-text-secondary">
                        This domain will use the server's shared mail hostname
                        <code class="font-mono font-semibold text-theme-text-primary">{{ serverMailHostname }}</code>
                        and its existing TLS certificate.
                    </p>
                    <p v-if="form.errors.use_shared_mail_host" class="text-xs text-theme-danger">
                        {{ form.errors.use_shared_mail_host }}
                    </p>
                </div>

                <!-- Live Preview -->
                <div class="pt-2 border-t border-theme-border-subtle text-[11px] text-theme-text-secondary space-y-1">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-theme-text-muted">Email addresses:</span>
                        <code class="font-mono text-theme-text-primary">user@{{ cleanDomain || 'example.org' }}</code>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-theme-text-muted">Mail host (MX / IMAP / SMTP):</span>
                        <code class="font-mono text-theme-text-primary">{{ resolvedMailHost }}</code>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-theme-text-muted">TLS certificate:</span>
                        <span v-if="form.use_shared_mail_host" class="font-mono text-theme-text-primary">
                            Shared ({{ serverMailHostname }})
                        </span>
                        <code v-else class="font-mono text-theme-text-primary">{{ resolvedMailHost }}</code>
                    </div>
                </div>
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
