<script setup>
import { Link } from '@inertiajs/vue3';
import UiAlert from '@shared/Ui/UiAlert.vue';
import UiButton from '@shared/Ui/UiButton.vue';

defineProps({
    gate: {
        type: Object,
        required: true,
    },
    panelSslUrl: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <UiAlert v-if="!gate.allowed" variant="warning">
        <div class="space-y-2">
            <p class="font-medium">
                Source control integration requires your panel to be served over HTTPS on a domain name. Set your panel URL and issue an SSL certificate first, then return here.
            </p>
            <p v-if="gate.message" class="text-xs opacity-90">
                {{ gate.message }}
            </p>
            <p class="text-xs opacity-85">
                Note: Your panel port must also be reachable by GitHub (not blocked by a firewall or IP allowlist) for automatic push-to-deploy webhooks to arrive.
            </p>
            <div class="pt-1">
                <Link :href="panelSslUrl">
                    <UiButton size="sm" variant="secondary">
                        Configure Panel URL &amp; SSL
                    </UiButton>
                </Link>
            </div>
        </div>
    </UiAlert>
</template>
