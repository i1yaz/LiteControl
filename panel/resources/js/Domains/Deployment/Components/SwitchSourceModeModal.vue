<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import UiAlert from '@shared/Ui/UiAlert.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiModal from '@shared/Ui/UiModal.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    domainId: {
        type: Number,
        required: true,
    },
    targetMode: {
        type: String,
        required: true, // 'provider' | 'manual'
    },
    hasManualCredentials: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close']);

const form = useForm({
    target_mode: props.targetMode,
    confirm_clear_credentials: false,
});

watch(
    () => [props.show, props.targetMode],
    () => {
        form.target_mode = props.targetMode;
        form.confirm_clear_credentials = props.targetMode === 'provider';
        form.clearErrors();
    },
    { immediate: true },
);

const isSwitchingToProvider = computed(() => props.targetMode === 'provider');

function submitSwitch() {
    form.post(`/domains/${props.domainId}/deploy/source-mode`, {
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
        :title="isSwitchingToProvider ? 'Switch to Connected Provider Mode' : 'Switch to Manual Git Mode'"
        max-width="md"
        @close="emit('close')"
    >
        <div class="space-y-4">
            <template v-if="isSwitchingToProvider">
                <UiAlert variant="warning">
                    <div class="space-y-1.5">
                        <p class="font-medium">
                            Stored manual PAT and SSH deploy keys will be cleared
                        </p>
                        <p class="text-xs opacity-90">
                            Switching from Manual Git Mode to a Connected Provider removes any stored manual Personal Access Token, SSH deploy key pair, and manual webhook secret from LiteControl.
                        </p>
                        <p class="text-xs opacity-90">
                            Remember to also remove the old deploy key from your repository's <strong>Settings &rarr; Deploy keys</strong> on GitHub.
                        </p>
                    </div>
                </UiAlert>
            </template>

            <template v-else>
                <UiAlert variant="info">
                    <p class="text-xs">
                        Switching to Manual Git Mode detaches this domain from the connected GitHub App and disables provider push-to-deploy webhooks. You can then configure a custom SSH deploy key or PAT for external client repositories.
                    </p>
                </UiAlert>
            </template>

            <UiAlert v-if="form.errors.confirm_clear_credentials" variant="danger">
                {{ form.errors.confirm_clear_credentials }}
            </UiAlert>
        </div>

        <template #footer>
            <UiButton variant="secondary" @click="emit('close')">
                Cancel
            </UiButton>
            <UiButton
                :variant="isSwitchingToProvider ? 'warning' : 'primary'"
                :loading="form.processing"
                @click="submitSwitch"
            >
                {{ isSwitchingToProvider ? 'Clear Manual Credentials & Switch' : 'Switch to Manual Git Mode' }}
            </UiButton>
        </template>
    </UiModal>
</template>
