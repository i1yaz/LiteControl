<script setup lang="ts">
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import UiAlert from '@/Shared/Ui/UiAlert.vue';
import UiBadge from '@/Shared/Ui/UiBadge.vue';
import UiButton from '@/Shared/Ui/UiButton.vue';
import UiInput from '@/Shared/Ui/UiInput.vue';
import UiModal from '@/Shared/Ui/UiModal.vue';
import type { DeploymentConfig } from './DeploymentConfigForm.vue';

const props = defineProps<{
    show: boolean;
    domain: {
        id: number;
        name: string;
    };
    config: DeploymentConfig;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const activeTab = ref<'env' | 'paths'>('env');
const isLoadingEnv = ref(false);
const loadError = ref<string | null>(null);
const envLoadedOnce = ref(false);

const envForm = useForm({
    content: '',
});

const pathsForm = useForm({
    shared_dirs: [...(props.config.shared_dirs ?? [])],
    shared_files: [...(props.config.shared_files ?? [])],
});

const newSharedDir = ref('');
const newSharedFile = ref('');

watch(
    () => props.show,
    (isOpen) => {
        if (isOpen) {
            pathsForm.shared_dirs = [...(props.config.shared_dirs ?? [])];
            pathsForm.shared_files = [...(props.config.shared_files ?? [])];
            if (!envLoadedOnce.value) {
                loadCurrentEnv();
            }
        }
    },
);

async function loadCurrentEnv() {
    isLoadingEnv.value = true;
    loadError.value = null;

    try {
        const response = await fetch(`/domains/${props.domain.id}/deploy/env`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const data = await response.json();

        if (!response.ok) {
            loadError.value = data.error || 'Failed to load shared .env file.';
            return;
        }

        envForm.content = data.content ?? '';
        envLoadedOnce.value = true;
    } catch {
        loadError.value = 'Network error while loading shared .env file.';
    } finally {
        isLoadingEnv.value = false;
    }
}

function saveEnv() {
    envForm.put(`/domains/${props.domain.id}/deploy/env`, {
        preserveScroll: true,
        onSuccess: () => {
            emit('close');
        },
    });
}

function addSharedDir() {
    const trimmed = newSharedDir.value.trim().replace(/^\/+|\/+$/g, '');
    if (trimmed && !pathsForm.shared_dirs.includes(trimmed)) {
        pathsForm.shared_dirs.push(trimmed);
    }
    newSharedDir.value = '';
}

function removeSharedDir(index: number) {
    pathsForm.shared_dirs.splice(index, 1);
}

function addSharedFile() {
    const trimmed = newSharedFile.value.trim().replace(/^\/+/, '');
    if (trimmed && !pathsForm.shared_files.includes(trimmed)) {
        pathsForm.shared_files.push(trimmed);
    }
    newSharedFile.value = '';
}

function removeSharedFile(index: number) {
    pathsForm.shared_files.splice(index, 1);
}

function saveSharedPaths() {
    pathsForm.put(`/domains/${props.domain.id}/deploy/config`, {
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
        :title="`Shared Environment & Paths — ${domain.name}`"
        max-width="4xl"
        @close="emit('close')"
    >
        <div class="space-y-4">
            <!-- Tab Switcher -->
            <div class="flex border-b border-[var(--theme-border)]">
                <button
                    type="button"
                    class="px-4 py-2 text-sm font-medium border-b-2 transition-colors"
                    :class="
                        activeTab === 'env'
                            ? 'border-[var(--theme-accent)] text-[var(--theme-accent)]'
                            : 'border-transparent text-[var(--theme-text-muted)] hover:text-[var(--theme-text)]'
                    "
                    @click="activeTab = 'env'"
                >
                    shared/.env Editor
                </button>
                <button
                    type="button"
                    class="px-4 py-2 text-sm font-medium border-b-2 transition-colors"
                    :class="
                        activeTab === 'paths'
                            ? 'border-[var(--theme-accent)] text-[var(--theme-accent)]'
                            : 'border-transparent text-[var(--theme-text-muted)] hover:text-[var(--theme-text)]'
                    "
                    @click="activeTab = 'paths'"
                >
                    Custom Shared Paths
                    <UiBadge class="ml-1.5" variant="neutral">
                        {{ pathsForm.shared_dirs.length + pathsForm.shared_files.length }}
                    </UiBadge>
                </button>
            </div>

            <!-- Tab 1: shared/.env Editor -->
            <div v-if="activeTab === 'env'" class="space-y-4">
                <UiAlert v-if="loadError" variant="danger">
                    {{ loadError }}
                </UiAlert>
                <UiAlert v-if="(envForm.errors as Record<string, string>).env || envForm.errors.content" variant="danger">
                    {{ (envForm.errors as Record<string, string>).env || envForm.errors.content }}
                </UiAlert>

                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs text-[var(--theme-text-muted)]">
                        Edits <code class="font-mono text-[var(--theme-text)]">shared/.env</code> symlinked into every release.
                    </p>
                    <UiButton
                        type="button"
                        variant="secondary"
                        size="sm"
                        :disabled="isLoadingEnv"
                        @click="loadCurrentEnv"
                    >
                        {{ isLoadingEnv ? 'Loading...' : 'Load Current .env' }}
                    </UiButton>
                </div>

                <div>
                    <textarea
                        v-model="envForm.content"
                        rows="16"
                        spellcheck="false"
                        placeholder="APP_NAME=LiteControl&#10;APP_ENV=production&#10;APP_DEBUG=false"
                        class="w-full rounded-[var(--theme-radius-md)] border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-2 font-mono text-xs leading-relaxed text-[var(--theme-text)] focus:border-[var(--theme-accent)] focus:outline-none focus:ring-1 focus:ring-[var(--theme-accent)]"
                    />
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-[var(--theme-border)]">
                    <UiButton type="button" variant="secondary" @click="emit('close')">
                        Cancel
                    </UiButton>
                    <UiButton
                        type="button"
                        variant="primary"
                        :disabled="envForm.processing || isLoadingEnv"
                        @click="saveEnv"
                    >
                        {{ envForm.processing ? 'Saving...' : 'Save & Reload Workers' }}
                    </UiButton>
                </div>
            </div>

            <!-- Tab 2: Custom Shared Paths -->
            <div v-else class="space-y-6">
                <p class="text-xs text-[var(--theme-text-muted)]">
                    Shared directories and files persist across all zero-downtime releases via symlinks from
                    <code class="font-mono text-[var(--theme-text)]">shared/</code>.
                </p>

                <!-- Shared Directories -->
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-[var(--theme-text)]">
                        Shared Directories
                    </label>
                    <div class="flex gap-2">
                        <UiInput
                            v-model="newSharedDir"
                            placeholder="e.g. storage or public/uploads"
                            class="flex-1"
                            @keydown.enter.prevent="addSharedDir"
                        />
                        <UiButton type="button" variant="secondary" @click="addSharedDir">
                            Add Dir
                        </UiButton>
                    </div>
                    <div class="flex flex-wrap gap-2 pt-1">
                        <span
                            v-for="(dir, idx) in pathsForm.shared_dirs"
                            :key="dir"
                            class="inline-flex items-center gap-1.5 rounded-[var(--theme-radius-md)] border border-[var(--theme-border)] bg-[var(--theme-surface-muted)] px-2.5 py-1 font-mono text-xs text-[var(--theme-text)]"
                        >
                            {{ dir }}
                            <button
                                type="button"
                                class="text-[var(--theme-text-muted)] hover:text-[var(--theme-danger)]"
                                @click="removeSharedDir(idx)"
                            >
                                &times;
                            </button>
                        </span>
                        <span
                            v-if="pathsForm.shared_dirs.length === 0"
                            class="text-xs text-[var(--theme-text-muted)] italic"
                        >
                            No shared directories configured.
                        </span>
                    </div>
                </div>

                <!-- Shared Files -->
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-[var(--theme-text)]">
                        Shared Files
                    </label>
                    <div class="flex gap-2">
                        <UiInput
                            v-model="newSharedFile"
                            placeholder="e.g. .env or auth.json"
                            class="flex-1"
                            @keydown.enter.prevent="addSharedFile"
                        />
                        <UiButton type="button" variant="secondary" @click="addSharedFile">
                            Add File
                        </UiButton>
                    </div>
                    <div class="flex flex-wrap gap-2 pt-1">
                        <span
                            v-for="(file, idx) in pathsForm.shared_files"
                            :key="file"
                            class="inline-flex items-center gap-1.5 rounded-[var(--theme-radius-md)] border border-[var(--theme-border)] bg-[var(--theme-surface-muted)] px-2.5 py-1 font-mono text-xs text-[var(--theme-text)]"
                        >
                            {{ file }}
                            <button
                                type="button"
                                class="text-[var(--theme-text-muted)] hover:text-[var(--theme-danger)]"
                                @click="removeSharedFile(idx)"
                            >
                                &times;
                            </button>
                        </span>
                        <span
                            v-if="pathsForm.shared_files.length === 0"
                            class="text-xs text-[var(--theme-text-muted)] italic"
                        >
                            No shared files configured.
                        </span>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-[var(--theme-border)]">
                    <UiButton type="button" variant="secondary" @click="emit('close')">
                        Cancel
                    </UiButton>
                    <UiButton
                        type="button"
                        variant="primary"
                        :disabled="pathsForm.processing"
                        @click="saveSharedPaths"
                    >
                        {{ pathsForm.processing ? 'Saving...' : 'Save Shared Paths' }}
                    </UiButton>
                </div>
            </div>
        </div>
    </UiModal>
</template>
