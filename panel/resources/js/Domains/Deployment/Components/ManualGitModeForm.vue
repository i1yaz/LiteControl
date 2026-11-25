<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
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
    showManualBanner: {
        type: Boolean,
        default: false,
    },
    hasActiveProviders: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['switch-to-provider', 'connect-github']);

const seedShared = ref(true);
const copiedField = ref(null);
const generatingKey = ref(false);
const showManualKey = ref(false);
const showSetupGuide = ref(false);

const form = useForm({
    mode: props.config.mode ?? 'simple',
    preset: props.config.preset ?? 'laravel',
    repo_provider: props.config.repo_provider ?? 'github',
    repo_url: props.config.repo_url ?? '',
    branch: props.config.branch ?? 'main',
    fetch_driver: props.config.fetch_driver ?? 'shallow_git',
    web_subpath: props.config.web_subpath ?? '',
    php_version: props.config.php_version ?? '83',
    keep_releases: props.config.keep_releases ?? 5,
    shared_dirs: props.config.shared_dirs ?? [],
    shared_files: props.config.shared_files ?? [],
    health_check_enabled: Boolean(props.config.health_check_enabled ?? false),
    health_check_path: props.config.health_check_path ?? '/',
    auto_rollback: Boolean(props.config.auto_rollback ?? true),
    api_token: '',
    webhook_secret: '',
    deploy_key_public: '',
    deploy_key_private: '',
    seed_shared: true,
});

const isEnablingAtomic = computed(() => props.config.mode === 'simple' && form.mode === 'atomic');
const isShallowGit = computed(() => form.fetch_driver === 'shallow_git');
const isProviderTarball = computed(() => form.fetch_driver === 'provider_tarball');

const webhookUrl = computed(() => {
    if (!props.config.webhook_token) {
        return '';
    }
    const origin = typeof window !== 'undefined' ? window.location.origin : '';
    return `${origin}/api/webhooks/deploy/${props.config.webhook_token}`;
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

watch(seedShared, (val) => {
    form.seed_shared = val;
});

function copyToClipboard(text, field) {
    if (!text || typeof navigator === 'undefined' || !navigator.clipboard) {
        return;
    }
    navigator.clipboard.writeText(text).then(() => {
        copiedField.value = field;
        setTimeout(() => {
            if (copiedField.value === field) {
                copiedField.value = null;
            }
        }, 2000);
    });
}

function generateWebhookSecret() {
    const bytes = new Uint8Array(24);
    crypto.getRandomValues(bytes);
    form.webhook_secret = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

function generateDeployKey() {
    generatingKey.value = true;
    router.post(
        `/domains/${props.domain.id}/deploy/key`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                generatingKey.value = false;
            },
        },
    );
}

function submit() {
    form.put(`/domains/${props.domain.id}/deploy/config`, {
        preserveScroll: true,
        onSuccess: () => {
            form.api_token = '';
            form.deploy_key_public = '';
            form.deploy_key_private = '';
            showManualKey.value = false;
        },
    });
}
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <UiAlert v-if="showManualBanner" variant="info">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="font-medium">Manual Git Mode</p>
                    <p class="text-xs opacity-90">
                        This domain uses manually configured SSH deploy keys, PATs, or webhooks (ideal for external client repositories).
                    </p>
                </div>
                <UiButton
                    v-if="hasActiveProviders"
                    type="button"
                    size="sm"
                    variant="secondary"
                    @click="emit('switch-to-provider')"
                >
                    Switch to Connected Provider
                </UiButton>
                <UiButton
                    v-else
                    type="button"
                    size="sm"
                    variant="secondary"
                    @click="emit('connect-github')"
                >
                    Connect GitHub Account
                </UiButton>
            </div>
        </UiAlert>

        <!-- Deployment Mode Switcher -->
        <UiCard title="Deployment Mode" subtitle="Choose between manual deployment or atomic zero-downtime releases.">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <button
                    type="button"
                    class="flex flex-col items-start rounded-theme-md border p-4 text-left transition"
                    :class="
                        form.mode === 'simple'
                            ? 'border-theme-accent bg-theme-bg-elevated ring-1 ring-theme-accent'
                            : 'border-theme-border hover:border-theme-text-muted'
                    "
                    @click="form.mode = 'simple'"
                >
                    <div class="flex w-full items-center justify-between">
                        <span class="font-semibold text-theme-text-primary">Manual Deployment</span>
                        <UiBadge v-if="form.mode === 'simple'" variant="info">Selected</UiBadge>
                    </div>
                    <p class="mt-1 text-xs text-theme-text-secondary">
                        Standard <code>public_html</code> directory. Best for static sites or manual FTP/Git pulls.
                    </p>
                </button>

                <button
                    type="button"
                    class="flex flex-col items-start rounded-theme-md border p-4 text-left transition"
                    :class="
                        form.mode === 'atomic'
                            ? 'border-theme-accent bg-theme-bg-elevated ring-1 ring-theme-accent'
                            : 'border-theme-border hover:border-theme-text-muted'
                    "
                    @click="form.mode = 'atomic'"
                >
                    <div class="flex w-full items-center justify-between">
                        <span class="font-semibold text-theme-text-primary">Atomic Zero Downtime</span>
                        <UiBadge v-if="form.mode === 'atomic'" variant="success">Recommended</UiBadge>
                    </div>
                    <p class="mt-1 text-xs text-theme-text-secondary">
                        Builds into <code>releases/</code>, symlinks <code>shared/</code>, and atomically flips <code>current</code>.
                    </p>
                </button>
            </div>

            <div v-if="isEnablingAtomic" class="mt-4">
                <UiAlert variant="info">
                    <label class="flex cursor-pointer items-center gap-2 text-sm">
                        <input
                            v-model="seedShared"
                            type="checkbox"
                            class="h-4 w-4 rounded border-theme-border"
                        />
                        <span>Seed <code>shared/.env</code> and <code>storage/uploads</code> from existing <code>public_html</code></span>
                    </label>
                </UiAlert>
            </div>
        </UiCard>

        <template v-if="form.mode === 'atomic'">
            <!-- Zero-Downtime Setup Guide -->
            <UiCard>
                <button
                    type="button"
                    class="flex w-full items-center justify-between gap-3 text-left"
                    :aria-expanded="showSetupGuide"
                    @click="showSetupGuide = !showSetupGuide"
                >
                    <div>
                        <h3 class="text-sm font-semibold text-theme-text-primary">Zero-Downtime Setup Guide</h3>
                        <p class="mt-0.5 text-xs text-theme-text-secondary">
                            Follow these steps to connect your repository manually and enable atomic deployments.
                        </p>
                    </div>
                    <span class="text-xs font-medium text-theme-text-muted">
                        {{ showSetupGuide ? 'Hide' : 'Show' }}
                    </span>
                </button>

                <div v-if="showSetupGuide" class="mt-4 border-t border-theme-border-subtle pt-4">
                    <ol class="list-decimal space-y-2 pl-5 text-xs text-theme-text-secondary">
                        <li>
                            Choose your <strong>Fetch Driver</strong>: use <strong>Shallow Clone + Purge .git</strong> with an SSH deploy key, or <strong>Provider Tarball</strong> with a Personal Access Token (PAT).
                        </li>
                        <li>
                            Enter your repository URL, branch, and framework preset.
                        </li>
                        <li>
                            (Optional) For push-to-deploy, add the Webhook URL and Webhook Secret to your Git provider.
                        </li>
                        <li>
                            Click <strong class="text-theme-text-primary">Save Deployment Configuration</strong>.
                        </li>
                    </ol>
                </div>
            </UiCard>

            <!-- Repository & Framework Preset -->
            <UiCard title="Repository & Framework Preset" subtitle="Configure your Git source, branch, fetch driver, and framework defaults.">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
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

                    <UiSelect
                        v-model="form.fetch_driver"
                        label="Fetch Driver"
                        :error="form.errors.fetch_driver"
                    >
                        <option value="shallow_git">Shallow Clone + Purge .git</option>
                        <option value="provider_tarball">Provider Tarball</option>
                    </UiSelect>

                    <UiInput
                        v-model="form.repo_url"
                        label="Repository URL"
                        placeholder="git@github.com:org/repo.git"
                        :error="form.errors.repo_url"
                    />

                    <UiInput
                        v-model="form.branch"
                        label="Branch"
                        placeholder="main"
                        :error="form.errors.branch"
                    />

                    <UiInput
                        v-model="form.web_subpath"
                        label="Doc Root"
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

                    <!-- Provider API Token (PAT) ONLY when Fetch Driver = provider_tarball -->
                    <div v-if="isProviderTarball" class="md:col-span-2">
                        <UiInput
                            v-model="form.api_token"
                            type="password"
                            label="Provider API Token (PAT)"
                            placeholder="ghp_... (leave blank to keep existing token)"
                            :error="form.errors.api_token"
                        />
                        <p class="mt-1 text-[11px] text-theme-text-secondary">
                            Required for private repositories when using <strong>Provider Tarball</strong> (GitHub PAT with <code>Contents: Read</code> or <code>repo</code> scope). Stored encrypted.
                        </p>
                    </div>
                </div>

                <!-- Shared Dirs & Files Preview -->
                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
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

                <!-- SSH Public Deploy Key ONLY when Fetch Driver = shallow_git -->
                <div class="mt-6 space-y-4 border-t border-theme-border-subtle pt-4">
                    <div v-if="isShallowGit" class="space-y-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <label class="block text-xs font-medium text-theme-text-secondary">SSH Public Deploy Key</label>
                            <div class="flex items-center gap-2">
                                <UiButton
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    :loading="generatingKey"
                                    @click="generateDeployKey"
                                >
                                    {{ config.deploy_key_public ? 'Generate Key' : 'Generate SSH Deploy Key' }}
                                </UiButton>
                                <UiButton
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="showManualKey = !showManualKey"
                                >
                                    {{ showManualKey ? 'Hide Manual Entry' : 'Paste Custom Key' }}
                                </UiButton>
                            </div>
                        </div>

                        <div v-if="config.deploy_key_public" class="flex items-center gap-2">
                            <input
                                type="text"
                                readonly
                                :value="config.deploy_key_public"
                                class="w-full rounded-theme-md border border-theme-border bg-theme-bg-surface px-3 py-1.5 font-mono text-xs text-theme-text-primary"
                            />
                            <UiButton
                                type="button"
                                variant="secondary"
                                size="sm"
                                @click="copyToClipboard(config.deploy_key_public ?? '', 'deploy_key')"
                            >
                                {{ copiedField === 'deploy_key' ? 'Copied' : 'Copy' }}
                            </UiButton>
                        </div>
                        <p v-else class="text-xs text-theme-text-secondary">
                            No deploy key configured yet. Click <strong>Generate SSH Deploy Key</strong> to create an Ed25519 key pair for this domain.
                        </p>

                        <div v-if="showManualKey" class="mt-3 space-y-3 rounded-theme-md border border-theme-border bg-theme-bg-surface p-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-theme-text-secondary">Custom Public Key (<code>ssh-ed25519 ...</code>)</label>
                                <textarea
                                    v-model="form.deploy_key_public"
                                    rows="2"
                                    placeholder="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI... deploy@example.com"
                                    class="w-full rounded-theme-md border border-theme-border bg-theme-bg-base px-3 py-1.5 font-mono text-xs text-theme-text-primary"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-theme-text-secondary">Custom Private Key (stored encrypted)</label>
                                <textarea
                                    v-model="form.deploy_key_private"
                                    rows="4"
                                    placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                                    class="w-full rounded-theme-md border border-theme-border bg-theme-bg-base px-3 py-1.5 font-mono text-xs text-theme-text-primary"
                                />
                            </div>
                            <p class="text-[11px] text-theme-text-secondary">
                                Click <strong>Save Deployment Configuration</strong> below to store your custom key pair.
                            </p>
                        </div>
                    </div>

                    <div v-if="webhookUrl" class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-theme-text-secondary">Webhook URL</label>
                            <div class="flex items-center gap-2">
                                <input
                                    type="text"
                                    readonly
                                    :value="webhookUrl"
                                    class="w-full rounded-theme-md border border-theme-border bg-theme-bg-surface px-3 py-1.5 font-mono text-xs text-theme-text-primary"
                                />
                                <UiButton
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    @click="copyToClipboard(webhookUrl, 'webhook_url')"
                                >
                                    {{ copiedField === 'webhook_url' ? 'Copied' : 'Copy' }}
                                </UiButton>
                            </div>
                        </div>

                        <div>
                            <div class="mb-1 flex items-center justify-between">
                                <label class="block text-xs font-medium text-theme-text-secondary">Webhook Secret (optional, recommended)</label>
                                <UiButton
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="generateWebhookSecret"
                                >
                                    Generate Secret
                                </UiButton>
                            </div>
                            <div class="flex items-center gap-2">
                                <input
                                    v-model="form.webhook_secret"
                                    type="text"
                                    placeholder="Leave blank to keep existing secret, or click Generate Secret"
                                    class="w-full rounded-theme-md border border-theme-border bg-theme-bg-surface px-3 py-1.5 font-mono text-xs text-theme-text-primary"
                                />
                                <UiButton
                                    v-if="form.webhook_secret"
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    @click="copyToClipboard(form.webhook_secret, 'webhook_secret')"
                                >
                                    {{ copiedField === 'webhook_secret' ? 'Copied' : 'Copy' }}
                                </UiButton>
                            </div>
                            <p class="mt-1 text-[11px] text-theme-text-secondary">
                                Paste the same secret into GitHub's <strong>Secret</strong> field (and click Save below). LiteControl verifies <code>X-Hub-Signature-256</code> and rejects unsigned or forged payloads with HTTP 403.
                            </p>
                        </div>
                    </div>
                </div>
            </UiCard>

            <!-- Health Check & Auto-Rollback -->
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
        </template>

        <div class="flex justify-end">
            <UiButton type="submit" :loading="form.processing">
                Save Deployment Configuration
            </UiButton>
        </div>
    </form>
</template>
