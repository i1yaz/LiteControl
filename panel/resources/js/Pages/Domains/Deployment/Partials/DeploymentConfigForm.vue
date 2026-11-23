<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import UiAlert from '@/Shared/Ui/UiAlert.vue';
import UiBadge from '@/Shared/Ui/UiBadge.vue';
import UiButton from '@/Shared/Ui/UiButton.vue';
import UiCard from '@/Shared/Ui/UiCard.vue';
import UiInput from '@/Shared/Ui/UiInput.vue';
import UiSelect from '@/Shared/Ui/UiSelect.vue';
import UiToggle from '@/Shared/Ui/UiToggle.vue';

export interface DeploymentPreset {
    label?: string;
    web_subpath: string | null;
    shared_dirs: string[];
    shared_files: string[];
}

export interface DeploymentConfig {
    id?: number;
    mode: 'simple' | 'atomic';
    preset: 'laravel' | 'wordpress' | 'nextjs' | 'nodejs' | 'static' | 'custom';
    repo_provider: 'github' | 'gitlab' | 'bitbucket' | 'custom' | null;
    repo_url: string | null;
    branch: string;
    fetch_driver: 'shallow_git' | 'provider_tarball';
    web_subpath: string | null;
    php_version: string | null;
    keep_releases: number;
    shared_dirs: string[] | null;
    shared_files: string[] | null;
    health_check_enabled: boolean;
    health_check_path: string;
    auto_rollback: boolean;
    deploy_key_public?: string | null;
    webhook_token?: string | null;
}

const props = defineProps<{
    domain: {
        id: number;
        name: string;
    };
    config: DeploymentConfig;
    presets: Record<string, DeploymentPreset>;
}>();

const seedShared = ref(true);
const copiedField = ref<'deploy_key' | 'webhook_url' | 'webhook_secret' | null>(null);
const generatingKey = ref(false);
const showManualKey = ref(false);

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
    health_check_enabled: props.config.health_check_enabled ?? true,
    health_check_path: props.config.health_check_path ?? '/',
    auto_rollback: props.config.auto_rollback ?? true,
    api_token: '',
    webhook_secret: '',
    deploy_key_public: '',
    deploy_key_private: '',
    seed_shared: true,
});

const isEnablingAtomic = computed(
    () => props.config.mode === 'simple' && form.mode === 'atomic',
);

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

function copyToClipboard(text: string, field: 'deploy_key' | 'webhook_url' | 'webhook_secret'): void {
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

function generateWebhookSecret(): void {
    const bytes = new Uint8Array(24);
    crypto.getRandomValues(bytes);
    form.webhook_secret = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

function generateDeployKey(): void {
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

function submit(): void {
    form.put(`/domains/${props.domain.id}/deploy/config`, {
        preserveScroll: true,
        onSuccess: () => {
            form.deploy_key_public = '';
            form.deploy_key_private = '';
            showManualKey.value = false;
        },
    });
}
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <!-- Deployment Mode Switcher -->
        <UiCard title="Deployment Mode" subtitle="Choose between manual deployment or atomic zero-downtime releases.">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <button
                    type="button"
                    class="flex flex-col items-start rounded-lg border p-4 text-left transition"
                    :class="
                        form.mode === 'simple'
                            ? 'border-[var(--theme-accent)] bg-[var(--theme-surface-elevated)] ring-1 ring-[var(--theme-accent)]'
                            : 'border-[var(--theme-border)] hover:border-[var(--theme-text-muted)]'
                    "
                    @click="form.mode = 'simple'"
                >
                    <div class="flex w-full items-center justify-between">
                        <span class="font-semibold text-[var(--theme-text)]">Manual Deployment</span>
                        <UiBadge v-if="form.mode === 'simple'" variant="info">Selected</UiBadge>
                    </div>
                    <p class="mt-1 text-xs text-[var(--theme-text-muted)]">
                        Standard <code>public_html</code> directory. Best for static sites or manual FTP/Git pulls.
                    </p>
                </button>

                <button
                    type="button"
                    class="flex flex-col items-start rounded-lg border p-4 text-left transition"
                    :class="
                        form.mode === 'atomic'
                            ? 'border-[var(--theme-accent)] bg-[var(--theme-surface-elevated)] ring-1 ring-[var(--theme-accent)]'
                            : 'border-[var(--theme-border)] hover:border-[var(--theme-text-muted)]'
                    "
                    @click="form.mode = 'atomic'"
                >
                    <div class="flex w-full items-center justify-between">
                        <span class="font-semibold text-[var(--theme-text)]">Atomic Zero Downtime</span>
                        <UiBadge v-if="form.mode === 'atomic'" variant="success">Recommended</UiBadge>
                    </div>
                    <p class="mt-1 text-xs text-[var(--theme-text-muted)]">
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
                            class="h-4 w-4 rounded border-[var(--theme-border)]"
                        />
                        <span>Seed <code>shared/.env</code> and <code>storage/uploads</code> from existing <code>public_html</code></span>
                    </label>
                </UiAlert>
            </div>
        </UiCard>

        <template v-if="form.mode === 'atomic'">
            <!-- Zero-Downtime Setup Guide -->
            <UiCard
                title="Zero-Downtime Setup Guide"
                subtitle="Follow these steps to connect your repository and enable atomic deployments."
            >
                <ol class="list-decimal space-y-2 pl-5 text-xs text-[var(--theme-text-muted)]">
                    <li>
                        <strong class="text-[var(--theme-text)]">Generate SSH Deploy Key:</strong>
                        Click <strong class="text-[var(--theme-text)]">Generate SSH Deploy Key</strong> below (or paste your own key pair). This is a domain-specific deploy key, separate from your SSH login key in
                        <Link href="/users" class="font-medium text-[var(--theme-accent)] underline hover:opacity-80">Users</Link>.
                    </li>
                    <li>
                        <strong class="text-[var(--theme-text)]">Add Key to GitHub / GitLab / Bitbucket:</strong>
                        Open your repository's <em>Settings &rarr; Deploy keys &rarr; Add deploy key</em> and paste the <strong class="text-[var(--theme-text)]">SSH Public Deploy Key</strong>.
                    </li>
                    <li>
                        <strong class="text-[var(--theme-text)]">Configure Repository &amp; Preset:</strong>
                        Enter your repository SSH URL (e.g., <code>git@github.com:org/repo.git</code>), branch, and framework preset. Presets automatically configure <code>web_subpath</code> and persistent <code>shared/</code> files like <code>.env</code>.
                    </li>
                    <li>
                        <strong class="text-[var(--theme-text)]">Optional Auto-Deploy Webhook:</strong>
                        In GitHub <em>Settings &rarr; Webhooks &rarr; Add webhook</em>, set:
                        <strong>Payload URL</strong> = Webhook URL below,
                        <strong>Content type</strong> = <code>application/json</code>,
                        <strong>Secret</strong> = your Webhook Secret below (prevents fake requests via HMAC signature),
                        and <strong>Events</strong> = <em>Just the push event</em>.
                    </li>
                    <li>
                        <strong class="text-[var(--theme-text)]">Save &amp; Deploy:</strong>
                        Click <strong class="text-[var(--theme-text)]">Save Deployment Configuration</strong> to initialize <code>releases/</code>, <code>shared/</code>, and <code>current</code>, then trigger a deployment.
                    </li>
                </ol>

                <div class="mt-4 grid grid-cols-1 gap-3 border-t border-[var(--theme-border)] pt-4 md:grid-cols-2">
                    <div class="rounded-lg border border-[var(--theme-border)] bg-[var(--theme-surface)] p-3">
                        <div class="text-xs font-semibold text-[var(--theme-text)]">Shallow Clone + Purge .git</div>
                        <p class="mt-1 text-xs text-[var(--theme-text-muted)]">
                            Runs <code>git clone --depth=1 --single-branch</code> using your SSH deploy key, records the commit metadata, and deletes <code>.git</code> so no repository metadata is exposed. Best for most Git repositories.
                        </p>
                    </div>
                    <div class="rounded-lg border border-[var(--theme-border)] bg-[var(--theme-surface)] p-3">
                        <div class="text-xs font-semibold text-[var(--theme-text)]">Provider Tarball</div>
                        <p class="mt-1 text-xs text-[var(--theme-text-muted)]">
                            Downloads a compressed <code>.tar.gz</code> archive directly from your Git provider's API (using a bearer token for private repos) and extracts it without running Git or needing an SSH key. Faster for large repos.
                        </p>
                    </div>
                </div>
            </UiCard>

            <!-- Framework Preset & Repository Configuration -->
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

            <!-- Shared Dirs & Files Preview -->
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <div class="mb-1 text-xs font-medium text-[var(--theme-text-muted)]">Shared Directories</div>
                    <div class="flex flex-wrap gap-1.5">
                        <UiBadge v-for="dir in form.shared_dirs" :key="dir" variant="neutral">
                            {{ dir }}
                        </UiBadge>
                        <span v-if="!form.shared_dirs?.length" class="text-xs text-[var(--theme-text-muted)]">None</span>
                    </div>
                </div>
                <div>
                    <div class="mb-1 text-xs font-medium text-[var(--theme-text-muted)]">Shared Files</div>
                    <div class="flex flex-wrap gap-1.5">
                        <UiBadge v-for="file in form.shared_files" :key="file" variant="neutral">
                            {{ file }}
                        </UiBadge>
                        <span v-if="!form.shared_files?.length" class="text-xs text-[var(--theme-text-muted)]">None</span>
                    </div>
                </div>
            </div>

            <!-- Copyable Deploy Key & Webhook URL -->
            <div class="mt-6 space-y-4 border-t border-[var(--theme-border)] pt-4">
                <div class="space-y-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <label class="block text-xs font-medium text-[var(--theme-text-muted)]">SSH Public Deploy Key</label>
                        <div class="flex items-center gap-2">
                            <UiButton
                                type="button"
                                variant="secondary"
                                size="sm"
                                :loading="generatingKey"
                                @click="generateDeployKey"
                            >
                                {{ config.deploy_key_public ? 'Regenerate Key' : 'Generate SSH Deploy Key' }}
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
                            class="w-full rounded border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-1.5 font-mono text-xs text-[var(--theme-text)]"
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
                    <p v-else class="text-xs text-[var(--theme-text-muted)]">
                        No deploy key configured yet. Click <strong>Generate SSH Deploy Key</strong> to create an Ed25519 key pair for this domain.
                    </p>

                    <div v-if="showManualKey" class="mt-3 space-y-3 rounded-lg border border-[var(--theme-border)] bg-[var(--theme-surface)] p-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-[var(--theme-text-muted)]">Custom Public Key (<code>ssh-ed25519 ...</code>)</label>
                            <textarea
                                v-model="form.deploy_key_public"
                                rows="2"
                                placeholder="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI... deploy@example.com"
                                class="w-full rounded border border-[var(--theme-border)] bg-[var(--theme-bg)] px-3 py-1.5 font-mono text-xs text-[var(--theme-text)]"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-[var(--theme-text-muted)]">Custom Private Key (stored encrypted)</label>
                            <textarea
                                v-model="form.deploy_key_private"
                                rows="4"
                                placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                                class="w-full rounded border border-[var(--theme-border)] bg-[var(--theme-bg)] px-3 py-1.5 font-mono text-xs text-[var(--theme-text)]"
                            />
                        </div>
                        <p class="text-[11px] text-[var(--theme-text-muted)]">
                            Click <strong>Save Deployment Configuration</strong> below to store your custom key pair.
                        </p>
                    </div>
                </div>

                <div v-if="webhookUrl" class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-[var(--theme-text-muted)]">Webhook URL</label>
                        <div class="flex items-center gap-2">
                            <input
                                type="text"
                                readonly
                                :value="webhookUrl"
                                class="w-full rounded border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-1.5 font-mono text-xs text-[var(--theme-text)]"
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
                            <label class="block text-xs font-medium text-[var(--theme-text-muted)]">Webhook Secret (optional, recommended)</label>
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
                                class="w-full rounded border border-[var(--theme-border)] bg-[var(--theme-surface)] px-3 py-1.5 font-mono text-xs text-[var(--theme-text)]"
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
                        <p class="mt-1 text-[11px] text-[var(--theme-text-muted)]">
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
