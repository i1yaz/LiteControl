<script setup lang="ts">
import { Copy, Check, Server, Hash, ShieldCheck } from '@lucide/vue';
import { useClipboard } from '@shared/Composables/usePasswordGenerator';

defineProps({
    connectionInfo: {
        type: Object,
        required: true,
    },
});

const { copiedKey, copyText } = useClipboard();
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg px-4 py-3 flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-xs">
            <div class="flex items-center gap-2">
                <Server class="w-3.5 h-3.5 text-theme-accent shrink-0" />
                <span class="text-theme-text-muted uppercase tracking-wider font-medium">Host:</span>
                <code class="font-mono text-theme-text-primary bg-theme-bg-surface px-2 py-0.5 rounded border border-theme-border">
                    {{ connectionInfo.host }}
                </code>
                <button
                    type="button"
                    @click="copyText(connectionInfo.host, 'host')"
                    class="inline-flex items-center gap-1 text-[11px] text-theme-text-muted hover:text-theme-text-primary transition-colors"
                    title="Copy Host"
                >
                    <Check v-if="copiedKey === 'host'" class="w-3.5 h-3.5 text-theme-success" />
                    <Copy v-else class="w-3.5 h-3.5" />
                </button>
            </div>

            <div class="flex items-center gap-2">
                <Hash class="w-3.5 h-3.5 text-theme-accent shrink-0" />
                <span class="text-theme-text-muted uppercase tracking-wider font-medium">Port:</span>
                <code class="font-mono text-theme-text-primary bg-theme-bg-surface px-2 py-0.5 rounded border border-theme-border">
                    {{ connectionInfo.port }}
                </code>
                <button
                    type="button"
                    @click="copyText(connectionInfo.port, 'port')"
                    class="inline-flex items-center gap-1 text-[11px] text-theme-text-muted hover:text-theme-text-primary transition-colors"
                    title="Copy Port"
                >
                    <Check v-if="copiedKey === 'port'" class="w-3.5 h-3.5 text-theme-success" />
                    <Copy v-else class="w-3.5 h-3.5" />
                </button>
            </div>

            <div class="flex items-center gap-2">
                <ShieldCheck class="w-3.5 h-3.5 text-theme-success shrink-0" />
                <span class="text-theme-text-muted uppercase tracking-wider font-medium">Protocol:</span>
                <span class="font-medium text-theme-text-primary">FTP / FTPS</span>
            </div>
        </div>
    </div>
</template>
