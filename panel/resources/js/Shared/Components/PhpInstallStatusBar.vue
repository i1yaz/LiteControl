<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';

const version = ref<string | null>(null);
const status = ref<'installing' | 'installed' | 'failed' | null>(null);
let pollTimer: ReturnType<typeof setInterval> | null = null;

const formatVersion = (ver: string | null) => {
    if (!ver || ver.length < 2) return ver || '';
    return `${ver[0]}.${ver.slice(1)}`;
};

const fetchStatus = async () => {
    try {
        const res = await fetch('/php/versions/install-status', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!res.ok) return;
        const data = await res.json();
        const prevStatus = status.value;
        version.value = data.version;
        status.value = data.status;

        if (data.status === 'installing') {
            startPolling();
        } else {
            stopPolling();
            if (prevStatus === 'installing' && data.status === 'installed') {
                router.reload({ preserveScroll: true });
            }
        }
    } catch {
        // Ignore transient network errors
    }
};

const startPolling = () => {
    if (pollTimer) return;
    pollTimer = setInterval(fetchStatus, 3000);
};

const stopPolling = () => {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
};

const dismiss = async () => {
    stopPolling();
    status.value = null;
    version.value = null;
    const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';
    try {
        await fetch('/php/versions/install-status/dismiss', {
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

onMounted(() => {
    fetchStatus();
});

onUnmounted(() => {
    stopPolling();
});
</script>

<template>
    <div
        v-if="status && version"
        class="w-full px-4 py-2 text-xs font-mono flex items-center justify-between gap-3 border-b"
        :class="{
            'bg-amber-500/15 border-amber-500/30 text-amber-200': status === 'installing',
            'bg-emerald-500/15 border-emerald-500/30 text-emerald-200': status === 'installed',
            'bg-red-500/15 border-red-500/30 text-red-200': status === 'failed',
        }"
    >
        <div class="flex items-center gap-2">
            <svg v-if="status === 'installing'" class="w-3.5 h-3.5 animate-spin shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.219-8.56" /></svg>
            <svg v-else-if="status === 'installed'" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10" /><path d="m9 12 2 2 4-4" /></svg>
            <svg v-else class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10" /><line x1="12" x2="12" y1="8" y2="12" /><line x1="12" x2="12.01" y1="16" y2="16" /></svg>
            <span>
                PHP <strong>{{ formatVersion(version) }}</strong> (<code>lsphp{{ version }}</code>):
                <strong class="uppercase">{{ status }}</strong>
            </span>
        </div>
        <button
            type="button"
            @click="dismiss"
            class="p-1 rounded hover:bg-white/10 transition-colors cursor-pointer"
            title="Dismiss"
            aria-label="Dismiss"
        >
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>
        </button>
    </div>
</template>
