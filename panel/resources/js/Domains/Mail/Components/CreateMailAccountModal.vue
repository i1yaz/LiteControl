<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Eye, EyeOff, KeyRound } from '@lucide/vue';
import UiModal from '@shared/Ui/UiModal.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiToggle from '@shared/Ui/UiToggle.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    domains: {
        type: Array,
        default: () => [],
    },
    selectedDomain: {
        type: Object,
        default: null,
    },
    packageLimits: {
        type: Object,
        default: () => ({ used_accounts: 0, max_accounts: 0, max_quota_mb: 0 }),
    },
});

const emit = defineEmits(['close']);

const showPassword = ref(false);

const form = useForm({
    mail_domain_id: '',
    local_part: '',
    password: '',
    quota_mb: 1024,
    allow_imap: true,
    allow_pop3: false,
    allow_smtp: true,
});

const activeDomain = computed(() => {
    const id = Number(form.mail_domain_id);
    return props.domains.find((d) => d.id === id) || props.selectedDomain || props.domains[0] || null;
});

watch(
    () => [props.show, props.selectedDomain, props.domains],
    ([visible]) => {
        if (visible) {
            const initialDomain = props.selectedDomain || props.domains[0] || null;
            form.mail_domain_id = initialDomain ? initialDomain.id : '';
            form.local_part = '';
            form.password = '';
            form.quota_mb = initialDomain?.default_quota_mb ?? 1024;
            form.allow_imap = true;
            form.allow_pop3 = false;
            form.allow_smtp = true;
            showPassword.value = false;
            form.clearErrors();
        }
    },
    { immediate: true },
);

watch(
    () => form.mail_domain_id,
    (newId) => {
        const match = props.domains.find((d) => d.id === Number(newId));
        if (match && typeof match.default_quota_mb === 'number') {
            form.quota_mb = match.default_quota_mb;
        }
    },
);

const generatePassword = () => {
    const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const lower = 'abcdefghijkmnopqrstuvwxyz';
    const digits = '23456789';
    const symbols = '!@#$%^&*_-+=';
    const all = upper + lower + digits + symbols;
    const length = 18;
    const bytes = new Uint32Array(length);
    if (typeof window !== 'undefined' && window.crypto?.getRandomValues) {
        window.crypto.getRandomValues(bytes);
    } else {
        for (let i = 0; i < length; i++) {
            bytes[i] = Math.floor(Math.random() * 0xffffffff);
        }
    }

    const chars = [
        upper[bytes[0] % upper.length],
        lower[bytes[1] % lower.length],
        digits[bytes[2] % digits.length],
        symbols[bytes[3] % symbols.length],
    ];
    for (let i = 4; i < length; i++) {
        chars.push(all[bytes[i] % all.length]);
    }
    for (let i = chars.length - 1; i > 0; i--) {
        const j = bytes[i] % (i + 1);
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }

    form.password = chars.join('');
    showPassword.value = true;
};

const localPartError = computed(() => form.errors.local_part || form.errors.email || '');

const quotaHint = computed(() => {
    const ceiling = props.packageLimits?.max_quota_mb ?? 0;
    if (ceiling > 0) {
        return `Package disk quota ceiling: ${ceiling} MB total across all mailboxes.`;
    }
    return 'Set to 0 for unlimited mailbox storage.';
});

const submit = () => {
    form.post('/email/accounts', {
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
        title="Create Mailbox"
        max-width="md"
        :closeable="!form.processing"
        @close="handleClose"
    >
        <form id="create-mail-account-form" class="space-y-4" @submit.prevent="submit">
            <UiAlert v-if="form.errors.mail_domain_id" variant="danger">
                {{ form.errors.mail_domain_id }}
            </UiAlert>

            <div class="space-y-1.5">
                <label
                    for="create-mailbox-local-part"
                    class="block text-xs font-semibold uppercase tracking-wider text-theme-text-muted"
                >
                    Email Address <span class="text-theme-danger-text">*</span>
                </label>

                <div class="flex items-stretch rounded-theme-md border border-theme-border bg-theme-bg-input overflow-hidden focus-within:ring-2 focus-within:ring-theme-accent/25 focus-within:border-theme-accent">
                    <input
                        id="create-mailbox-local-part"
                        v-model="form.local_part"
                        type="text"
                        placeholder="info"
                        autocomplete="off"
                        required
                        aria-describedby="create-mailbox-local-part-hint create-mailbox-local-part-error"
                        class="flex-1 min-w-0 bg-transparent px-3 py-2 text-sm text-theme-text-primary placeholder:text-theme-text-muted focus:outline-none"
                    />
                    <span class="flex items-center px-2.5 text-sm font-mono text-theme-text-muted bg-theme-bg-surface border-x border-theme-border">
                        @
                    </span>
                    <select
                        id="create-mailbox-domain"
                        v-model="form.mail_domain_id"
                        aria-label="Mail domain"
                        required
                        class="bg-theme-bg-surface px-2.5 py-2 text-sm font-mono text-theme-text-primary focus:outline-none"
                    >
                        <option v-for="d in domains" :key="d.id" :value="d.id">
                            {{ d.domain }}
                        </option>
                    </select>
                </div>

                <p id="create-mailbox-local-part-hint" class="text-[11px] text-theme-text-muted">
                    Allowed characters: <code>a-z</code>, <code>0-9</code>, <code>.</code>, <code>_</code>, <code>+</code>, <code>-</code> (no <code>..</code>, trailing <code>.</code>, or <code>mailer-daemon</code>).
                </p>
                <p
                    v-if="localPartError"
                    id="create-mailbox-local-part-error"
                    class="text-xs text-theme-danger-text"
                >
                    {{ localPartError }}
                </p>
            </div>

            <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                    <label
                        for="create-mailbox-password"
                        class="block text-xs font-semibold uppercase tracking-wider text-theme-text-muted"
                    >
                        Password <span class="text-theme-danger-text">*</span>
                    </label>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 text-xs font-medium text-theme-accent hover:underline"
                        @click="generatePassword"
                    >
                        <KeyRound class="w-3.5 h-3.5" />
                        Generate Strong Password
                    </button>
                </div>

                <div class="relative">
                    <input
                        id="create-mailbox-password"
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        minlength="8"
                        autocomplete="new-password"
                        required
                        aria-describedby="create-mailbox-password-error"
                        class="w-full rounded-theme-md border border-theme-border bg-theme-bg-input pl-3 pr-10 py-2 text-sm text-theme-text-primary focus:outline-none focus:ring-2 focus:ring-theme-accent/25 focus:border-theme-accent"
                    />
                    <button
                        type="button"
                        class="absolute inset-y-0 right-0 flex items-center px-3 text-theme-text-muted hover:text-theme-text-primary"
                        :aria-label="showPassword ? 'Hide password' : 'Show password'"
                        @click="showPassword = !showPassword"
                    >
                        <EyeOff v-if="showPassword" class="w-4 h-4" />
                        <Eye v-else class="w-4 h-4" />
                    </button>
                </div>

                <p
                    v-if="form.errors.password"
                    id="create-mailbox-password-error"
                    class="text-xs text-theme-danger-text"
                >
                    {{ form.errors.password }}
                </p>
            </div>

            <UiInput
                id="create-mailbox-quota"
                v-model.number="form.quota_mb"
                type="number"
                min="0"
                label="Mailbox Quota (MB)"
                :hint="quotaHint"
                :error="form.errors.quota_mb"
                required
            />

            <div class="pt-2 border-t border-theme-border-subtle space-y-2.5">
                <p class="text-xs font-semibold uppercase tracking-wider text-theme-text-muted">
                    Allowed Protocols
                </p>
                <UiToggle
                    v-model="form.allow_imap"
                    label="IMAP"
                    description="Allow mail clients and webmail to sync folders via IMAP."
                />
                <UiToggle
                    v-model="form.allow_smtp"
                    label="SMTP"
                    description="Allow authenticated outbound mail submission via SMTP."
                />
                <UiToggle
                    v-model="form.allow_pop3"
                    label="POP3"
                    description="Allow legacy POP3 download access (disabled by default)."
                />
            </div>
        </form>

        <template #footer>
            <UiButton variant="ghost" :disabled="form.processing" @click="handleClose">
                Cancel
            </UiButton>
            <UiButton
                type="submit"
                form="create-mail-account-form"
                variant="primary"
                :loading="form.processing"
                :disabled="!activeDomain"
            >
                Create Mailbox
            </UiButton>
        </template>
    </UiModal>
</template>
