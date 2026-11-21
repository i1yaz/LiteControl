<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import UiModal from '@shared/Ui/UiModal.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';

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

const form = useForm({
    domain: '',
    admin_override: false,
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
        mode.value = props.availableWebDomains.length > 0 || !props.canAdminOverride
            ? 'webserver'
            : 'admin_override';
        form.reset();
        form.clearErrors();
        form.admin_override = mode.value === 'admin_override';
        form.domain = mode.value === 'webserver' && props.availableWebDomains.length === 1
            ? props.availableWebDomains[0]
            : '';
    }
});

watch(mode, (newMode) => {
    form.admin_override = newMode === 'admin_override';
    form.domain = '';
    form.clearErrors('domain');
});

const submit = () => {
    form.post('/email/domains', {
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
        title="Enable Mail Domain"
        max-width="lg"
        :closeable="!form.processing"
        @close="handleClose"
    >
        <form id="enable-mail-domain-form" class="space-y-4" @submit.prevent="submit">
            <div v-if="canAdminOverride" class="flex items-center gap-2 border-b border-theme-border-subtle pb-3">
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
                :disabled="!form.domain"
            >
                Enable Mail Domain
            </UiButton>
        </template>
    </UiModal>
</template>
