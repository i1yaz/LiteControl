<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    domains: {
        type: Array,
        default: () => [],
    },
    filterActive: {
        type: Boolean,
        default: false,
    },
    clamavActive: {
        type: Boolean,
        default: false,
    },
});

const forms = reactive(
    Object.fromEntries(
        props.domains.map((d) => [
            d.id,
            {
                spam_filter_enabled: Boolean(d.spam_filter_enabled),
                spam_header_score: Number(d.spam_header_score ?? 6.0),
                spam_reject_score: Number(d.spam_reject_score ?? 15.0),
                processing: false,
            },
        ])
    )
);

const savePolicy = (domain) => {
    const form = forms[domain.id];
    if (!form) return;
    form.processing = true;
    router.put(
        route('email.domains.spam-policy.update', domain.id),
        {
            spam_filter_enabled: form.spam_filter_enabled,
            spam_header_score: form.spam_header_score,
            spam_reject_score: form.spam_reject_score,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                form.processing = false;
            },
        }
    );
};
</script>

<template>
    <Head title="Anti-Spam & ClamAV" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold text-theme-text">Anti-Spam &amp; ClamAV</h1>
                    <p class="mt-1 text-sm text-theme-muted">
                        Configure per-domain Rspamd header and reject thresholds.
                    </p>
                </div>
                <div class="flex items-center gap-2 text-xs">
                    <span
                        class="rounded-full px-3 py-1 font-medium"
                        :class="filterActive ? 'bg-emerald-500/15 text-emerald-500' : 'bg-amber-500/15 text-amber-500'"
                    >
                        Rspamd: {{ filterActive ? 'Full Anti-Spam' : 'DKIM-Light' }}
                    </span>
                    <span
                        class="rounded-full px-3 py-1 font-medium"
                        :class="clamavActive ? 'bg-emerald-500/15 text-emerald-500' : 'bg-theme-surface-2 text-theme-muted'"
                    >
                        ClamAV: {{ clamavActive ? 'Active' : 'Disabled' }}
                    </span>
                </div>
            </div>

            <div v-if="domains.length === 0" class="rounded-xl border border-theme-border bg-theme-surface p-6 text-sm text-theme-muted">
                No mail domains enabled yet. Enable a mail domain under Email Accounts first.
            </div>

            <div v-else class="space-y-4">
                <div
                    v-for="domain in domains"
                    :key="domain.id"
                    class="rounded-xl border border-theme-border bg-theme-surface p-5"
                >
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div class="font-medium text-theme-text">{{ domain.domain }}</div>
                        <label class="inline-flex items-center gap-2 text-sm text-theme-text">
                            <input
                                v-model="forms[domain.id].spam_filter_enabled"
                                type="checkbox"
                                class="rounded border-theme-border"
                            />
                            <span>Enable Spam Filter</span>
                        </label>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label class="block text-xs font-medium text-theme-muted">Add-Header Score (Junk)</label>
                            <input
                                v-model.number="forms[domain.id].spam_header_score"
                                type="number"
                                step="0.5"
                                min="0.1"
                                max="99.9"
                                class="mt-1 w-full rounded-lg border border-theme-border bg-theme-bg px-3 py-2 text-sm text-theme-text"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-theme-muted">Reject Score</label>
                            <input
                                v-model.number="forms[domain.id].spam_reject_score"
                                type="number"
                                step="0.5"
                                min="0.2"
                                max="100"
                                class="mt-1 w-full rounded-lg border border-theme-border bg-theme-bg px-3 py-2 text-sm text-theme-text"
                            />
                        </div>
                        <div class="flex items-end">
                            <button
                                type="button"
                                :disabled="forms[domain.id].processing"
                                class="w-full rounded-lg bg-theme-primary px-4 py-2 text-sm font-medium text-white hover:opacity-90 disabled:opacity-50"
                                @click="savePolicy(domain)"
                            >
                                {{ forms[domain.id].processing ? 'Saving...' : 'Save Policy' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
