<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';

const moduleName = ref<string | null>(null);
const status = ref<'installing' | 'installed' | 'failed' | null>(null);
const errorMessage = ref<string | null>(null);
let pollTimer: ReturnType<typeof setTimeout> | null = null;
let isFetching = false;
let isMounted = false;

const stopPolling = () => {
    if (pollTimer) {
        clearTimeout(pollTimer);
        pollTimer = null;
    }
};

const scheduleNextPoll = () => {
    stopPolling();
    if (isMounted && status.value === 'installing') {
        pollTimer = setTimeout(fetchStatus, 3000);
    }
};

const fetchStatus = async () => {
    if (isFetching) return;
    isFetching = true;
    stopPolling();

    try {
        const res = await fetch('/modules/install-status', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!res.ok) return;
        const data = await res.json();
        const prevStatus = status.value;
        moduleName.value = data.module;
        status.value = data.status;
        errorMessage.value = data.error || null;

        if (data.status !== 'installing' && prevStatus === 'installing' && data.status === 'installed') {
            router.reload({ preserveScroll: true });
        }
    } catch {
        // Ignore transient network errors
    } finally {
        isFetching = false;
        scheduleNextPoll();
    }
};

const dismiss = async () => {
    stopPolling();
    status.value = null;
    moduleName.value = null;
    errorMessage.value = null;
    const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';
    try {
        await fetch('/modules/install-status/dismiss', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            credentials: 'same-origin',
        });
    } catch {
        // Ignore
    }
};

let removeInertiaSuccessListener: (() => void) | null = null;

onMounted(() => {
    isMounted = true;
    fetchStatus();
    removeInertiaSuccessListener = router.on('success', (event) => {
        const successMsg = (event.detail.page.props as { flash?: { success?: string } })?.flash?.success;
        if (typeof successMsg === 'string' && successMsg.includes('installation queued')) {
            fetchStatus();
        }
    });
});

onUnmounted(() => {
    isMounted = false;
    stopPolling();
    removeInertiaSuccessListener?.();
});
</script>

<template>
    <div
        v-if="status && moduleName"
        class="w-full px-4 py-2 text-xs font-mono flex items-center justify-between gap-3 border-b"
        :class="{
            'bg-theme-warning-bg border-theme-warning-border text-theme-warning-text': status === 'installing',
            'bg-theme-success-bg border-theme-success-border text-theme-success-text': status === 'installed',
            'bg-theme-danger-bg border-theme-danger-border text-theme-danger-text': status === 'failed',
        }"
    >
        <div class="flex items-center gap-2">
            <svg v-if="status === 'installing'" class="w-3.5 h-3.5 animate-spin shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.219-8.56" /></svg>
            <svg v-else-if="status === 'installed'" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10" /><path d="m9 12 2 2 4-4" /></svg>
            <svg v-else class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10" /><line x1="12" x2="12" y1="8" y2="12" /><line x1="12" x2="12.01" y1="16" y2="16" /></svg>
            <span>
                Module <strong>{{ moduleName }}</strong>:
                <strong class="uppercase">{{ status }}</strong>
                <span v-if="status === 'failed' && errorMessage" class="ml-2 opacity-90 break-all">
                    — {{ errorMessage }}
                </span>
            </span>
        </div>
        <button
            type="button"
            @click="dismiss"
            class="p-1 rounded-theme-sm hover:bg-theme-bg-surface/40 transition-colors cursor-pointer"
            title="Dismiss"
            aria-label="Dismiss"
        >
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>
        </button>
    </div>
</template>
