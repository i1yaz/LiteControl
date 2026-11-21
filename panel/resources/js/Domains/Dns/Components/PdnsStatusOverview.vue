<script setup lang="ts">
import { computed } from 'vue';
import {
    Activity,
    Server,
    Database,
    ShieldCheck,
    Key,
    RefreshCw,
    CheckCircle2,
    AlertTriangle,
    FileCode,
    Network,
    Eraser,
} from '@lucide/vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';

const props = defineProps({
    settings: {
        type: Object,
        required: true,
    },
    daemon: {
        type: Object,
        required: true,
    },
    apiHealth: {
        type: Object,
        required: true,
    },
    testingConnection: {
        type: Boolean,
        default: false,
    },
    rotatingKey: {
        type: Boolean,
        default: false,
    },
    flushingCache: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['test-connection', 'rotate-key', 'flush-cache']);

const isDaemonActive = computed(() => Boolean(props.daemon?.systemd_active));
const hasPort53Conflict = computed(() => Boolean(props.daemon?.port_53?.conflict));
const isApiConnected = computed(() => Boolean(props.apiHealth?.connected));

const activeBackendLabel = computed(() => {
    const launch = props.apiHealth?.backend?.launch;
    if (!launch) {
        return 'Not detected';
    }
    return launch;
});

const serverVersionLabel = computed(() => {
    const server = props.apiHealth?.server;
    if (!server?.version) {
        return 'Unavailable';
    }
    return `${server.daemon_type || 'authoritative'} v${server.version}`;
});
</script>

<template>
    <div class="space-y-4">
        <!-- Daemon Plane Error Alert -->
        <UiAlert v-if="daemon?.error" variant="danger">
            <div class="font-semibold">{{ __('PowerDNS Daemon Plane Error') }}</div>
            <p class="mt-1 text-xs break-all">
                {{ daemon.error }}
            </p>
        </UiAlert>

        <!-- Port 53 Conflict Alert -->
        <UiAlert v-if="hasPort53Conflict" variant="danger">
            <div class="font-semibold">{{ __('Port 53 Conflict Detected') }}</div>
            <p class="mt-1 text-xs">
                {{
                    daemon?.port_53?.conflict_info ||
                    __('Another process is currently bound to port 53 and may prevent PowerDNS from starting.')
                }}
            </p>
        </UiAlert>

        <!-- API Connection Error Alert -->
        <UiAlert v-if="!isApiConnected && apiHealth?.error" variant="warning">
            <div class="font-semibold">{{ __('PowerDNS HTTP API Status') }}</div>
            <p class="mt-1 text-xs break-all">
                {{ apiHealth.error }}
            </p>
        </UiAlert>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- System & Daemon Plane Card -->
            <UiCard
                :title="__('Daemon & OS Configuration Plane')"
                :subtitle="__('Managed via Go CLI shim (lc-cli pdns) and systemd service control.')"
            >
                <template #header>
                    <UiBadge :variant="isDaemonActive ? 'success' : 'danger'">
                        <Activity class="w-3.5 h-3.5" />
                        <span>{{ isDaemonActive ? __('systemd: active') : __('systemd: ') + (daemon?.systemd_state || 'inactive') }}</span>
                    </UiBadge>
                </template>

                <div class="space-y-3 text-xs sm:text-sm">
                    <div class="flex items-center justify-between py-2 border-b border-theme-border-subtle">
                        <span class="text-theme-text-secondary flex items-center gap-2">
                            <Server class="w-4 h-4 text-theme-text-muted" />
                            {{ __('OS Family & Service') }}
                        </span>
                        <span class="font-mono text-theme-text-primary">
                            {{ daemon?.distro_family || 'debian' }} ({{ daemon?.service_name || 'pdns' }}.service)
                        </span>
                    </div>

                    <div class="flex items-center justify-between py-2 border-b border-theme-border-subtle">
                        <span class="text-theme-text-secondary flex items-center gap-2">
                            <FileCode class="w-4 h-4 text-theme-text-muted" />
                            {{ __('Main Config Path') }}
                        </span>
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-xs text-theme-text-primary">
                                {{ daemon?.config_path || '/etc/powerdns/pdns.conf' }}
                            </span>
                            <UiBadge :variant="daemon?.include_dir_enabled ? 'success' : 'warning'">
                                {{ daemon?.include_dir_enabled ? __('include-dir OK') : __('include-dir pending') }}
                            </UiBadge>
                        </div>
                    </div>

                    <div class="flex items-center justify-between py-2 border-b border-theme-border-subtle">
                        <span class="text-theme-text-secondary flex items-center gap-2">
                            <FileCode class="w-4 h-4 text-theme-text-muted" />
                            {{ __('Managed Drop-In') }}
                        </span>
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-xs text-theme-text-primary">
                                {{ daemon?.drop_in_path || '/etc/powerdns/pdns.d/99-litecontrol.conf' }}
                            </span>
                            <UiBadge :variant="daemon?.drop_in_present ? 'info' : 'neutral'">
                                {{ daemon?.drop_in_present ? __('Present') : __('Not Written') }}
                            </UiBadge>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <span class="text-theme-text-secondary flex items-center gap-2">
                            <Network class="w-4 h-4 text-theme-text-muted" />
                            {{ __('Port 53 Listener') }}
                        </span>
                        <div class="flex items-center gap-2">
                            <UiBadge :variant="hasPort53Conflict ? 'danger' : 'success'">
                                <AlertTriangle v-if="hasPort53Conflict" class="w-3.5 h-3.5" />
                                <CheckCircle2 v-else class="w-3.5 h-3.5" />
                                <span>
                                    {{
                                        hasPort53Conflict
                                            ? __('Conflict: ') + (daemon?.port_53?.owner || 'unknown')
                                            : __('Owner: ') + (daemon?.port_53?.owner || 'pdns_server')
                                    }}
                                </span>
                            </UiBadge>
                        </div>
                    </div>
                </div>
            </UiCard>

            <!-- Runtime HTTP API & Active Backend Card -->
            <UiCard
                :title="__('HTTP API & Active Backend Plane')"
                :subtitle="__('Live readout from PowerDNS Authoritative HTTP API (/api/v1/servers/localhost).')"
            >
                <template #header>
                    <UiBadge :variant="isApiConnected ? 'success' : 'warning'">
                        <CheckCircle2 v-if="isApiConnected" class="w-3.5 h-3.5" />
                        <AlertTriangle v-else class="w-3.5 h-3.5" />
                        <span>{{ isApiConnected ? __('API Connected') : __('API Unverified') }}</span>
                    </UiBadge>
                </template>

                <div class="space-y-3 text-xs sm:text-sm">
                    <div class="flex items-center justify-between py-2 border-b border-theme-border-subtle">
                        <span class="text-theme-text-secondary flex items-center gap-2">
                            <Server class="w-4 h-4 text-theme-text-muted" />
                            {{ __('Daemon Version') }}
                        </span>
                        <span class="font-mono text-theme-text-primary">
                            {{ serverVersionLabel }}
                        </span>
                    </div>

                    <div class="flex items-center justify-between py-2 border-b border-theme-border-subtle">
                        <span class="text-theme-text-secondary flex items-center gap-2">
                            <Database class="w-4 h-4 text-theme-text-muted" />
                            {{ __('Active Storage Backend (launch)') }}
                        </span>
                        <div class="flex items-center gap-2">
                            <UiBadge :variant="isApiConnected ? 'primary' : 'neutral'">
                                {{ activeBackendLabel }}
                            </UiBadge>
                            <UiBadge
                                v-if="isApiConnected"
                                :variant="apiHealth?.backend?.dnssec_enabled ? 'success' : 'neutral'"
                            >
                                <ShieldCheck class="w-3.5 h-3.5" />
                                <span>
                                    {{ apiHealth?.backend?.dnssec_enabled ? __('DNSSEC Enabled') : __('DNSSEC Off') }}
                                </span>
                            </UiBadge>
                        </div>
                    </div>

                    <div class="flex items-center justify-between py-2 border-b border-theme-border-subtle">
                        <span class="text-theme-text-secondary flex items-center gap-2">
                            <Key class="w-4 h-4 text-theme-text-muted" />
                            {{ __('Encrypted API Key State') }}
                        </span>
                        <div class="flex items-center gap-2">
                            <UiBadge :variant="settings?.has_api_key ? 'success' : 'warning'">
                                {{ settings?.has_api_key ? __('Stored (Encrypted)') : __('Not Generated') }}
                            </UiBadge>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <span class="text-theme-text-secondary">
                            {{ __('API Endpoint') }}
                        </span>
                        <span class="font-mono text-xs text-theme-text-primary">
                            {{ settings?.base_url }}
                        </span>
                    </div>
                </div>

                <template #footer>
                    <div class="flex flex-wrap items-center justify-end gap-3">
                        <UiButton
                            type="button"
                            variant="secondary"
                            size="sm"
                            :loading="flushingCache"
                            :disabled="flushingCache || testingConnection || rotatingKey"
                            @click="emit('flush-cache')"
                        >
                            <Eraser v-if="!flushingCache" class="w-4 h-4" />
                            <span>{{ flushingCache ? __('Flushing Cache...') : __('Flush Global Cache') }}</span>
                        </UiButton>

                        <UiButton
                            type="button"
                            variant="secondary"
                            size="sm"
                            :loading="testingConnection"
                            :disabled="testingConnection || rotatingKey || flushingCache"
                            @click="emit('test-connection')"
                        >
                            <RefreshCw v-if="!testingConnection" class="w-4 h-4" />
                            <span>{{ testingConnection ? __('Testing...') : __('Test Connection') }}</span>
                        </UiButton>

                        <UiButton
                            type="button"
                            variant="warning"
                            size="sm"
                            :loading="rotatingKey"
                            :disabled="rotatingKey || testingConnection || flushingCache"
                            @click="emit('rotate-key')"
                        >
                            <Key v-if="!rotatingKey" class="w-4 h-4" />
                            <span>{{ rotatingKey ? __('Rotating Key...') : __('Rotate API Key') }}</span>
                        </UiButton>
                    </div>
                </template>
            </UiCard>
        </div>
    </div>
</template>
