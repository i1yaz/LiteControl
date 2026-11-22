<script setup>
import { computed, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';
import DomainSelector from '@domains/Mail/Components/DomainSelector.vue';

const props = defineProps({
    filter_active: {
        type: Boolean,
        default: false,
    },
    clamav_active: {
        type: Boolean,
        default: false,
    },
    mail_core_active: {
        type: Boolean,
        default: true,
    },
    can_manage_modules: {
        type: Boolean,
        default: false,
    },
    domains: {
        type: Array,
        default: () => [],
    },
    selected_domain: {
        type: Object,
        default: null,
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success || '');
const flashError = computed(() => page.props.flash?.error || '');

const selectedDomainName = computed(() => props.selected_domain?.domain || '');

const form = useForm({
    spam_filter_enabled: Boolean(props.selected_domain?.spam_filter_enabled ?? true),
    spam_header_score: Number(props.selected_domain?.spam_header_score ?? 5.0),
    spam_reject_score: Number(props.selected_domain?.spam_reject_score ?? 15.0),
});

watch(
    () => props.selected_domain,
    (domain) => {
        if (!domain) {
            return;
        }
        form.spam_filter_enabled = Boolean(domain.spam_filter_enabled ?? true);
        form.spam_header_score = Number(domain.spam_header_score ?? 5.0);
        form.spam_reject_score = Number(domain.spam_reject_score ?? 15.0);
        form.clearErrors();
    },
    { deep: true }
);

const headerScoreNum = computed(() => Number(form.spam_header_score));
const rejectScoreNum = computed(() => Number(form.spam_reject_score));

const clientScoreError = computed(() => {
    if (Number.isNaN(headerScoreNum.value) || headerScoreNum.value < 0.1 || headerScoreNum.value > 99.9) {
        return 'Header score must be between 0.1 and 99.9.';
    }
    if (Number.isNaN(rejectScoreNum.value) || rejectScoreNum.value < 0.2 || rejectScoreNum.value > 100) {
        return 'Reject score must be between 0.2 and 100.0.';
    }
    if (headerScoreNum.value >= rejectScoreNum.value) {
        return 'Header score must be strictly lower than the reject score.';
    }
    return '';
});

const canSubmit = computed(() => {
    return Boolean(props.selected_domain) && !form.processing && clientScoreError.value === '';
});

const submitPolicy = () => {
    if (!props.selected_domain || clientScoreError.value !== '') {
        return;
    }

    form.put(`/email/domains/${props.selected_domain.id}/spam-policy`, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Anti-Spam Policy" />

    <div class="space-y-6">
        <UiPageHeader
            title="Per-Domain Anti-Spam Policy"
            subtitle="Configure Rspamd spam scoring thresholds for Junk folder delivery and SMTP-time rejection."
        >
            <template #actions>
                <div class="flex items-center gap-2">
                    <UiBadge :variant="filter_active ? 'success' : 'warning'">
                        Rspamd: {{ filter_active ? 'Full Anti-Spam' : 'DKIM-Light' }}
                    </UiBadge>
                    <UiBadge :variant="clamav_active ? 'success' : 'neutral'">
                        ClamAV: {{ clamav_active ? 'Active' : 'Disabled' }}
                    </UiBadge>
                </div>
            </template>
        </UiPageHeader>

        <div aria-live="polite" class="space-y-3">
            <UiAlert v-if="flashSuccess" variant="success">
                {{ flashSuccess }}
            </UiAlert>
            <UiAlert v-if="flashError" variant="danger">
                {{ flashError }}
            </UiAlert>
            <UiAlert v-if="!mail_core_active" variant="warning">
                The Mail Core module is not active. Install and enable it in
                <Link href="/modules" class="underline font-medium">Module Manager</Link>
                before configuring mail filtering.
            </UiAlert>
        </div>

        <!-- Inactive Mail Filter Empty State -->
        <div v-if="!filter_active">
            <UiCard>
                <div class="py-10 text-center space-y-3 max-w-lg mx-auto">
                    <div class="inline-flex items-center justify-center rounded-full px-3 py-1 text-xs font-medium bg-theme-surface-2 text-theme-text-secondary">
                        DKIM-Light + Outbound Ratelimit Mode
                    </div>
                    <h2 class="text-base font-semibold text-theme-text-primary">
                        Full Anti-Spam Filtering Is Not Enabled
                    </h2>
                    <p class="text-sm text-theme-text-muted">
                        This server is currently running Rspamd in <strong>DKIM-Light + outbound ratelimit</strong> mode.
                        Per-domain spam scoring, Junk folder filing, and SMTP-time rejection require the
                        <code>mail_filter</code> module to be enabled.
                    </p>
                    <div v-if="can_manage_modules" class="pt-2">
                        <Link
                            href="/modules"
                            class="inline-flex items-center px-4 py-2 text-xs font-medium rounded-theme-md bg-theme-accent text-white hover:opacity-90 transition-opacity"
                        >
                            Enable mail_filter in Module Manager
                        </Link>
                    </div>
                    <p v-else class="text-xs text-theme-text-muted pt-1">
                        Ask your server administrator to enable the <code>mail_filter</code> module in Module Manager.
                    </p>
                </div>
            </UiCard>
        </div>

        <!-- No Mail Domains Empty State -->
        <div v-else-if="domains.length === 0">
            <UiCard>
                <div class="py-10 text-center space-y-3">
                    <p class="text-sm font-medium text-theme-text-primary">
                        No mail domains enabled yet.
                    </p>
                    <p class="text-xs text-theme-text-muted max-w-md mx-auto">
                        Enable a domain in Mail Domains before configuring per-domain anti-spam policies.
                    </p>
                    <div class="pt-2">
                        <Link
                            href="/email/domains"
                            class="inline-flex items-center px-3.5 py-2 text-xs font-medium rounded-theme-md bg-theme-accent text-white hover:opacity-90 transition-opacity"
                        >
                            Go to Mail Domains
                        </Link>
                    </div>
                </div>
            </UiCard>
        </div>

        <!-- Active Filter Policy Form -->
        <template v-else>
            <div class="w-full sm:max-w-xs">
                <DomainSelector
                    :domains="domains"
                    :model-value="selectedDomainName"
                    route-url="/email/spam"
                    label="Mail Domain"
                />
            </div>

            <UiCard v-if="selected_domain">
                <form class="space-y-6" @submit.prevent="submitPolicy">
                    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-theme-border pb-4">
                        <div>
                            <h2 class="text-base font-semibold text-theme-text-primary">
                                {{ selected_domain.domain }}
                            </h2>
                            <p class="text-xs text-theme-text-muted mt-0.5">
                                Adjust Rspamd thresholds for incoming messages addressed to this domain.
                            </p>
                        </div>

                        <label
                            for="spam_filter_enabled"
                            class="inline-flex items-center gap-2.5 cursor-pointer select-none text-sm font-medium text-theme-text-primary"
                        >
                            <input
                                id="spam_filter_enabled"
                                v-model="form.spam_filter_enabled"
                                type="checkbox"
                                class="h-4 w-4 rounded border-theme-border accent-theme-accent"
                            />
                            <span>Enable Anti-Spam Filtering</span>
                        </label>
                    </div>

                    <UiAlert v-if="clientScoreError" variant="danger">
                        {{ clientScoreError }}
                    </UiAlert>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <!-- Header Score (Junk) -->
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <label
                                    for="spam_header_score"
                                    class="block text-sm font-medium text-theme-text-primary"
                                >
                                    Junk / Add-Header Score
                                </label>
                                <span class="text-xs font-mono text-theme-text-muted">
                                    Default: 5.0
                                </span>
                            </div>
                            <div class="flex items-center gap-3">
                                <input
                                    id="spam_header_score_range"
                                    v-model.number="form.spam_header_score"
                                    type="range"
                                    min="0.5"
                                    max="30"
                                    step="0.5"
                                    aria-label="Junk / Add-Header Score slider"
                                    class="flex-1 accent-theme-accent"
                                />
                                <input
                                    id="spam_header_score"
                                    v-model.number="form.spam_header_score"
                                    type="number"
                                    min="0.1"
                                    max="99.9"
                                    step="0.5"
                                    aria-describedby="spam_header_score_help spam_header_score_error"
                                    class="w-24 rounded-theme-md border border-theme-border bg-theme-surface px-3 py-1.5 text-sm text-theme-text-primary"
                                />
                            </div>
                            <p id="spam_header_score_help" class="text-xs text-theme-text-muted">
                                Messages scoring at or above this threshold receive spam headers and are filed into the <strong>Junk</strong> folder.
                            </p>
                            <p
                                v-if="form.errors.spam_header_score"
                                id="spam_header_score_error"
                                class="text-xs text-red-500"
                            >
                                {{ form.errors.spam_header_score }}
                            </p>
                        </div>

                        <!-- Reject Score (SMTP Reject) -->
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <label
                                    for="spam_reject_score"
                                    class="block text-sm font-medium text-theme-text-primary"
                                >
                                    SMTP Reject Score
                                </label>
                                <span class="text-xs font-mono text-theme-text-muted">
                                    Default: 15.0
                                </span>
                            </div>
                            <div class="flex items-center gap-3">
                                <input
                                    id="spam_reject_score_range"
                                    v-model.number="form.spam_reject_score"
                                    type="range"
                                    min="1"
                                    max="50"
                                    step="0.5"
                                    aria-label="SMTP Reject Score slider"
                                    class="flex-1 accent-theme-accent"
                                />
                                <input
                                    id="spam_reject_score"
                                    v-model.number="form.spam_reject_score"
                                    type="number"
                                    min="0.2"
                                    max="100"
                                    step="0.5"
                                    aria-describedby="spam_reject_score_help spam_reject_score_error"
                                    class="w-24 rounded-theme-md border border-theme-border bg-theme-surface px-3 py-1.5 text-sm text-theme-text-primary"
                                />
                            </div>
                            <p id="spam_reject_score_help" class="text-xs text-theme-text-muted">
                                Messages scoring at or above this threshold are rejected immediately during SMTP delivery.
                            </p>
                            <p
                                v-if="form.errors.spam_reject_score"
                                id="spam_reject_score_error"
                                class="text-xs text-red-500"
                            >
                                {{ form.errors.spam_reject_score }}
                            </p>
                        </div>
                    </div>

                    <!-- Score Band Explanation -->
                    <div class="rounded-theme-md border border-theme-border bg-theme-surface-2/50 p-4 space-y-2">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-theme-text-secondary">
                            Score Band Behavior
                        </h3>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 text-xs">
                            <div class="rounded-theme-md border border-theme-border bg-theme-surface p-3 space-y-1">
                                <div class="font-semibold text-theme-text-primary">
                                    &lt; {{ headerScoreNum.toFixed(1) }} &rarr; Inbox
                                </div>
                                <p class="text-theme-text-muted">
                                    Delivered normally to the recipient's Inbox.
                                </p>
                            </div>
                            <div class="rounded-theme-md border border-theme-border bg-theme-surface p-3 space-y-1">
                                <div class="font-semibold text-theme-text-primary">
                                    {{ headerScoreNum.toFixed(1) }} &ndash; {{ (rejectScoreNum - 0.1).toFixed(1) }} &rarr; Junk
                                </div>
                                <p class="text-theme-text-muted">
                                    Marked with spam headers and filed into the Junk folder.
                                </p>
                            </div>
                            <div class="rounded-theme-md border border-theme-border bg-theme-surface p-3 space-y-1">
                                <div class="font-semibold text-theme-text-primary">
                                    &ge; {{ rejectScoreNum.toFixed(1) }} &rarr; SMTP Reject
                                </div>
                                <p class="text-theme-text-muted">
                                    Rejected at SMTP time before entering the mailbox.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <UiButton
                            type="submit"
                            variant="primary"
                            :disabled="!canSubmit"
                        >
                            {{ form.processing ? 'Saving...' : 'Save Anti-Spam Policy' }}
                        </UiButton>
                    </div>
                </form>
            </UiCard>
        </template>
    </div>
</template>
