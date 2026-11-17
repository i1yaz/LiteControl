<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Sliders, Save, Cpu, Zap, Shield, RefreshCw, Globe, AlertTriangle } from '@lucide/vue';

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

const syncSiteForm = (domain: DomainItem | null) => {
    const t = domain?.tuning;
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

watch(selectedDomain, (dom) => syncSiteForm(dom), { immediate: true });

const memoryInvariantError = computed(() => {
    const minHard = siteForm.php_memory_limit_mb + 64;
    if (siteForm.mem_hard_limit_mb < minHard) {
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
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Zap class="w-6 h-6 text-amber-400" />
                        <span>{{ __('OpenLiteSpeed Tuning') }}</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        High-concurrency connection limits, keep-alive parameters, body size buffers, and per-site LSAPI/PHP tuning.
                    </p>
                </div>
            </div>

            <!-- Tabs -->
            <div class="flex items-center gap-2 border-b border-slate-800 pb-3">
                <button
                    type="button"
                    @click="activeTab = 'general'"
                    :class="[
                        'px-4 py-2 rounded-xl text-sm font-medium transition-colors flex items-center gap-2',
                        activeTab === 'general'
                            ? 'bg-amber-600 text-white shadow-lg shadow-amber-600/20'
                            : 'bg-slate-900 text-slate-400 hover:text-white border border-slate-800',
                    ]"
                >
                    <Sliders class="w-4 h-4" />
                    <span>{{ __('General') }}</span>
                </button>
                <button
                    type="button"
                    @click="activeTab = 'per-site'"
                    :class="[
                        'px-4 py-2 rounded-xl text-sm font-medium transition-colors flex items-center gap-2',
                        activeTab === 'per-site'
                            ? 'bg-amber-600 text-white shadow-lg shadow-amber-600/20'
                            : 'bg-slate-900 text-slate-400 hover:text-white border border-slate-800',
                    ]"
                >
                    <Globe class="w-4 h-4" />
                    <span>{{ __('Per-Site Tuning') }}</span>
                </button>
            </div>

            <!-- General Form -->
            <form v-if="activeTab === 'general'" @submit.prevent="submit" class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6">
                <!-- Section 1: Connection Capacity -->
                <div>
                    <h2 class="text-sm font-semibold text-white uppercase tracking-wider text-slate-300">{{ __('Connection Capacity') }}</h2>
                    <p class="text-xs text-slate-400 mt-0.5">{{ __('Configure simultaneous concurrent worker connection thresholds.') }}</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">{{ __('Max Connections') }}</label>
                        <input
                            v-model.number="form.maxConnections"
                            type="number"
                            min="10"
                            max="100000"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500"
                        />
                        <p class="text-[11px] text-slate-500 mt-1">{{ __('Maximum concurrent connections allowed (default: 10000).') }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">{{ __('Max SSL Connections') }}</label>
                        <input
                            v-model.number="form.maxSSLConnections"
                            type="number"
                            min="10"
                            max="100000"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500"
                        />
                        <p class="text-[11px] text-slate-500 mt-1">{{ __('Maximum concurrent TLS/HTTPS connections (default: 8000).') }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">{{ __('Connection Timeout (seconds)') }}</label>
                        <input
                            v-model.number="form.connTimeout"
                            type="number"
                            min="5"
                            max="3600"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500"
                        />
                        <p class="text-[11px] text-slate-500 mt-1">{{ __('Idle socket close timeout.') }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">{{ __('Max Request Body Size') }}</label>
                        <input
                            v-model="form.maxReqBodySize"
                            type="text"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500"
                        />
                        <p class="text-[11px] text-slate-500 mt-1">{{ __('E.g. 500M or 2047M maximum HTTP POST buffer.') }}</p>
                    </div>
                </div>

                <!-- Section 2: Keep-Alive -->
                <div class="pt-4 border-t border-slate-800">
                    <h2 class="text-sm font-semibold text-white uppercase tracking-wider text-slate-300">{{ __('Keep-Alive Optimization') }}</h2>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">{{ __('Max Keep-Alive Requests') }}</label>
                        <input
                            v-model.number="form.maxKeepAliveReq"
                            type="number"
                            min="1"
                            max="50000"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">{{ __('Keep-Alive Timeout (seconds)') }}</label>
                        <input
                            v-model.number="form.keepAliveTimeout"
                            type="number"
                            min="1"
                            max="60"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500"
                        />
                    </div>
                </div>

                <!-- Section 3: Compression & Protocols -->
                <div class="pt-4 border-t border-slate-800">
                    <h2 class="text-sm font-semibold text-white uppercase tracking-wider text-slate-300">{{ __('Compression & HTTP/3 (QUIC)') }}</h2>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">{{ __('GZIP Compression') }}</label>
                        <select
                            v-model="form.enableGzipCompress"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500"
                        >
                            <option :value="1">{{ __('Enabled') }}</option>
                            <option :value="0">{{ __('Disabled') }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">{{ __('GZIP Compression Level') }}</label>
                        <select
                            v-model="form.gzipCompressLevel"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500"
                        >
                            <option v-for="lvl in 9" :key="lvl" :value="lvl">Level {{ lvl }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">{{ __('HTTP/3 & QUIC Support') }}</label>
                        <select
                            v-model="form.quicEnable"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500"
                        >
                            <option :value="1">{{ __('Enabled') }}</option>
                            <option :value="0">{{ __('Disabled') }}</option>
                        </select>
                    </div>
                </div>

                <!-- Footer Action -->
                <div class="pt-4 border-t border-slate-800 flex items-center justify-between">
                    <span class="text-xs text-slate-400 flex items-center gap-1.5">
                        <RefreshCw class="w-3.5 h-3.5 text-emerald-400" />
                        Safe reload with automatic rollback is applied on save.
                    </span>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-5 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-500 text-white font-medium text-sm flex items-center gap-2 shadow-lg shadow-amber-600/20 disabled:opacity-50 transition-colors"
                    >
                        <Save class="w-4 h-4" />
                        <span>{{ form.processing ? 'Applying...' : 'Apply Tuning' }}</span>
                    </button>
                </div>
            </form>

            <!-- Per-Site Tuning Form -->
            <div v-else class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-slate-800">
                    <div>
                        <h2 class="text-sm font-semibold text-white uppercase tracking-wider">{{ __('Select Site') }}</h2>
                        <p class="text-xs text-slate-400 mt-0.5">{{ __('Configure isolated LSAPI worker pool limits and PHP runtime directives per site.') }}</p>
                    </div>
                    <select
                        v-model="selectedDomainId"
                        class="bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2 text-sm text-white focus:outline-none focus:border-amber-500 min-w-[240px]"
                    >
                        <option v-if="!domains || domains.length === 0" :value="null">{{ __('No sites available') }}</option>
                        <option v-for="dom in domains" :key="dom.id" :value="dom.id">
                            {{ dom.domain }} ({{ dom.user?.username ?? 'nobody' }} · PHP {{ dom.php_version }})
                        </option>
                    </select>
                </div>

                <form v-if="selectedDomain" @submit.prevent="submitSiteTuning" class="space-y-6">
                    <!-- Cross-layer memory invariant warning -->
                    <div v-if="memoryInvariantError" class="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs flex items-center gap-2">
                        <AlertTriangle class="w-4 h-4 shrink-0" />
                        <span>{{ memoryInvariantError }}</span>
                    </div>

                    <!-- Layer 1: LSAPI Worker Pool Limits -->
                    <div>
                        <h3 class="text-sm font-semibold text-slate-200 uppercase tracking-wider flex items-center gap-2">
                            <Cpu class="w-4 h-4 text-amber-400" />
                            <span>{{ __('Layer 1: LSAPI Worker Pool Limits') }}</span>
                        </h3>
                        <p class="text-xs text-slate-400 mt-0.5">{{ __('Enforced by OpenLiteSpeed & Linux Kernel (RLIMIT) on extprocessor.') }}</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Max Conns (LSAPI_CHILDREN)</label>
                            <input v-model.number="siteForm.max_conns" type="number" min="1" max="500" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:border-amber-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Init Timeout (s)</label>
                            <input v-model.number="siteForm.init_timeout" type="number" min="5" max="3600" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:border-amber-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Persist Connection</label>
                            <select v-model="siteForm.persist_conn" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:border-amber-500">
                                <option :value="true">Enabled (1)</option>
                                <option :value="false">Disabled (0)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Keep-Alive Timeout (s)</label>
                            <input v-model.number="siteForm.pc_keep_alive_timeout" type="number" min="0" max="3600" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:border-amber-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Memory Soft Limit (MB)</label>
                            <input v-model.number="siteForm.mem_soft_limit_mb" type="number" min="64" max="65536" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:border-amber-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Memory Hard Limit (MB)</label>
                            <input v-model.number="siteForm.mem_hard_limit_mb" type="number" min="64" max="65536" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:border-amber-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Process Soft Limit</label>
                            <input v-model.number="siteForm.proc_soft_limit" type="number" min="10" max="4096" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:border-amber-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Process Hard Limit</label>
                            <input v-model.number="siteForm.proc_hard_limit" type="number" min="10" max="4096" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:border-amber-500" />
                        </div>
                    </div>

                    <!-- Layer 2: PHP Runtime Directives -->
                    <div class="pt-4 border-t border-slate-800">
                        <h3 class="text-sm font-semibold text-slate-200 uppercase tracking-wider flex items-center gap-2">
                            <Shield class="w-4 h-4 text-emerald-400" />
                            <span>{{ __('Layer 2: PHP Runtime Directives (phpIniOverride)') }}</span>
                        </h3>
                        <p class="text-xs text-slate-400 mt-0.5">{{ __('Enforced via php_admin_value / php_admin_flag so tenant scripts cannot override limits.') }}</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">memory_limit (MB)</label>
                            <input v-model.number="siteForm.php_memory_limit_mb" type="number" min="32" max="32768" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:border-amber-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">max_execution_time (s)</label>
                            <input v-model.number="siteForm.php_max_execution_time" type="number" min="1" max="3600" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:border-amber-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">max_input_time (s)</label>
                            <input v-model.number="siteForm.php_max_input_time" type="number" min="1" max="3600" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:border-amber-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">upload_max_filesize (MB)</label>
                            <input v-model.number="siteForm.php_upload_max_filesize_mb" type="number" min="1" max="10240" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:border-amber-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">post_max_size (MB)</label>
                            <input v-model.number="siteForm.php_post_max_size_mb" type="number" min="1" max="10240" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:border-amber-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">max_input_vars</label>
                            <input v-model.number="siteForm.php_max_input_vars" type="number" min="100" max="100000" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:border-amber-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">display_errors</label>
                            <select v-model="siteForm.php_display_errors" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:border-amber-500">
                                <option :value="false">Off</option>
                                <option :value="true">On</option>
                            </select>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-slate-800 flex items-center justify-between">
                        <span class="text-xs text-slate-400">
                            <template v-if="selectedDomain.tuning?.sync_status === 'applied'">
                                Last applied: {{ selectedDomain.tuning.applied_at }}
                            </template>
                            <template v-else>
                                Using default per-site tuning profile.
                            </template>
                        </span>
                        <button
                            type="submit"
                            :disabled="siteForm.processing || !!memoryInvariantError"
                            class="px-5 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-500 text-white font-medium text-sm flex items-center gap-2 shadow-lg shadow-amber-600/20 disabled:opacity-50 transition-colors"
                        >
                            <Save class="w-4 h-4" />
                            <span>{{ siteForm.processing ? 'Applying...' : 'Apply Per-Site Tuning' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
</template>

