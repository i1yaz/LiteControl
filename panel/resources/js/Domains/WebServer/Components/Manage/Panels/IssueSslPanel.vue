<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ShieldCheck, RefreshCw, X } from '@lucide/vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close']);

const form = useForm({
    domain_id: props.domain.id,
});

const handleIssue = () => {
    form.post('/security/ssl/issue', {
        preserveScroll: true,
    });
};
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-5 shadow-xs space-y-5">
        <div class="flex items-center justify-between border-b border-theme-border pb-3">
            <div class="flex items-center gap-2">
                <ShieldCheck class="w-4 h-4 text-theme-success-text" />
                <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary theme-ui-control">
                    Issue / Renew Let's Encrypt SSL
                </h3>
            </div>

            <button @click="emit('close')" class="text-theme-text-muted hover:text-theme-text-primary p-1">
                <X class="w-4 h-4" />
            </button>
        </div>

        <div class="max-w-xl space-y-4">
            <UiAlert variant="info">
                Let's Encrypt automated HTTP-01 challenge will be verified against
                <strong class="text-theme-text-primary">{{ domain.domain }}</strong> and
                <strong class="text-theme-text-primary">www.{{ domain.domain }}</strong>.
                Ensure DNS A-records are pointed to this server IP.
            </UiAlert>

            <div class="flex items-center gap-3 pt-2">
                <UiButton
                    variant="success"
                    @click="handleIssue"
                    :disabled="form.processing"
                >
                    <RefreshCw class="w-3.5 h-3.5" :class="{ 'animate-spin': form.processing }" />
                    <span>{{ form.processing ? 'Requesting Certificate...' : (domain.ssl_enabled ? 'Renew SSL Certificate' : 'Issue SSL Certificate') }}</span>
                </UiButton>

                <UiButton
                    type="button"
                    variant="secondary"
                    @click="emit('close')"
                >
                    Cancel
                </UiButton>
            </div>
        </div>
    </div>
</template>

