<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import UiAlert from '@shared/Ui/UiAlert.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';
import UiToggle from '@shared/Ui/UiToggle.vue';

const props = defineProps({
    domain: {
        type: Object,
        required: true,
    },
    config: {
        type: Object,
        required: true,
    },
    presets: {
        type: Object,
        required: true,
    },
    providers: {
        type: Array,
        default: () => [],
    },
    hasManualCredentials: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['switch-to-manual', 'connect-another']);

const activeProviders = computed(() => props.providers.filter((p) => p.status === 'active'));
const defaultProviderId = computed(() => {
    if (props.config.git_provider_id) {
        return String(props.config.git_provider_id);
    }

    return activeProviders.value[0] ? String(activeProviders.value[0].id) : '';
});

const form = useForm({
    mode: props.config.mode === 'simple' ? 'atomic' : (props.config.mode ?? 'atomic'),
    git_provider_id: defaultProviderId.value,
    remote_repo_id: props.config.remote_repo_id ?? '',
    remote_repo_full_name: props.config.remote_repo_full_name ?? '',
    branch: props.config.branch ?? 'main',
    auto_deploy: props.config.source_mode === 'provider' ? Boolean(props.config.auto_deploy) : true,
    preset: props.config.preset ?? 'laravel',
    fetch_driver: props.config.source_mode === 'provider'
        ? (props.config.fetch_driver ?? 'provider_tarball')
        : 'provider_tarball',
    web_subpath: props.config.web_subpath ?? '',
    php_version: props.config.php_version ?? '83',
    keep_releases: props.config.keep_releases ?? 5,
    shared_dirs: props.config.shared_dirs ?? [],
    shared_files: props.config.shared_files ?? [],
    health_check_enabled: Boolean(props.config.health_check_enabled ?? false),
    health_check_path: props.config.health_check_path ?? '/',
    auto_rollback: Boolean(props.config.auto_rollback ?? true),
    confirm_clear_credentials: false,
});

const repoSearch = ref('');
const repositories = ref([]);
const loadingRepos = ref(false);
const repoError = ref('');

const branches = ref([]);
const loadingBranches = ref(false);
const branchError = ref('');

const showAdvanced = ref(false);

const selectedProvider = computed(() =>
    props.providers.find((p) => String(p.id) === String(form.git_provider_id)) ?? null,
);

const filteredRepositories = computed(() => {
    const term = repoSearch.value.trim().toLowerCase();
    if (!term) {
        return repositories.value;
    }

    return repositories.value.filter((repo) =>
        String(repo.full_name ?? '').toLowerCase().includes(term),
    );
});

const webhookBadge = computed(() => {
    if (!selectedProvider.value) {
        return { label: 'No successful delivery yet', variant: 'warning' };
    }

    return selectedProvider.value.webhook_verified_at
        ? { label: 'Webhooks Verified', variant: 'success' }
        : { label: 'No successful delivery yet', variant: 'warning' };
});

watch(
    () => form.preset,
    (newPreset) => {
        if (newPreset === 'custom') {
            return;
        }
        const presetData = props.presets?.[newPreset];
        if (!presetData) {
            return;
        }
        form.web_subpath = presetData.web_subpath ?? '';
        form.shared_dirs = [...(presetData.shared_dirs ?? [])];
        form.shared_files = [...(presetData.shared_files ?? [])];
    },
);

async function loadRepositories(forceRefresh = false) {
    if (!form.git_provider_id) {
        repositories.value = [];
        return;
    }

    loadingRepos.value = true;
    repoError.value = '';

    try {
        const params = new URLSearchParams();
        if (forceRefresh) {
            params.set('refresh', '1');
        }
        const query = params.toString() ? `?${params.toString()}` : '';
        const response = await fetch(`/settings/source-control/providers/${form.git_provider_id}/repositories${query}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            repoError.value = payload.message || 'Unable to load repositories from GitHub.';
            repositories.value = [];
            return;
        }

        repositories.value = Array.isArray(payload.repositories) ? payload.repositories : [];
    } catch {
        repoError.value = 'Network error while loading repositories.';
    } finally {
        loadingRepos.value = false;
    }
}

async function loadBranches() {
    if (!form.git_provider_id || !form.remote_repo_full_name) {
        branches.value = [];
        return;
    }

    loadingBranches.value = true;
    branchError.value = '';

    try {
        const params = new URLSearchParams({
            repo: form.remote_repo_full_name,
        });
        if (form.remote_repo_id) {
            params.set('repository_id', String(form.remote_repo_id));
        }

        const response = await fetch(`/settings/source-control/providers/${form.git_provider_id}/branches?${params.toString()}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            branchError.value = payload.message || 'Unable to load branches.';
            branches.value = [];
            return;
        }

        branches.value = Array.isArray(payload.branches) ? payload.branches : [];
    } catch {
        branchError.value = 'Network error while loading branches.';
    } finally {
        loadingBranches.value = false;
    }
}

function handleProviderChange(event) {
    const val = event?.target?.value ?? form.git_provider_id;
    if (val === '__connect_new__') {
        form.git_provider_id = defaultProviderId.value;
        emit('connect-another');
        return;
    }

    form.remote_repo_id = '';
    form.remote_repo_full_name = '';
    branches.value = [];
    loadRepositories(false);
}

function handleRepositoryChange(event) {
    const selectedId = String(event?.target?.value ?? '');
    const match = repositories.value.find((r) => String(r.id) === selectedId);
    if (match) {
        form.remote_repo_id = String(match.id);
        form.remote_repo_full_name = String(match.full_name);
        if (match.default_branch) {
            form.branch = String(match.default_branch);
        }
        loadBranches();
    }
}

function submit() {
    form.confirm_clear_credentials = props.config.source_mode !== 'provider' && props.hasManualCredentials;
    form.put(`/domains/${props.domain.id}/deploy/source-control`, {
        preserveScroll: true,
    });
}

onMounted(() => {
    if (form.git_provider_id) {
        loadRepositories(false);
        if (form.remote_repo_full_name) {
            loadBranches();
        }
    }
});
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <UiCard
            title="Connected Repository"
            subtitle="Deploy private or public GitHub repositories directly with short-lived scoped tokens and push webhooks."
        >
            <div class="space-y-5">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <UiSelect
                            v-model="form.git_provider_id"
                            label="GitHub Account"
                            :error="form.errors.git_provider_id"
                            @change="handleProviderChange"
                        >
                            <option
                                v-for="provider in providers"
                                :key="provider.id"
                                :value="String(provider.id)"
                            >
                                {{ provider.label }}{{ provider.external_account_login ? ` (@${provider.external_account_login})` : '' }}
                            </option>
                            <option value="__connect_new__">+ Connect another account...</option>
                        </UiSelect>
                    </div>

                    <div class="flex items-end justify-between gap-2">
                        <div class="flex-1">
                            <UiInput
                                v-model="repoSearch"
                                label="Filter Repositories"
                                placeholder="Search owner/repo..."
                            />
                        </div>
                        <UiButton
                            type="button"
                            variant="secondary"
                            size="sm"
                            :loading="loadingRepos"
                            @click="loadRepositories(true)"
                        >
                            Refresh
                        </UiButton>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="space-y-1.5">
                        <UiSelect
                            :model-value="form.remote_repo_id"
                            label="Repository"
                            :error="form.errors.remote_repo_id || form.errors.remote_repo_full_name"
                            @change="handleRepositoryChange"
                        >
                            <option value="">
                                {{ loadingRepos ? 'Loading repositories...' : 'Select a repository' }}
                            </option>
                            <option
                                v-if="form.remote_repo_id && !repositories.some((r) => String(r.id) === String(form.remote_repo_id))"
                                :value="form.remote_repo_id"
                            >
                                {{ form.remote_repo_full_name || `Repository #${form.remote_repo_id}` }}
                            </option>
                            <option
                                v-for="repo in filteredRepositories"
                                :key="repo.id"
                                :value="String(repo.id)"
                            >
                                {{ repo.full_name }}{{ repo.private ? ' (Private)' : '' }}
                            </option>
                        </UiSelect>

                        <div class="flex items-center justify-between text-xs text-theme-text-secondary">
                            <span v-if="repoError" class="text-theme-danger-text">{{ repoError }}</span>
                            <span v-else-if="form.remote_repo_full_name">
                                Selected: <code>{{ form.remote_repo_full_name }}</code>
                            </span>
                            <a
                                v-if="selectedProvider?.manage_repos_url"
                                :href="selectedProvider.manage_repos_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="font-medium text-theme-accent-text hover:underline"
                            >
                                Manage repo access on GitHub &#8599;
                            </a>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <UiSelect
                            v-if="branches.length > 0"
                            v-model="form.branch"
                            label="Branch"
                            :error="form.errors.branch"
                        >
                            <option
                                v-if="form.branch && !branches.some((b) => b.name === form.branch)"
                                :value="form.branch"
                            >
                                {{ form.branch }}
                            </option>
                            <option
                                v-for="branch in branches"
                                :key="branch.name"
                                :value="branch.name"
                            >
                                {{ branch.name }}
                            </option>
                        </UiSelect>
                        <UiInput
                            v-else
                            v-model="form.branch"
                            label="Branch"
                            placeholder="main"
                            :error="form.errors.branch"
                        />
                        <p v-if="branchError" class="text-xs text-theme-danger-text">
                            {{ branchError }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-theme-md border border-theme-border bg-theme-bg-surface p-3.5">
                    <UiToggle
                        v-model="form.auto_deploy"
                        label="Auto-Deploy on Push"
                        description="Automatically trigger an atomic release when commits are pushed to the selected branch."
                    />
                    <UiBadge :variant="webhookBadge.variant">
                        {{ webhookBadge.label }}
                    </UiBadge>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <UiSelect
                        v-model="form.preset"
                        label="Framework Preset"
                        :error="form.errors.preset"
                    >
                        <option value="laravel">Laravel</option>
                        <option value="wordpress">WordPress</option>
                        <option value="nodejs">Node.js</option>
                        <option value="nextjs">Next.js</option>
                        <option value="static">Static</option>
                        <option value="custom">Custom</option>
                    </UiSelect>

                    <UiInput
                        v-model="form.web_subpath"
                        label="Web Subpath"
                        placeholder="public"
                        :error="form.errors.web_subpath"
                    />

                    <UiInput
                        v-model.number="form.keep_releases"
                        type="number"
                        min="1"
                        max="20"
                        label="Keep Releases"
                        :error="form.errors.keep_releases"
                    />
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <div class="mb-1 text-xs font-medium text-theme-text-secondary">Shared Directories</div>
                        <div class="flex flex-wrap gap-1.5">
                            <UiBadge v-for="dir in form.shared_dirs" :key="dir" variant="neutral">
                                {{ dir }}
                            </UiBadge>
                            <span v-if="!form.shared_dirs?.length" class="text-xs text-theme-text-muted">None</span>
                        </div>
                    </div>
                    <div>
                        <div class="mb-1 text-xs font-medium text-theme-text-secondary">Shared Files</div>
                        <div class="flex flex-wrap gap-1.5">
                            <UiBadge v-for="file in form.shared_files" :key="file" variant="neutral">
                                {{ file }}
                            </UiBadge>
                            <span v-if="!form.shared_files?.length" class="text-xs text-theme-text-muted">None</span>
                        </div>
                    </div>
                </div>
            </div>
        </UiCard>

        <UiCard title="Health Check & Auto-Rollback" subtitle="Verify the new release responds with HTTP 2xx/3xx before finalizing.">
            <div class="space-y-4">
                <UiToggle
                    v-model="form.health_check_enabled"
                    label="Enable HTTP Health Check"
                    description="Probe the domain after activating the new release."
                />

                <UiInput
                    v-if="form.health_check_enabled"
                    v-model="form.health_check_path"
                    label="Health Check Path"
                    placeholder="/"
                    :error="form.errors.health_check_path"
                />

                <UiToggle
                    v-model="form.auto_rollback"
                    label="Auto-rollback on HTTP 5xx"
                    description="Automatically restore the previous release if the health check fails."
                />
            </div>
        </UiCard>

        <!-- Collapsed Advanced Drawer -->
        <UiCard>
            <button
                type="button"
                class="flex w-full items-center justify-between gap-3 text-left"
                :aria-expanded="showAdvanced"
                @click="showAdvanced = !showAdvanced"
            >
                <div>
                    <h3 class="text-sm font-semibold text-theme-text-primary">Advanced</h3>
                    <p class="mt-0.5 text-xs text-theme-text-secondary">
                        Fetch driver options (Git Submodules / Git LFS) or switch to Manual Git Mode for external client repositories.
                    </p>
                </div>
                <span class="text-xs font-medium text-theme-text-muted">
                    {{ showAdvanced ? 'Hide' : 'Show' }}
                </span>
            </button>

            <div v-if="showAdvanced" class="mt-4 space-y-4 border-t border-theme-border-subtle pt-4">
                <div class="space-y-1.5">
                    <UiSelect
                        v-model="form.fetch_driver"
                        label="Fetch Driver"
                        :error="form.errors.fetch_driver"
                    >
                        <option value="provider_tarball">Provider Tarball (Recommended)</option>
                        <option value="shallow_git">Shallow Clone + Purge .git</option>
                    </UiSelect>
                    <p class="text-xs text-theme-text-secondary">
                        <strong>Provider Tarball</strong> downloads a fast compressed archive without Git overhead. Choose <strong>Shallow Clone + Purge .git</strong> if your repository uses <strong>Git Submodules</strong> or <strong>Git LFS</strong>.
                    </p>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-theme-md border border-theme-border bg-theme-bg-surface p-3.5">
                    <div>
                        <p class="text-xs font-semibold text-theme-text-primary">Deploying an external client repository?</p>
                        <p class="text-xs text-theme-text-secondary">
                            Switch to Manual Git Mode to configure custom SSH deploy keys, PATs, or manual webhooks.
                        </p>
                    </div>
                    <UiButton
                        type="button"
                        variant="secondary"
                        size="sm"
                        @click="emit('switch-to-manual')"
                    >
                        Switch to Manual Git Mode
                    </UiButton>
                </div>
            </div>
        </UiCard>

        <UiAlert v-if="form.errors.confirm_clear_credentials" variant="warning">
            {{ form.errors.confirm_clear_credentials }}
        </UiAlert>

        <div class="flex items-center justify-between gap-3">
            <Link href="/settings/source-control" class="text-xs font-medium text-theme-accent-text hover:underline">
                Manage Source Control Providers &rarr;
            </Link>
            <UiButton
                type="submit"
                :disabled="!form.git_provider_id || !form.remote_repo_id"
                :loading="form.processing"
            >
                Save Provider Deployment Configuration
            </UiButton>
        </div>
    </form>
</template>
