<script setup lang="ts">
import { shallowRef } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { Globe, Plus, Trash2, X } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiButton from '@shared/Ui/UiButton.vue';
import UiInput from '@shared/Ui/UiInput.vue';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['close']);

const form = useForm({
    alias: '',
});

const submitAlias = () => {
    form.post(`/domains/${props.domain.id}/aliases`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        },
    });
};

const deleteAlias = async (alias) => {
    if (await confirmDialog({
        title: 'Remove Domain Alias',
        message: `Remove domain alias '${alias.domain}'?`,
        confirmText: 'Remove',
        variant: 'danger',
    })) {
        router.delete(`/domains/${props.domain.id}/aliases/${alias.id}`, {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <div class="bg-theme-bg-card border border-theme-border rounded-theme-lg p-5 shadow-xs space-y-5">
        <div class="flex items-center justify-between border-b border-theme-border pb-3">
            <div class="flex items-center gap-2">
                <Globe class="w-4 h-4 text-theme-accent" />
                <h3 class="text-xs font-bold uppercase tracking-wider text-theme-text-primary theme-ui-control">
                    Domain Aliases for {{ domain.domain }}
                </h3>
            </div>
            <button @click="emit('close')" class="text-theme-text-muted hover:text-theme-text-primary p-1">
                <X class="w-4 h-4" />
            </button>
        </div>

        <!-- Add Alias Form -->
        <form @submit.prevent="submitAlias" class="flex flex-col sm:flex-row items-start gap-3">
            <div class="flex-1 w-full">
                <UiInput
                    v-model="form.alias"
                    type="text"
                    :placeholder="__('alias.example.com')"
                    :error="form.errors.alias"
                    required
                />
            </div>

            <UiButton
                type="submit"
                variant="primary"
                :disabled="form.processing"
                :loading="form.processing"
            >
                <Plus v-if="!form.processing" class="w-3.5 h-3.5" />
                <span>{{ form.processing ? 'Adding...' : 'Add Alias' }}</span>
            </UiButton>
        </form>

        <!-- Existing Aliases List -->
        <div class="space-y-2 pt-2">
            <h4 class="text-[11px] font-bold uppercase text-theme-text-secondary tracking-wider theme-ui-control">
                Configured Aliases ({{ domain.aliases?.length || 0 }})
            </h4>

            <div v-if="domain.aliases && domain.aliases.length > 0" class="divide-y divide-theme-border border border-theme-border rounded-theme-md overflow-hidden">
                <div
                    v-for="al in domain.aliases"
                    :key="al.id"
                    class="p-3 bg-theme-bg-surface/40 flex items-center justify-between text-xs hover:bg-theme-bg-surface/80 transition-colors"
                >
                    <div class="flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-theme-accent"></span>
                        <span class="font-mono text-theme-text-primary font-medium">{{ al.domain }}</span>
                    </div>

                    <button
                        @click="deleteAlias(al)"
                        class="text-theme-text-muted hover:text-theme-danger-text p-1 transition-colors"
                        :title="__('Remove Alias')"
                    >
                        <Trash2 class="w-4 h-4" />
                    </button>
                </div>
            </div>
            <div v-else class="text-xs text-theme-text-muted py-3 italic">
                No domain aliases configured.
            </div>
        </div>
    </div>
</template>

