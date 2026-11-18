<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { KeyRound, ShieldCheck, X } from '@lucide/vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiTextarea from '@shared/Ui/UiTextarea.vue';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close']);

const form = useForm({
    domain_id: props.domain.id,
    certificate: '',
    private_key: '',
});

const submitCustomSsl = () => {
    form.post('/security/ssl/custom', {
        preserveScroll: true,
        onSuccess: () => {
            emit('close');
        },
    });
};
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-5 shadow-xs space-y-5">
        <div class="flex items-center justify-between border-b border-theme-border pb-3">
            <div class="flex items-center gap-2">
                <KeyRound class="w-4 h-4 text-theme-accent" />
                <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary theme-ui-control">
                    Install Custom SSL Certificate
                </h3>
            </div>

            <button @click="emit('close')" class="text-theme-text-muted hover:text-theme-text-primary p-1">
                <X class="w-4 h-4" />
            </button>
        </div>

        <form @submit.prevent="submitCustomSsl" class="space-y-4">
            <div>
                <UiTextarea
                    id="certificate"
                    v-model="form.certificate"
                    :rows="6"
                    label="Certificate (CRT / PEM)"
                    placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"
                    :error="form.errors.certificate"
                    spellcheck="false"
                    mono
                    required
                />
            </div>

            <div>
                <UiTextarea
                    id="private_key"
                    v-model="form.private_key"
                    :rows="6"
                    label="Private Key (KEY)"
                    placeholder="-----BEGIN RSA/EC PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"
                    :error="form.errors.private_key"
                    spellcheck="false"
                    mono
                    required
                />
            </div>

            <div class="flex items-center gap-3 pt-2">
                <UiButton
                    type="submit"
                    variant="primary"
                    :disabled="form.processing"
                    :loading="form.processing"
                >
                    <ShieldCheck v-if="!form.processing" class="w-3.5 h-3.5" />
                    <span>{{ form.processing ? 'Validating & Installing...' : 'Install Certificate' }}</span>
                </UiButton>

                <UiButton
                    type="button"
                    variant="secondary"
                    @click="emit('close')"
                >
                    Cancel
                </UiButton>
            </div>
        </form>
    </div>
</template>

