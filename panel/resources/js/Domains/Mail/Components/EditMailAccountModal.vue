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
    account: {
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
    password: '',
    quota_mb: 1024,
    active: true,
    allow_imap: true,
    allow_pop3: false,
    allow_smtp: true,
});

watch(
    () => [props.show, props.account],
    ([visible, target]) => {
        if (visible && target) {
            form.password = '';
            form.quota_mb = target.quota_mb ?? 1024;
            form.active = Boolean(target.active);
            form.allow_imap = Boolean(target.allow_imap);
            form.allow_pop3 = Boolean(target.allow_pop3);
            form.allow_smtp = Boolean(target.allow_smtp);
            showPassword.value = false;
            form.clearErrors();
        }
    },
    { immediate: true },
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

const quotaHint = computed(() => {
    const ceiling = props.packageLimits?.max_quota_mb ?? 0;
    if (ceiling > 0) {
        return `Package disk quota ceiling: ${ceiling} MB total across all mailboxes.`;
    }
    return 'Set to 0 for unlimited mailbox storage.';
});

const submit = () => {
    if (!props.account) {
        return;
    }

    form.put(`/email/accounts/${props.account.id}`, {
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
        :title="account ? `Edit Mailbox — ${account.email}` : 'Edit Mailbox'"
        max-width="md"
        :closeable="!form.processing"
        @close="handleClose"
    >
        <form id="edit-mail-account-form" class="space-y-4" @submit.prevent="submit">
            <UiAlert variant="info">
                Changing the password or disabling the account/protocols immediately terminates active IMAP/SMTP sessions (<code>evict-session</code>).
            </UiAlert>

            <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                    <label
                        for="edit-mailbox-password"
                        class="block text-xs font-semibold uppercase tracking-wider text-theme-text-muted"
                    >
                        New Password (Optional)
                    </label>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 text-xs font-medium text-theme-accent hover:underline"
                        @click="generatePassword"
                    >
                        <KeyRound class="w-3.5 h-3.5" />
                        Generate
                    </button>
                </div>

                <div class="relative">
                    <input
                        id="edit-mailbox-password"
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        placeholder="Leave blank to keep current password"
                        autocomplete="new-password"
                        aria-describedby="edit-mailbox-password-error"
                        class="w-full rounded-theme-md border border-theme-border bg-theme-bg-input pl-3 pr-10 py-2 text-sm text-theme-text-primary placeholder:text-theme-text-muted focus:outline-none focus:ring-2 focus:ring-theme-accent/25 focus:border-theme-accent"
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
                    id="edit-mailbox-password-error"
                    class="text-xs text-theme-danger-text"
                >
                    {{ form.errors.password }}
                </p>
            </div>

            <UiInput
                id="edit-mailbox-quota"
                v-model.number="form.quota_mb"
                type="number"
                min="0"
                label="Mailbox Quota (MB)"
                :hint="quotaHint"
                :error="form.errors.quota_mb"
                required
            />

            <div class="pt-2 border-t border-theme-border-subtle space-y-2.5">
                <UiToggle
                    v-model="form.active"
                    label="Mailbox Active"
                    description="Disabling suspends login and incoming delivery for this mailbox."
                />
                <UiToggle
                    v-model="form.allow_imap"
                    label="IMAP"
                    description="Allow IMAP folder synchronization."
                />
                <UiToggle
                    v-model="form.allow_smtp"
                    label="SMTP"
                    description="Allow authenticated outbound SMTP submission."
                />
                <UiToggle
                    v-model="form.allow_pop3"
                    label="POP3"
                    description="Allow legacy POP3 download access."
                />
            </div>
        </form>

        <template #footer>
            <UiButton variant="ghost" :disabled="form.processing" @click="handleClose">
                Cancel
            </UiButton>
            <UiButton
                type="submit"
                form="edit-mail-account-form"
                variant="primary"
                :loading="form.processing"
            >
                Save Changes
            </UiButton>
        </template>
    </UiModal>
</template>
