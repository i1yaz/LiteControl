<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import UiAlert from '@shared/Ui/UiAlert.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiModal from '@shared/Ui/UiModal.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    provider: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['close']);

const form = useForm({
    switch_linked_to_manual: false,
});

watch(
    () => props.show,
    (visible) => {
        if (visible) {
            form.reset();
            form.clearErrors();
        }
    },
);

const hasLinkedDomains = computed(() => (props.provider?.linked_domains_count ?? 0) > 0);
const canSubmit = computed(() => !hasLinkedDomains.value || form.switch_linked_to_manual);

function confirmDisconnect() {
    if (!props.provider || !canSubmit.value) {
        return;
    }

    form.delete(`/settings/source-control/providers/${props.provider.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            emit('close');
        },
    });
}
</script>

<template>
    <UiModal
        :show="show"
        :title="provider ? `Disconnect ${provider.label}` : 'Disconnect Provider'"
        max-width="lg"
        @close="emit('close')"
    >
        <div v-if="provider" class="space-y-4">
            <UiAlert v-if="hasLinkedDomains" variant="warning">
                <div class="space-y-2">
                    <p class="font-medium">
                        This provider is currently linked to {{ provider.linked_domains_count }} domain(s).
                    </p>
                    <div class="flex flex-wrap gap-1.5">
                        <UiBadge
                            v-for="item in provider.linked_domains"
                            :key="item.domain_id"
                            variant="neutral"
                        >
                            {{ item.domain }}
                        </UiBadge>
                    </div>
                    <label class="mt-2 flex cursor-pointer items-start gap-2 text-xs">
                        <input
                            v-model="form.switch_linked_to_manual"
                            type="checkbox"
                            class="mt-0.5 h-4 w-4 rounded border-theme-border"
                        />
                        <span>
                            Switch all linked domains to <strong>Manual Git Mode</strong> (disables provider auto-deploy) and disconnect this provider.
                        </span>
                    </label>
                </div>
            </UiAlert>

            <UiAlert variant="info">
                <div class="space-y-1.5">
                    <p class="font-medium">
                        Remember to remove the GitHub App on GitHub as well
                    </p>
                    <p class="text-xs opacity-90">
                        Disconnecting removes the credentials from LiteControl, but the GitHub App (especially organization-owned Apps) remains installed on GitHub until you delete it from your GitHub App settings.
                    </p>
                    <p v-if="provider.github_app_settings_url" class="text-xs">
                        <a
                            :href="provider.github_app_settings_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="font-medium underline"
                        >
                            Open GitHub App Advanced Settings &#8599;
                        </a>
                    </p>
                </div>
            </UiAlert>

            <UiAlert v-if="form.errors.provider" variant="danger">
                {{ form.errors.provider }}
            </UiAlert>
        </div>

        <template #footer>
            <UiButton variant="secondary" @click="emit('close')">
                Cancel
            </UiButton>
            <UiButton
                variant="danger"
                :disabled="!canSubmit"
                :loading="form.processing"
                @click="confirmDisconnect"
            >
                {{ hasLinkedDomains ? 'Switch to Manual & Disconnect' : 'Disconnect Provider' }}
            </UiButton>
        </template>
    </UiModal>
</template>
