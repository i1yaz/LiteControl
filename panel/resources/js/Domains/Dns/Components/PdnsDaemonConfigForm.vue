<script setup lang="ts">
import { computed } from 'vue';
import { Save, ShieldAlert, Lock, Globe, Network } from '@lucide/vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';

const props = defineProps({
    form: {
        type: Object,
        required: true,
    },
    hasWebserverPassword: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['submit']);

const isLoopbackHost = computed(() => {
    const host = String(props.form.host || '').trim();
    return host === '127.0.0.1' || host.startsWith('127.') || host === '::1';
});

const handleAutosecondaryChange = (checked: boolean) => {
    props.form.autosecondary_enabled = checked;
    if (checked && !props.form.secondary_enabled) {
        props.form.secondary_enabled = true;
    }
};
</script>

<template>
    <form @submit.prevent="emit('submit')" class="space-y-6">
        <!-- HTTP API & Webserver Binding Card -->
        <UiCard
            :title="__('HTTP API & Webserver Binding')"
            :subtitle="__('Controls the embedded PowerDNS HTTP API listener written to 99-litecontrol.conf.')"
        >
            <template #header>
                <UiBadge :variant="isLoopbackHost ? 'success' : 'warning'">
                    <Lock class="w-3.5 h-3.5" />
                    <span>{{ isLoopbackHost ? __('Loopback Isolated') : __('External Bind') }}</span>
                </UiBadge>
            </template>

            <div class="space-y-5">
                <UiAlert v-if="!isLoopbackHost" variant="warning">
                    <div class="font-semibold flex items-center gap-1.5">
                        <ShieldAlert class="w-4 h-4" />
                        <span>{{ __('Non-Loopback Bind Address Security Guardrail') }}</span>
                    </div>
                    <p class="mt-1 text-xs">
                        {{
                            __('Binding the PowerDNS HTTP API to a non-loopback IP requires an explicit webserver allowlist (0.0.0.0/0 and ::/0 are rejected) and a mandatory webserver password.')
                        }}
                    </p>
                </UiAlert>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <UiInput
                        id="pdns-host"
                        v-model="form.host"
                        :label="__('Webserver Bind Address (webserver-address)')"
                        placeholder="127.0.0.1"
                        :hint="__('Default 127.0.0.1 keeps the PowerDNS HTTP API restricted to local loopback.')"
                        required
                        :error="form.errors.host"
                    />

                    <UiInput
                        id="pdns-port"
                        v-model="form.port"
                        type="number"
                        :label="__('Webserver Port (webserver-port)')"
                        placeholder="8081"
                        :hint="__('TCP port for /api/v1/servers/localhost (default: 8081).')"
                        required
                        :error="form.errors.port"
                    />

                    <UiInput
                        id="pdns-allow-from"
                        v-model="form.webserver_allow_from"
                        :label="__('Allowed Client IPs / CIDRs (webserver-allow-from)')"
                        placeholder="127.0.0.1,::1"
                        :hint="__('Comma-separated IPs or CIDRs allowed to query the HTTP API.')"
                        required
                        :error="form.errors.webserver_allow_from"
                    />

                    <UiInput
                        id="pdns-webserver-password"
                        v-model="form.webserver_password"
                        type="password"
                        :label="__('Webserver Password (webserver-password)')"
                        :placeholder="
                            hasWebserverPassword
                                ? __('•••••••• (Leave blank to keep existing password)')
                                : __('Required for non-loopback bind addresses')
                        "
                        :hint="__('Protects the built-in PowerDNS metrics dashboard. Encrypted at rest.')"
                        :required="!isLoopbackHost && !hasWebserverPassword"
                        :error="form.errors.webserver_password"
                    />
                </div>
            </div>
        </UiCard>

        <!-- Global Role Capabilities & Replication Defaults Card -->
        <UiCard
            :title="__('Global Role Capabilities & Transfer Defaults')"
            :subtitle="__('Daemon-wide primary, secondary, and autosecondary replication flags in 99-litecontrol.conf.')"
        >
            <div class="space-y-6">
                <!-- Capability Toggle Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Primary Toggle -->
                    <label
                        class="flex items-start gap-3 p-4 rounded-theme-md border border-theme-border bg-theme-bg-surface cursor-pointer hover:border-theme-accent transition-colors"
                    >
                        <input
                            v-model="form.primary_enabled"
                            type="checkbox"
                            class="mt-1 rounded border-theme-border text-theme-accent focus:ring-theme-accent"
                        />
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <Globe class="w-4 h-4 text-theme-accent" />
                                <span class="text-xs sm:text-sm font-semibold text-theme-text-primary">
                                    {{ __('Primary Mode (primary=yes)') }}
                                </span>
                            </div>
                            <p class="text-xs text-theme-text-secondary">
                                {{ __('Required for Primary zones to send outbound NOTIFY packets and answer AXFR/IXFR transfers.') }}
                            </p>
                        </div>
                    </label>

                    <!-- Secondary Toggle -->
                    <label
                        class="flex items-start gap-3 p-4 rounded-theme-md border border-theme-border bg-theme-bg-surface cursor-pointer hover:border-theme-accent transition-colors"
                    >
                        <input
                            v-model="form.secondary_enabled"
                            type="checkbox"
                            class="mt-1 rounded border-theme-border text-theme-accent focus:ring-theme-accent"
                        />
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <Network class="w-4 h-4 text-theme-accent" />
                                <span class="text-xs sm:text-sm font-semibold text-theme-text-primary">
                                    {{ __('Secondary Mode (secondary=yes)') }}
                                </span>
                            </div>
                            <p class="text-xs text-theme-text-secondary">
                                {{ __('Required for Secondary zones to poll and pull zone transfers from upstream primaries.') }}
                            </p>
                        </div>
                    </label>

                    <!-- Autosecondary Toggle -->
                    <label
                        class="flex items-start gap-3 p-4 rounded-theme-md border border-theme-border bg-theme-bg-surface cursor-pointer hover:border-theme-accent transition-colors"
                    >
                        <input
                            :checked="form.autosecondary_enabled"
                            type="checkbox"
                            class="mt-1 rounded border-theme-border text-theme-accent focus:ring-theme-accent"
                            @change="handleAutosecondaryChange(($event.target as HTMLInputElement).checked)"
                        />
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <Network class="w-4 h-4 text-theme-accent" />
                                <span class="text-xs sm:text-sm font-semibold text-theme-text-primary">
                                    {{ __('Autosecondary (autosecondary=yes)') }}
                                </span>
                            </div>
                            <p class="text-xs text-theme-text-secondary">
                                {{ __('Accept NOTIFY from trusted Autoprimaries (Supermasters) to auto-provision new secondary zones.') }}
                            </p>
                        </div>
                    </label>
                </div>

                <!-- Global Transfer Default Inputs -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 pt-2 border-t border-theme-border-subtle">
                    <UiInput
                        id="pdns-allow-axfr-ips"
                        v-model="form.allow_axfr_ips"
                        :label="__('Global Allow AXFR IPs (allow-axfr-ips)')"
                        placeholder="198.51.100.10, 203.0.113.0/24"
                        :hint="__('Optional comma-separated secondary IPs/CIDRs permitted to AXFR unless overridden per zone.')"
                        :error="form.errors.allow_axfr_ips"
                    />

                    <UiInput
                        id="pdns-also-notify"
                        v-model="form.also_notify"
                        :label="__('Global Also-Notify IPs (also-notify)')"
                        placeholder="198.51.100.10, 203.0.113.20:5300"
                        :hint="__('Optional comma-separated hidden secondary IPs (with optional :port) notified on zone updates.')"
                        :error="form.errors.also_notify"
                    />
                </div>
            </div>

            <template #footer>
                <div class="flex items-center justify-between">
                    <span class="text-xs text-theme-text-muted">
                        {{ __('Changes are validated with pdns_server --config-check before restarting pdns.service.') }}
                    </span>

                    <UiButton
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                        :disabled="form.processing"
                    >
                        <Save v-if="!form.processing" class="w-4 h-4" />
                        <span>{{ form.processing ? __('Validating & Applying...') : __('Save & Apply Configuration') }}</span>
                    </UiButton>
                </div>
            </template>
        </UiCard>
    </form>
</template>
