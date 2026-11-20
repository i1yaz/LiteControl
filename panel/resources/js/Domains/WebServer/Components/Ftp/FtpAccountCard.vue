<script setup lang="ts">
import { Copy, Check, Folder, HardDrive, Key, Pencil, Trash2 } from '@lucide/vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import { useClipboard } from '@shared/Composables/usePasswordGenerator';

defineProps({
    account: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['change-password', 'edit', 'delete']);
const { copiedKey, copyText } = useClipboard();
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-4 flex flex-col justify-between gap-4 hover:border-theme-accent/40 transition-colors">
        <div class="space-y-3">
            <!-- Header: Username + Copy + Domain Badge -->
            <div class="flex items-start justify-between gap-2">
                <div class="flex items-center gap-1.5 min-w-0">
                    <span class="w-1.5 h-1.5 rounded-theme-badge bg-theme-success shrink-0"></span>
                    <span class="font-bold text-sm text-theme-text-primary truncate" :title="account.username">
                        {{ account.username }}
                    </span>
                    <button
                        type="button"
                        @click="copyText(account.username, `user_${account.id}`)"
                        class="text-theme-text-muted hover:text-theme-text-primary transition-colors p-0.5 shrink-0"
                        title="Copy Username"
                    >
                        <Check v-if="copiedKey === `user_${account.id}`" class="w-3.5 h-3.5 text-theme-success" />
                        <Copy v-else class="w-3.5 h-3.5" />
                    </button>
                </div>

                <UiBadge v-if="account.domain?.domain" variant="neutral" class="shrink-0">
                    {{ account.domain.domain }}
                </UiBadge>
            </div>

            <!-- Details: Home Directory & Disk Quota -->
            <div class="space-y-2 text-xs">
                <div class="flex items-center gap-2 text-theme-text-secondary">
                    <Folder class="w-3.5 h-3.5 text-theme-text-muted shrink-0" />
                    <code
                        class="font-mono text-[11px] bg-theme-bg-surface px-1.5 py-0.5 rounded border border-theme-border truncate block w-full"
                        :title="account.dir"
                    >
                        {{ account.dir }}
                    </code>
                </div>

                <div class="flex items-center gap-2 text-theme-text-secondary">
                    <HardDrive class="w-3.5 h-3.5 text-theme-text-muted shrink-0" />
                    <span>
                        Quota:
                        <strong class="text-theme-text-primary">
                            {{ account.quota_mb > 0 ? `${account.quota_mb} MB` : 'Unlimited' }}
                        </strong>
                    </span>
                </div>
            </div>
        </div>

        <!-- Actions Footer -->
        <div class="pt-3 border-t border-theme-border flex items-center justify-between gap-2">
            <div class="flex items-center gap-1.5">
                <UiButton
                    type="button"
                    variant="secondary"
                    size="sm"
                    @click="emit('change-password', account)"
                >
                    <Key class="w-3.5 h-3.5 mr-1 inline" />
                    Password
                </UiButton>
                <UiButton
                    type="button"
                    variant="secondary"
                    size="sm"
                    @click="emit('edit', account)"
                >
                    <Pencil class="w-3.5 h-3.5 mr-1 inline" />
                    Edit
                </UiButton>
            </div>

            <button
                type="button"
                @click="emit('delete', account)"
                title="Delete FTP Account"
                class="text-theme-text-muted hover:text-theme-danger transition-colors p-1.5 rounded-theme-md hover:bg-theme-bg-surface"
            >
                <Trash2 class="w-4 h-4" />
            </button>
        </div>
    </div>
</template>
