<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import UiModal from '@shared/Ui/UiModal.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    selectedDomain: {
        type: Object,
        default: null,
    },
    existingKeys: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['close']);

const defaultSelector = () => {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    return `lc${year}${month}`;
};

const form = useForm({
    mail_domain_id: '',
    selector: defaultSelector(),
    algorithm: 'rsa2048',
});

const selectorPattern = /^[a-z0-9][a-z0-9-]{0,62}$/;

const normalizedSelector = computed(() => form.selector.trim().toLowerCase());

const clientSelectorError = computed(() => {
    if (!normalizedSelector.value) {
        return '';
    }
    if (!selectorPattern.test(normalizedSelector.value)) {
        return 'Selector must start with a lowercase letter or digit and contain only a-z, 0-9, or hyphens (max 63 chars).';
    }
    return '';
});

const activeKeyForAlgorithm = computed(() =>
    props.existingKeys.find(
        (key) => key.status === 'active' && key.algorithm === form.algorithm,
    ) || null,
);

const canSubmit = computed(() => {
    if (form.processing || !props.selectedDomain) {
        return false;
    }
    if (!normalizedSelector.value || clientSelectorError.value) {
        return false;
    }
    return true;
});

watch(
    () => [props.show, props.selectedDomain],
    ([visible]) => {
        if (visible) {
            form.mail_domain_id = props.selectedDomain ? props.selectedDomain.id : '';
            form.selector = defaultSelector();
            form.algorithm = 'rsa2048';
            form.clearErrors();
        }
    },
    { immediate: true },
);

const submit = () => {
    form.selector = normalizedSelector.value;
    form.post('/email/dkim', {
        preserveScroll: true,
        onSuccess: () => {
            emit('close');
        },
    });
};
</script>

<template>
    <UiModal
        :show="show"
        :title="activeKeyForAlgorithm ? 'Rotate DKIM Key' : 'Generate DKIM Key'"
        max-width="lg"
        @close="emit('close')"
    >
        <form class="space-y-4" @submit.prevent="submit">
            <div
                v-if="selectedDomain"
                class="rounded-theme-md border border-theme-border-subtle bg-theme-bg-surface px-3 py-2 text-xs text-theme-text-secondary"
            >
                Domain:
                <span class="font-mono font-semibold text-theme-text-primary">{{ selectedDomain.domain }}</span>
            </div>

            <UiAlert v-if="activeKeyForAlgorithm" variant="warning">
                An active <strong>{{ form.algorithm.toUpperCase() }}</strong> key
                (<span class="font-mono">{{ activeKeyForAlgorithm.selector }}</span>) already exists for this domain.
                Generating a new key will retire the current active key once the new key activates.
            </UiAlert>

            <div class="space-y-1.5">
                <label
                    for="dkim-selector-input"
                    class="block text-xs font-medium text-theme-text-secondary"
                >
                    DKIM Selector
                </label>
                <input
                    id="dkim-selector-input"
                    v-model="form.selector"
                    type="text"
                    required
                    maxlength="63"
                    placeholder="lc202611"
                    :aria-invalid="Boolean(form.errors.selector || clientSelectorError)"
                    aria-describedby="dkim-selector-help dkim-selector-error"
                    class="w-full rounded-theme-md border border-theme-border bg-theme-bg-surface px-3 py-2 font-mono text-sm text-theme-text-primary focus:border-theme-accent focus:outline-none"
                >
                <p id="dkim-selector-help" class="text-[11px] text-theme-text-muted">
                    RFC 6376 label matching <code class="font-mono">^[a-z0-9][a-z0-9-]{0,62}$</code>.
                    DNS record host will be
                    <span class="font-mono text-theme-text-secondary">
                        {{ normalizedSelector || 'selector' }}._domainkey.{{ selectedDomain?.domain || 'example.com' }}
                    </span>.
                </p>
                <p
                    v-if="form.errors.selector || clientSelectorError"
                    id="dkim-selector-error"
                    role="alert"
                    class="text-xs text-theme-danger-text"
                >
                    {{ form.errors.selector || clientSelectorError }}
                </p>
            </div>

            <fieldset class="space-y-2">
                <legend class="block text-xs font-medium text-theme-text-secondary">
                    Signing Algorithm
                </legend>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <label
                        for="dkim-algo-rsa2048"
                        class="flex items-start gap-2.5 rounded-theme-md border p-3 cursor-pointer transition-colors"
                        :class="
                            form.algorithm === 'rsa2048'
                                ? 'border-theme-accent bg-theme-accent/10'
                                : 'border-theme-border-subtle bg-theme-bg-surface hover:border-theme-border'
                        "
                    >
                        <input
                            id="dkim-algo-rsa2048"
                            v-model="form.algorithm"
                            type="radio"
                            name="algorithm"
                            value="rsa2048"
                            class="mt-0.5 accent-theme-accent"
                        >
                        <div class="text-xs">
                            <div class="font-semibold text-theme-text-primary">RSA-2048 (Default)</div>
                            <p class="text-[11px] text-theme-text-muted mt-0.5">
                                Broadest compatibility across all receiving mail servers.
                            </p>
                        </div>
                    </label>

                    <label
                        for="dkim-algo-ed25519"
                        class="flex items-start gap-2.5 rounded-theme-md border p-3 cursor-pointer transition-colors"
                        :class="
                            form.algorithm === 'ed25519'
                                ? 'border-theme-accent bg-theme-accent/10'
                                : 'border-theme-border-subtle bg-theme-bg-surface hover:border-theme-border'
                        "
                    >
                        <input
                            id="dkim-algo-ed25519"
                            v-model="form.algorithm"
                            type="radio"
                            name="algorithm"
                            value="ed25519"
                            class="mt-0.5 accent-theme-accent"
                        >
                        <div class="text-xs">
                            <div class="font-semibold text-theme-text-primary">Ed25519</div>
                            <p class="text-[11px] text-theme-text-muted mt-0.5">
                                Compact key that fits in a single DNS TXT string (RFC 8463).
                            </p>
                        </div>
                    </label>
                </div>
                <p
                    v-if="form.errors.algorithm"
                    role="alert"
                    class="text-xs text-theme-danger-text"
                >
                    {{ form.errors.algorithm }}
                </p>
            </fieldset>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-theme-border-subtle">
                <UiButton
                    type="button"
                    variant="secondary"
                    :disabled="form.processing"
                    @click="emit('close')"
                >
                    Cancel
                </UiButton>
                <UiButton
                    type="submit"
                    variant="primary"
                    :disabled="!canSubmit"
                >
                    {{ form.processing ? 'Generating...' : (activeKeyForAlgorithm ? 'Rotate Key' : 'Generate Key') }}
                </UiButton>
            </div>
        </form>
    </UiModal>
</template>
