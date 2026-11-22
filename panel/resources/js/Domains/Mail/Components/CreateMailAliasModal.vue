<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import UiModal from '@shared/Ui/UiModal.vue';
import UiInput from '@shared/Ui/UiInput.vue';
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
    fanoutLimit: {
        type: Number,
        default: 5,
    },
    sourceCounts: {
        type: Object,
        default: () => ({}),
    },
});

const emit = defineEmits(['close']);

const aliasType = ref('forwarder');
const localPart = ref('');

const form = useForm({
    mail_domain_id: '',
    source_email: '',
    destination_email: '',
});

const activeDomain = computed(() => {
    const id = Number(form.mail_domain_id);
    return props.domains.find((d) => d.id === id) || props.selectedDomain || props.domains[0] || null;
});

const computedSourceEmail = computed(() => {
    const domainName = activeDomain.value?.domain || '';
    if (!domainName) {
        return '';
    }
    if (aliasType.value === 'catch_all') {
        return `@${domainName}`;
    }
    const cleanLocal = localPart.value.trim().toLowerCase();
    return cleanLocal ? `${cleanLocal}@${domainName}` : '';
});

const currentSourceCount = computed(() => {
    const source = computedSourceEmail.value;
    if (!source) {
        return 0;
    }
    return Number(props.sourceCounts?.[source] ?? 0);
});

const fanoutCapReached = computed(() => currentSourceCount.value >= props.fanoutLimit);

watch(
    () => [props.show, props.selectedDomain, props.domains],
    ([visible]) => {
        if (visible) {
            const initialDomain = props.selectedDomain || props.domains[0] || null;
            aliasType.value = 'forwarder';
            localPart.value = '';
            form.mail_domain_id = initialDomain ? initialDomain.id : '';
            form.source_email = '';
            form.destination_email = '';
            form.clearErrors();
        }
    },
    { immediate: true },
);

const submit = () => {
    form.source_email = computedSourceEmail.value;
    form.post('/email/forwarders', {
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
        title="Create Forwarder / Catch-All"
        max-width="lg"
        @close="emit('close')"
    >
        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <span class="block text-xs font-medium text-theme-text-secondary mb-1.5">
                    Forwarder Type
                </span>
                <div class="grid grid-cols-2 gap-2">
                    <button
                        type="button"
                        class="px-3 py-2 text-xs font-medium rounded-theme-md border text-left transition-colors"
                        :class="
                            aliasType === 'forwarder'
                                ? 'border-theme-accent bg-theme-accent/10 text-theme-text-primary'
                                : 'border-theme-border-subtle bg-theme-bg-surface text-theme-text-secondary hover:text-theme-text-primary'
                        "
                        @click="aliasType = 'forwarder'"
                    >
                        <div class="font-semibold">Address Forwarder</div>
                        <div class="text-[11px] text-theme-text-muted mt-0.5">
                            [local_part]@[domain]
                        </div>
                    </button>
                    <button
                        type="button"
                        class="px-3 py-2 text-xs font-medium rounded-theme-md border text-left transition-colors"
                        :class="
                            aliasType === 'catch_all'
                                ? 'border-theme-accent bg-theme-accent/10 text-theme-text-primary'
                                : 'border-theme-border-subtle bg-theme-bg-surface text-theme-text-secondary hover:text-theme-text-primary'
                        "
                        @click="aliasType = 'catch_all'"
                    >
                        <div class="font-semibold">Domain Catch-All</div>
                        <div class="text-[11px] text-theme-text-muted mt-0.5">
                            @[domain]
                        </div>
                    </button>
                </div>
            </div>

            <div>
                <label
                    for="alias-mail-domain"
                    class="block text-xs font-medium text-theme-text-secondary mb-1"
                >
                    Mail Domain
                </label>
                <select
                    id="alias-mail-domain"
                    v-model="form.mail_domain_id"
                    class="w-full rounded-theme-md border border-theme-border-subtle bg-theme-bg-surface px-3 py-2 text-sm text-theme-text-primary focus:outline-none focus:ring-2 focus:ring-theme-accent"
                    :aria-describedby="form.errors.mail_domain_id ? 'alias-domain-error' : undefined"
                    required
                >
                    <option v-for="d in domains" :key="d.id" :value="d.id">
                        {{ d.domain }}
                    </option>
                </select>
                <p
                    v-if="form.errors.mail_domain_id"
                    id="alias-domain-error"
                    class="mt-1 text-xs text-theme-danger"
                >
                    {{ form.errors.mail_domain_id }}
                </p>
            </div>

            <div v-if="aliasType === 'forwarder'">
                <label
                    for="alias-local-part"
                    class="block text-xs font-medium text-theme-text-secondary mb-1"
                >
                    Source Address
                </label>
                <div class="flex items-stretch">
                    <input
                        id="alias-local-part"
                        v-model="localPart"
                        type="text"
                        placeholder="sales"
                        autocomplete="off"
                        required
                        class="flex-1 min-w-0 rounded-l-theme-md border border-r-0 border-theme-border-subtle bg-theme-bg-surface px-3 py-2 text-sm text-theme-text-primary focus:outline-none focus:ring-2 focus:ring-theme-accent"
                        :aria-describedby="form.errors.source_email ? 'alias-source-error' : 'alias-source-hint'"
                    />
                    <span class="inline-flex items-center px-3 rounded-r-theme-md border border-theme-border-subtle bg-theme-bg-elevated text-xs font-mono text-theme-text-secondary">
                        @{{ activeDomain?.domain || 'domain' }}
                    </span>
                </div>
                <p id="alias-source-hint" class="mt-1 text-[11px] text-theme-text-muted">
                    Must not collide with an existing mailbox address on this domain.
                </p>
                <p
                    v-if="form.errors.source_email"
                    id="alias-source-error"
                    class="mt-1 text-xs text-theme-danger"
                >
                    {{ form.errors.source_email }}
                </p>
            </div>

            <div v-else class="space-y-3">
                <div>
                    <label
                        for="alias-catch-all-preview"
                        class="block text-xs font-medium text-theme-text-secondary mb-1"
                    >
                        Catch-All Source
                    </label>
                    <input
                        id="alias-catch-all-preview"
                        type="text"
                        :value="computedSourceEmail"
                        readonly
                        class="w-full rounded-theme-md border border-theme-border-subtle bg-theme-bg-elevated px-3 py-2 text-sm font-mono text-theme-text-secondary"
                        :aria-describedby="form.errors.source_email ? 'alias-source-error' : undefined"
                    />
                    <p
                        v-if="form.errors.source_email"
                        id="alias-source-error"
                        class="mt-1 text-xs text-theme-danger"
                    >
                        {{ form.errors.source_email }}
                    </p>
                </div>

                <UiAlert variant="warning">
                    Real mailboxes always take precedence and are never hijacked by a catch-all.
                    Catch-all addresses cannot be used as authenticated SMTP sender logins.
                </UiAlert>
            </div>

            <UiAlert v-if="fanoutCapReached && computedSourceEmail" variant="danger">
                Source <span class="font-mono font-semibold">{{ computedSourceEmail }}</span> has
                already reached the maximum fan-out cap ({{ currentSourceCount }} / {{ fanoutLimit }}
                destinations). Delete an existing destination before adding another.
            </UiAlert>
            <p
                v-else-if="currentSourceCount > 0 && computedSourceEmail"
                class="text-xs text-theme-text-secondary"
            >
                Existing destinations for <span class="font-mono">{{ computedSourceEmail }}</span>:
                <span class="font-semibold">{{ currentSourceCount }} / {{ fanoutLimit }}</span>
            </p>

            <div>
                <UiInput
                    id="alias-destination-email"
                    v-model="form.destination_email"
                    label="Destination Email"
                    type="email"
                    placeholder="team@destination.org"
                    :error="form.errors.destination_email"
                    required
                />
            </div>

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
                    :disabled="form.processing || !activeDomain"
                >
                    {{ form.processing ? 'Creating...' : 'Create Forwarder' }}
                </UiButton>
            </div>
        </form>
    </UiModal>
</template>
