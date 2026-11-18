<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Sliders, Save, Cpu, Zap, Shield, RefreshCw, Globe } from '@lucide/vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiTabs from '@shared/Ui/UiTabs.vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';

interface SiteTuningRecord {
    max_conns: number;
    init_timeout: number;
    persist_conn: boolean;
    pc_keep_alive_timeout: number;
    mem_soft_limit_mb: number;
    mem_hard_limit_mb: number;
    proc_soft_limit: number;
    proc_hard_limit: number;
    php_memory_limit_mb: number;
    php_max_execution_time: number;
    php_max_input_time: number;
    php_upload_max_filesize_mb: number;
    php_post_max_size_mb: number;
    php_max_input_vars: number;
    php_display_errors: boolean;
    sync_status?: string;
    applied_at?: string | null;
}

interface DomainItem {
    id: number;
    domain: string;
    php_version: string;
    status: string;
    user?: { id: number; username: string };
    tuning?: SiteTuningRecord | null;
}

const props = defineProps<{
    tuning?: Record<string, any>;
    domains?: DomainItem[];
}>();

const activeTab = ref<'general' | 'per-site'>('general');

const tuningTabs = [
    { id: 'general', label: 'General', icon: Sliders },
    { id: 'per-site', label: 'Per-Site Tuning', icon: Globe },
];

const form = useForm({
    maxConnections: props.tuning?.maxConnections ?? 10000,
    maxSSLConnections: props.tuning?.maxSSLConnections ?? 8000,
    connTimeout: props.tuning?.connTimeout ?? 300,
    maxKeepAliveReq: props.tuning?.maxKeepAliveReq ?? 1000,
    keepAliveTimeout: props.tuning?.keepAliveTimeout ?? 5,
    maxReqBodySize: props.tuning?.maxReqBodySize ?? '500M',
    enableGzipCompress: props.tuning?.enableGzipCompress ?? 1,
    gzipCompressLevel: props.tuning?.gzipCompressLevel ?? 6,
    quicEnable: props.tuning?.quicEnable ?? 1,
});

const submit = () => {
    form.post('/configuration/tuning');
};

const selectedDomainId = ref<number | null>(props.domains?.[0]?.id ?? null);
const selectedDomain = computed(() =>
    props.domains?.find((d) => d.id === selectedDomainId.value) ?? null
);

const siteForm = useForm({
    max_conns: 10,
    init_timeout: 60,
    persist_conn: true,
    pc_keep_alive_timeout: 5,
    mem_soft_limit_mb: 512,
    mem_hard_limit_mb: 1024,
    proc_soft_limit: 100,
    proc_hard_limit: 200,
    php_memory_limit_mb: 256,
    php_max_execution_time: 60,
    php_max_input_time: 60,
    php_upload_max_filesize_mb: 64,
    php_post_max_size_mb: 64,
    php_max_input_vars: 3000,
    php_display_errors: false,
});

const syncSiteForm = (t?: Partial<SiteTuningRecord> | null) => {
    siteForm.max_conns = t?.max_conns ?? 10;
    siteForm.init_timeout = t?.init_timeout ?? 60;
    siteForm.persist_conn = t?.persist_conn ?? true;
    siteForm.pc_keep_alive_timeout = t?.pc_keep_alive_timeout ?? 5;
    siteForm.mem_soft_limit_mb = t?.mem_soft_limit_mb ?? 512;
    siteForm.mem_hard_limit_mb = t?.mem_hard_limit_mb ?? 1024;
    siteForm.proc_soft_limit = t?.proc_soft_limit ?? 100;
    siteForm.proc_hard_limit = t?.proc_hard_limit ?? 200;
    siteForm.php_memory_limit_mb = t?.php_memory_limit_mb ?? 256;
    siteForm.php_max_execution_time = t?.php_max_execution_time ?? 60;
    siteForm.php_max_input_time = t?.php_max_input_time ?? 60;
    siteForm.php_upload_max_filesize_mb = t?.php_upload_max_filesize_mb ?? 64;
    siteForm.php_post_max_size_mb = t?.php_post_max_size_mb ?? 64;
    siteForm.php_max_input_vars = t?.php_max_input_vars ?? 3000;
    siteForm.php_display_errors = t?.php_display_errors ?? false;
    siteForm.clearErrors();
};

const loadingSiteTuning = ref(false);

const fetchSiteTuning = async (domain: DomainItem | null) => {
    if (!domain) {
        syncSiteForm(null);
        return;
    }
    loadingSiteTuning.value = true;
    try {
        const res = await fetch(`/configuration/tuning/site/${domain.id}`, {
            headers: { Accept: 'application/json' },
        });
        if (res.ok) {
            const data = await res.json();
            syncSiteForm(data.tuning ?? domain.tuning);
        } else {
            syncSiteForm(domain.tuning);
        }
    } catch {
        syncSiteForm(domain.tuning);
    } finally {
        loadingSiteTuning.value = false;
    }
};

watch(selectedDomain, (dom) => {
    fetchSiteTuning(dom);
}, { immediate: true });

const memoryInvariantError = computed(() => {
    const minHard = Number(siteForm.php_memory_limit_mb) + 64;
    if (Number(siteForm.mem_hard_limit_mb) < minHard) {
        return `OS Memory Hard Limit (${siteForm.mem_hard_limit_mb} MB) must be at least PHP memory_limit + 64 MB (${minHard} MB).`;
    }
    return null;
});

const submitSiteTuning = () => {
    if (!selectedDomain.value || memoryInvariantError.value) return;
    siteForm.post(`/configuration/tuning/site/${selectedDomain.value.id}`, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="__('LiteSpeed Tuning')" />

    <div class="max-w-4xl mx-auto space-y-6">
        <!-- Header -->
        <UiPageHeader
            :title="__('OpenLiteSpeed Tuning')"
            subtitle="High-concurrency connection limits, keep-alive parameters, body size buffers, and per-site LSAPI/PHP tuning."
        >
            <template #icon>
                <Zap class="w-6 h-6 text-theme-warning" />
            </template>
        </UiPageHeader>

        <!-- Tabs -->
        <UiTabs
            v-model="activeTab"
            :tabs="tuningTabs"
        />

        <!-- General Form -->
        <UiCard v-if="activeTab === 'general'">
            <form @submit.prevent="submit" class="space-y-6">
                <!-- Section 1: Connection Capacity -->
                <div>
                    <h2 class="text-sm font-semibold text-theme-text-primary uppercase tracking-wider">{{ __('Connection Capacity') }}</h2>
                    <p class="text-xs text-theme-text-secondary mt-0.5">{{ __('Configure simultaneous concurrent worker connection thresholds.') }}</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <UiInput
                        :model-value="form.maxConnections"
                        @update:model-value="form.maxConnections = Number($event)"
                        :label="__('Max Connections')"
                        :hint="__('Maximum concurrent connections allowed (default: 10000).')"
                        type="number"
                        min="10"
                        max="100000"
                        required
                    />

                    <UiInput
                        :model-value="form.maxSSLConnections"
                        @update:model-value="form.maxSSLConnections = Number($event)"
                        :label="__('Max SSL Connections')"
                        :hint="__('Maximum concurrent TLS/HTTPS connections (default: 8000).')"
                        type="number"
                        min="10"
                        max="100000"
                        required
                    />

                    <UiInput
                        :model-value="form.connTimeout"
                        @update:model-value="form.connTimeout = Number($event)"
                        :label="__('Connection Timeout (seconds)')"
                        :hint="__('Idle socket close timeout.')"
                        type="number"
                        min="5"
                        max="3600"
                        required
                    />

                    <UiInput
                        v-model="form.maxReqBodySize"
                        :label="__('Max Request Body Size')"
                        :hint="__('E.g. 500M or 2047M maximum HTTP POST buffer.')"
                        type="text"
                        required
                    />
                </div>

                <!-- Section 2: Keep-Alive -->
                <div class="pt-4 border-t border-theme-border-subtle">
                    <h2 class="text-sm font-semibold text-theme-text-primary uppercase tracking-wider">{{ __('Keep-Alive Optimization') }}</h2>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <UiInput
                        :model-value="form.maxKeepAliveReq"
                        @update:model-value="form.maxKeepAliveReq = Number($event)"
                        :label="__('Max Keep-Alive Requests')"
                        type="number"
                        min="1"
                        max="50000"
                        required
                    />

                    <UiInput
                        :model-value="form.keepAliveTimeout"
                        @update:model-value="form.keepAliveTimeout = Number($event)"
                        :label="__('Keep-Alive Timeout (seconds)')"
                        type="number"
                        min="1"
                        max="60"
                        required
                    />
                </div>

                <!-- Section 3: Compression & Protocols -->
                <div class="pt-4 border-t border-theme-border-subtle">
                    <h2 class="text-sm font-semibold text-theme-text-primary uppercase tracking-wider">{{ __('Compression & HTTP/3 (QUIC)') }}</h2>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                    <UiSelect
                        :model-value="form.enableGzipCompress"
                        @update:model-value="form.enableGzipCompress = Number($event)"
                        :label="__('GZIP Compression')"
                    >
                        <option :value="1">{{ __('Enabled') }}</option>
                        <option :value="0">{{ __('Disabled') }}</option>
                    </UiSelect>

                    <UiSelect
                        :model-value="form.gzipCompressLevel"
                        @update:model-value="form.gzipCompressLevel = Number($event)"
                        :label="__('GZIP Compression Level')"
                    >
                        <option v-for="lvl in 9" :key="lvl" :value="lvl">Level {{ lvl }}</option>
                    </UiSelect>

                    <UiSelect
                        :model-value="form.quicEnable"
                        @update:model-value="form.quicEnable = Number($event)"
                        :label="__('HTTP/3 & QUIC Support')"
                    >
                        <option :value="1">{{ __('Enabled') }}</option>
                        <option :value="0">{{ __('Disabled') }}</option>
                    </UiSelect>
                </div>

                <!-- Footer Action -->
                <div class="pt-4 border-t border-theme-border-subtle flex items-center justify-between">
                    <span class="text-xs text-theme-text-secondary flex items-center gap-1.5">
                        <RefreshCw class="w-3.5 h-3.5 text-theme-success" />
                        Safe reload with automatic rollback is applied on save.
                    </span>
                    <UiButton
                        type="submit"
                        variant="primary"
                        :disabled="form.processing"
                        :loading="form.processing"
                    >
                        <Save v-if="!form.processing" class="w-4 h-4" />
                        <span>{{ form.processing ? 'Applying...' : 'Apply Tuning' }}</span>
                    </UiButton>
                </div>
            </form>
        </UiCard>

        <!-- Per-Site Tuning Form -->
        <UiCard v-else>
            <div class="space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-theme-border-subtle">
                    <div>
                        <h2 class="text-sm font-semibold text-theme-text-primary uppercase tracking-wider">{{ __('Select Site') }}</h2>
                        <p class="text-xs text-theme-text-secondary mt-0.5">{{ __('Configure isolated LSAPI worker pool limits and PHP runtime directives per site.') }}</p>
                    </div>
                    <select
                        v-model="selectedDomainId"
                        class="bg-theme-bg-input border border-theme-border rounded-theme-md px-3.5 py-2 text-sm text-theme-text-primary focus:outline-none focus:border-theme-accent min-w-[240px]"
                    >
                        <option v-if="!domains || domains.length === 0" :value="null">{{ __('No sites available') }}</option>
                        <option v-for="dom in domains" :key="dom.id" :value="dom.id">
                            {{ dom.domain }} ({{ dom.user?.username ?? 'nobody' }} · PHP {{ dom.php_version }})
                        </option>
                    </select>
                </div>

                <form v-if="selectedDomain" @submit.prevent="submitSiteTuning" class="space-y-6">
                    <!-- Cross-layer memory invariant warning -->
                    <UiAlert v-if="memoryInvariantError" variant="danger">
                        {{ memoryInvariantError }}
                    </UiAlert>

                    <!-- Layer 1: LSAPI Worker Pool Limits -->
                    <div>
                        <h3 class="text-sm font-semibold text-theme-text-primary uppercase tracking-wider flex items-center gap-2">
                            <Cpu class="w-4 h-4 text-theme-warning" />
                            <span>{{ __('Layer 1: LSAPI Worker Pool Limits') }}</span>
                        </h3>
                        <p class="text-xs text-theme-text-secondary mt-0.5">{{ __('Enforced by OpenLiteSpeed & Linux Kernel (RLIMIT) on extprocessor.') }}</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <UiInput
                            :model-value="siteForm.max_conns"
                            @update:model-value="siteForm.max_conns = Number($event)"
                            label="Max Conns (LSAPI_CHILDREN)"
                            type="number"
                            min="1"
                            max="500"
                            required
                        />
                        <UiInput
                            :model-value="siteForm.init_timeout"
                            @update:model-value="siteForm.init_timeout = Number($event)"
                            label="Init Timeout (s)"
                            type="number"
                            min="5"
                            max="3600"
                            required
                        />
                        <UiSelect
                            :model-value="String(siteForm.persist_conn)"
                            @update:model-value="siteForm.persist_conn = $event === 'true' || $event === true"
                            label="Persist Connection"
                        >
                            <option value="true">Enabled (1)</option>
                            <option value="false">Disabled (0)</option>
                        </UiSelect>
                        <UiInput
                            :model-value="siteForm.pc_keep_alive_timeout"
                            @update:model-value="siteForm.pc_keep_alive_timeout = Number($event)"
                            label="Keep-Alive Timeout (s)"
                            type="number"
                            min="0"
                            max="3600"
                            required
                        />
                        <UiInput
                            :model-value="siteForm.mem_soft_limit_mb"
                            @update:model-value="siteForm.mem_soft_limit_mb = Number($event)"
                            label="Memory Soft Limit (MB)"
                            type="number"
                            min="64"
                            max="65536"
                            required
                        />
                        <UiInput
                            :model-value="siteForm.mem_hard_limit_mb"
                            @update:model-value="siteForm.mem_hard_limit_mb = Number($event)"
                            label="Memory Hard Limit (MB)"
                            type="number"
                            min="64"
                            max="65536"
                            required
                        />
                        <UiInput
                            :model-value="siteForm.proc_soft_limit"
                            @update:model-value="siteForm.proc_soft_limit = Number($event)"
                            label="Process Soft Limit"
                            type="number"
                            min="10"
                            max="4096"
                            required
                        />
                        <UiInput
                            :model-value="siteForm.proc_hard_limit"
                            @update:model-value="siteForm.proc_hard_limit = Number($event)"
                            label="Process Hard Limit"
                            type="number"
                            min="10"
                            max="4096"
                            required
                        />
                    </div>

                    <!-- Layer 2: PHP Runtime Directives -->
                    <div class="pt-4 border-t border-theme-border-subtle">
                        <h3 class="text-sm font-semibold text-theme-text-primary uppercase tracking-wider flex items-center gap-2">
                            <Shield class="w-4 h-4 text-theme-success" />
                            <span>{{ __('Layer 2: PHP Runtime Directives (phpIniOverride)') }}</span>
                        </h3>
                        <p class="text-xs text-theme-text-secondary mt-0.5">{{ __('Enforced via php_admin_value / php_admin_flag so tenant scripts cannot override limits.') }}</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <UiInput
                            :model-value="siteForm.php_memory_limit_mb"
                            @update:model-value="siteForm.php_memory_limit_mb = Number($event)"
                            label="memory_limit (MB)"
                            type="number"
                            min="32"
                            max="32768"
                            required
                        />
                        <UiInput
                            :model-value="siteForm.php_max_execution_time"
                            @update:model-value="siteForm.php_max_execution_time = Number($event)"
                            label="max_execution_time (s)"
                            type="number"
                            min="1"
                            max="3600"
                            required
                        />
                        <UiInput
                            :model-value="siteForm.php_max_input_time"
                            @update:model-value="siteForm.php_max_input_time = Number($event)"
                            label="max_input_time (s)"
                            type="number"
                            min="1"
                            max="3600"
                            required
                        />
                        <UiInput
                            :model-value="siteForm.php_upload_max_filesize_mb"
                            @update:model-value="siteForm.php_upload_max_filesize_mb = Number($event)"
                            label="upload_max_filesize (MB)"
                            type="number"
                            min="1"
                            max="10240"
                            required
                        />
                        <UiInput
                            :model-value="siteForm.php_post_max_size_mb"
                            @update:model-value="siteForm.php_post_max_size_mb = Number($event)"
                            label="post_max_size (MB)"
                            type="number"
                            min="1"
                            max="10240"
                            required
                        />
                        <UiInput
                            :model-value="siteForm.php_max_input_vars"
                            @update:model-value="siteForm.php_max_input_vars = Number($event)"
                            label="max_input_vars"
                            type="number"
                            min="100"
                            max="100000"
                            required
                        />
                        <UiSelect
                            :model-value="String(siteForm.php_display_errors)"
                            @update:model-value="siteForm.php_display_errors = $event === 'true' || $event === true"
                            label="display_errors"
                        >
                            <option value="false">Off</option>
                            <option value="true">On</option>
                        </UiSelect>
                    </div>

                    <div class="pt-4 border-t border-theme-border-subtle flex items-center justify-between">
                        <span class="text-xs text-theme-text-secondary">
                            <template v-if="selectedDomain.tuning?.sync_status === 'applied'">
                                Last applied: {{ selectedDomain.tuning.applied_at }}
                            </template>
                            <template v-else>
                                Using default per-site tuning profile.
                            </template>
                        </span>
                        <UiButton
                            type="submit"
                            variant="primary"
                            :disabled="siteForm.processing || !!memoryInvariantError"
                            :loading="siteForm.processing"
                        >
                            <Save v-if="!siteForm.processing" class="w-4 h-4" />
                            <span>{{ siteForm.processing ? 'Applying...' : 'Apply Per-Site Tuning' }}</span>
                        </UiButton>
                    </div>
                </form>
            </div>
        </UiCard>
    </div>
</template>


