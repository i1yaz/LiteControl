<script setup>
import { ref, onUnmounted } from 'vue';
import { Check, Copy } from '@lucide/vue';
import UiButton from '@shared/Ui/UiButton.vue';

const props = defineProps({
    value: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        default: '',
    },
    copyLabel: {
        type: String,
        default: 'Copy',
    },
    copiedLabel: {
        type: String,
        default: 'Copied',
    },
});

const copied = ref(false);
let resetTimer = null;

const copyToClipboard = async () => {
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(props.value);
        } else {
            const textarea = document.createElement('textarea');
            textarea.value = props.value;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        }

        copied.value = true;
        if (resetTimer) clearTimeout(resetTimer);
        resetTimer = setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        copied.value = false;
    }
};

onUnmounted(() => {
    if (resetTimer) clearTimeout(resetTimer);
});
</script>

<template>
    <div class="space-y-1.5">
        <div v-if="label" class="flex items-center justify-between gap-2">
            <span class="text-xs font-medium text-theme-text-secondary">{{ label }}</span>
        </div>
        <div class="relative rounded-theme-md border border-theme-border bg-theme-bg-surface p-3 pr-24">
            <pre class="m-0 overflow-x-auto whitespace-pre-wrap break-all font-mono text-xs text-theme-text-primary leading-relaxed"><code>{{ value }}</code></pre>
            <div class="absolute top-2 right-2">
                <UiButton
                    variant="secondary"
                    size="xs"
                    type="button"
                    :aria-label="copied ? copiedLabel : copyLabel"
                    @click="copyToClipboard"
                >
                    <Check v-if="copied" class="w-3.5 h-3.5 text-theme-success-text" />
                    <Copy v-else class="w-3.5 h-3.5" />
                    <span>{{ copied ? copiedLabel : copyLabel }}</span>
                </UiButton>
            </div>
            <span class="sr-only" role="status" aria-live="polite">
                {{ copied ? `${label || 'Value'} copied to clipboard.` : '' }}
            </span>
        </div>
    </div>
</template>
