<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import {
    Globe,
    Network,
    ShieldCheck,
    AlertTriangle,
    Sparkles,
    CheckCircle2,
} from '@lucide/vue';
import UiModal from '@shared/Ui/UiModal.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    defaultNameservers: {
        type: Array as () => string[],
        default: () => [],
    },
    capabilities: {
        type: Object,
        default: () => ({
            primary_enabled: true,
            secondary_enabled: false,
            autosecondary_enabled: false,
        }),
    },
});

const emit = defineEmits(['close']);

const form = useForm({
    zone_type: 'forward',
    name: '',
    reverse_prefix: '',
    kind: 'Native',
    nameservers: '',
    masters: '',
});

watch(
    () => props.show,
    (isOpen) => {
        if (isOpen) {
            form.clearErrors();
            if (!form.nameservers && props.defaultNameservers.length > 0) {
                form.nameservers = props.defaultNameservers.join(', ');
            }
        }
    }
);

const isReverseTab = computed(() => form.zone_type !== 'forward');

const selectMainTab = (tab: 'forward' | 'reverse') => {
    form.clearErrors();
    if (tab === 'forward') {
        form.zone_type = 'forward';
    } else if (form.zone_type === 'forward') {
        form.zone_type = 'reverse_ipv4';
    }
};

const selectReverseFamily = (family: 'reverse_ipv4' | 'reverse_ipv6') => {
    form.clearErrors();
    form.zone_type = family;
};

const applyReversePreset = (preset: string, family: 'reverse_ipv4' | 'reverse_ipv6') => {
    form.zone_type = family;
    form.reverse_prefix = preset;
    form.clearErrors('reverse_prefix');
};

/**
 * Pure client-side preview matching ZoneNameNormalizer in PHP.
 */
const expandIpv6ToHex = (ip: string): string | null => {
    const trimmed = ip.trim().toLowerCase();
    if (!trimmed || !/^[0-9a-f:]+$/.test(trimmed)) {
        return null;
    }

    const doubleColonParts = trimmed.split('::');
    if (doubleColonParts.length > 2) {
        return null;
    }

    const leftGroups = doubleColonParts[0] ? doubleColonParts[0].split(':').filter(Boolean) : [];
    const rightGroups =
        doubleColonParts.length === 2 && doubleColonParts[1]
            ? doubleColonParts[1].split(':').filter(Boolean)
            : [];

    if (doubleColonParts.length === 1 && leftGroups.length !== 8) {
        return null;
    }

    const missingGroups = 8 - (leftGroups.length + rightGroups.length);
    if (missingGroups < 0 || (doubleColonParts.length === 2 && missingGroups < 1)) {
        return null;
    }

    const fullGroups = [
        ...leftGroups,
        ...Array(doubleColonParts.length === 2 ? missingGroups : 0).fill('0000'),
        ...rightGroups,
    ];

    if (fullGroups.length !== 8) {
        return null;
    }

    let hex = '';
    for (const group of fullGroups) {
        if (group.length > 4 || !/^[0-9a-f]+$/.test(group)) {
            return null;
        }
        hex += group.padStart(4, '0');
    }

    return hex;
};

const canonicalPreview = computed(() => {
    if (form.zone_type === 'forward') {
        const raw = form.name.trim().replace(/\.+$/, '');
        if (!raw) {
            return { valid: false, zone: '', hint: 'Enter a domain name such as example.com' };
        }
        return {
            valid: raw.includes('.') && !/\s/.test(raw),
            zone: `${raw.toLowerCase()}.`,
            hint: 'Canonical trailing-dot FQDN sent to PowerDNS',
        };
    }

    const raw = form.reverse_prefix.trim().toLowerCase().replace(/\.+$/, '');
    if (!raw) {
        return {
            valid: false,
            zone: '',
            hint:
                form.zone_type === 'reverse_ipv4'
                    ? 'Enter an IPv4 CIDR (e.g. 192.0.2.0/24) to invert octets automatically'
                    : 'Enter an IPv6 prefix (e.g. 2001:db8:abcd::/48) to invert nibbles automatically',
        };
    }

    if (form.zone_type === 'reverse_ipv4') {
        if (raw.endsWith('.in-addr.arpa')) {
            return { valid: true, zone: `${raw}.`, hint: 'Existing IPv4 reverse zone name' };
        }

        const [ipPart, prefixPart] = raw.split('/');
        const octets = (ipPart || '').split('.');

        if (prefixPart !== undefined) {
            const prefixLen = Number(prefixPart);
            if (
                octets.length === 4 &&
                octets.every((o) => /^\d+$/.test(o) && Number(o) >= 0 && Number(o) <= 255) &&
                [8, 16, 24, 32].includes(prefixLen)
            ) {
                const count = prefixLen / 8;
                const inverted = octets.slice(0, count).reverse().join('.');
                return {
                    valid: true,
                    zone: `${inverted}.in-addr.arpa.`,
                    hint: `Auto-generated IPv4 /${prefixLen} PTR reverse zone`,
                };
            }
            return {
                valid: false,
                zone: '',
                hint: 'Use a valid IPv4 address with /8, /16, /24, or /32 prefix',
            };
        }

        if (
            octets.length >= 1 &&
            octets.length <= 3 &&
            octets.every((o) => /^\d+$/.test(o) && Number(o) >= 0 && Number(o) <= 255)
        ) {
            const inverted = [...octets].reverse().join('.');
            return {
                valid: true,
                zone: `${inverted}.in-addr.arpa.`,
                hint: 'Auto-generated from IPv4 octet prefix',
            };
        }

        return {
            valid: false,
            zone: '',
            hint: 'Provide a CIDR such as 192.0.2.0/24',
        };
    }

    // reverse_ipv6
    if (raw.endsWith('.ip6.arpa')) {
        return { valid: true, zone: `${raw}.`, hint: 'Existing IPv6 reverse zone name' };
    }

    if (raw.includes('/')) {
        const [ipPart, prefixPart] = raw.split('/');
        const prefixLen = Number(prefixPart);
        const fullHex = expandIpv6ToHex(ipPart || '');

        if (
            fullHex &&
            Number.isInteger(prefixLen) &&
            prefixLen >= 4 &&
            prefixLen <= 128 &&
            prefixLen % 4 === 0
        ) {
            const nibbleCount = prefixLen / 4;
            const invertedNibbles = fullHex
                .slice(0, nibbleCount)
                .split('')
                .reverse()
                .join('.');

            return {
                valid: true,
                zone: `${invertedNibbles}.ip6.arpa.`,
                hint: `Auto-generated IPv6 /${prefixLen} (${nibbleCount} nibbles) PTR reverse zone`,
            };
        }
    }

    return {
        valid: false,
        zone: '',
        hint: 'Provide a nibble-aligned IPv6 CIDR such as 2001:db8:abcd::/48',
    };
});

const kindOptions = [
    { value: 'Native', label: 'Native — Standalone or DB-replicated (SOA-EDIT-API=DEFAULT)' },
    { value: 'Primary', label: 'Primary (Master) — Sends NOTIFY & serves AXFR/IXFR to secondaries' },
    { value: 'Secondary', label: 'Secondary (Slave) — Pulls records via AXFR/IXFR from upstream primary' },
];

const showPrimaryDaemonWarning = computed(
    () => form.kind === 'Primary' && !props.capabilities?.primary_enabled
);

const showSecondaryDaemonWarning = computed(
    () => form.kind === 'Secondary' && !props.capabilities?.secondary_enabled
);

const submit = () => {
    form.post('/dns/zones', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('name', 'reverse_prefix', 'masters');
            emit('close');
        },
    });
};
</script>

<template>
    <UiModal
        :show="show"
        :title="__('Create Authoritative DNS Zone')"
        max-width="xl"
        @close="emit('close')"
    >
        <form @submit.prevent="submit" class="space-y-5">
            <!-- Forward vs Reverse Zone Tabs -->
            <div class="grid grid-cols-2 gap-2 p-1 rounded-theme-md bg-theme-bg-surface border border-theme-border">
                <button
                    type="button"
                    @click="selectMainTab('forward')"
                    :class="[
                        'flex items-center justify-center gap-2 py-2 px-3 rounded-theme-sm text-xs sm:text-sm font-medium transition-all cursor-pointer',
                        !isReverseTab
                            ? 'bg-theme-accent text-theme-text-on-accent font-semibold shadow-xs'
                            : 'text-theme-text-secondary hover:text-theme-text-primary',
                    ]"
                >
                    <Globe class="w-4 h-4" />
                    <span>{{ __('Forward Zone') }}</span>
                </button>
                <button
                    type="button"
                    @click="selectMainTab('reverse')"
                    :class="[
                        'flex items-center justify-center gap-2 py-2 px-3 rounded-theme-sm text-xs sm:text-sm font-medium transition-all cursor-pointer',
                        isReverseTab
                            ? 'bg-theme-accent text-theme-text-on-accent font-semibold shadow-xs'
                            : 'text-theme-text-secondary hover:text-theme-text-primary',
                    ]"
                >
                    <Network class="w-4 h-4" />
                    <span>{{ __('Reverse Zone (PTR)') }}</span>
                </button>
            </div>

            <!-- Forward Zone Input -->
            <div v-if="!isReverseTab" class="space-y-3">
                <UiInput
                    id="pdns-zone-name"
                    v-model="form.name"
                    :label="__('Domain Name (Forward Zone)')"
                    placeholder="example.com"
                    :hint="__('Unicode / IDN domains (e.g. münchen.de) are automatically converted to Punycode with a trailing dot.')"
                    :error="form.errors.name"
                    required
                />
            </div>

            <!-- Reverse Zone Helper (IPv4 / IPv6 CIDR -> in-addr.arpa. / ip6.arpa.) -->
            <div v-else class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xs font-medium text-theme-text-secondary">
                        {{ __('Address Family & Reverse Zone Helper') }}
                    </span>
                    <div class="inline-flex rounded-theme-md border border-theme-border bg-theme-bg-surface p-0.5">
                        <button
                            type="button"
                            @click="selectReverseFamily('reverse_ipv4')"
                            :class="[
                                'px-3 py-1 rounded-theme-sm text-xs font-medium transition-colors cursor-pointer',
                                form.zone_type === 'reverse_ipv4'
                                    ? 'bg-theme-accent text-theme-text-on-accent font-semibold'
                                    : 'text-theme-text-secondary hover:text-theme-text-primary',
                            ]"
                        >
                            {{ __('IPv4 (in-addr.arpa)') }}
                        </button>
                        <button
                            type="button"
                            @click="selectReverseFamily('reverse_ipv6')"
                            :class="[
                                'px-3 py-1 rounded-theme-sm text-xs font-medium transition-colors cursor-pointer',
                                form.zone_type === 'reverse_ipv6'
                                    ? 'bg-theme-accent text-theme-text-on-accent font-semibold'
                                    : 'text-theme-text-secondary hover:text-theme-text-primary',
                            ]"
                        >
                            {{ __('IPv6 (ip6.arpa)') }}
                        </button>
                    </div>
                </div>

                <UiInput
                    id="pdns-reverse-prefix"
                    v-model="form.reverse_prefix"
                    :label="
                        form.zone_type === 'reverse_ipv4'
                            ? __('IPv4 CIDR / Prefix')
                            : __('IPv6 CIDR / Nibble-Aligned Prefix')
                    "
                    :placeholder="
                        form.zone_type === 'reverse_ipv4'
                            ? '192.0.2.0/24'
                            : '2001:db8:abcd::/48'
                    "
                    :hint="
                        form.zone_type === 'reverse_ipv4'
                            ? __('Supports /8, /16, /24, /32 CIDRs (e.g. 192.0.2.0/24 → 2.0.192.in-addr.arpa.) or direct in-addr.arpa names.')
                            : __('Supports /4 to /128 prefixes divisible by 4 (e.g. 2001:db8:abcd::/48 → d.c.b.a.8.b.d.0.1.0.0.2.ip6.arpa.).')
                    "
                    :error="form.errors.reverse_prefix"
                    required
                />

                <!-- Quick Reverse Example Presets -->
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-theme-text-muted flex items-center gap-1">
                        <Sparkles class="w-3.5 h-3.5" />
                        {{ __('Quick examples:') }}
                    </span>
                    <template v-if="form.zone_type === 'reverse_ipv4'">
                        <button
                            type="button"
                            @click="applyReversePreset('192.0.2.0/24', 'reverse_ipv4')"
                            class="px-2 py-0.5 rounded-theme-sm bg-theme-bg-surface border border-theme-border text-theme-text-secondary hover:text-theme-text-primary font-mono cursor-pointer"
                        >
                            192.0.2.0/24
                        </button>
                        <button
                            type="button"
                            @click="applyReversePreset('198.51.0.0/16', 'reverse_ipv4')"
                            class="px-2 py-0.5 rounded-theme-sm bg-theme-bg-surface border border-theme-border text-theme-text-secondary hover:text-theme-text-primary font-mono cursor-pointer"
                        >
                            198.51.0.0/16
                        </button>
                    </template>
                    <template v-else>
                        <button
                            type="button"
                            @click="applyReversePreset('2001:db8:abcd::/48', 'reverse_ipv6')"
                            class="px-2 py-0.5 rounded-theme-sm bg-theme-bg-surface border border-theme-border text-theme-text-secondary hover:text-theme-text-primary font-mono cursor-pointer"
                        >
                            2001:db8:abcd::/48
                        </button>
                        <button
                            type="button"
                            @click="applyReversePreset('2001:db8:abcd:0012::/64', 'reverse_ipv6')"
                            class="px-2 py-0.5 rounded-theme-sm bg-theme-bg-surface border border-theme-border text-theme-text-secondary hover:text-theme-text-primary font-mono cursor-pointer"
                        >
                            2001:db8:abcd:0012::/64
                        </button>
                    </template>
                </div>
            </div>

            <!-- Live Canonical Zone Name Preview -->
            <div class="p-3.5 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle space-y-1.5">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs font-medium text-theme-text-secondary">
                        {{ __('Canonical PowerDNS Zone ID') }}
                    </span>
                    <UiBadge :variant="canonicalPreview.valid ? 'success' : 'neutral'">
                        <CheckCircle2 v-if="canonicalPreview.valid" class="w-3.5 h-3.5" />
                        <span>{{ canonicalPreview.valid ? __('Normalized FQDN') : __('Awaiting Input') }}</span>
                    </UiBadge>
                </div>
                <div class="font-mono text-xs sm:text-sm text-theme-text-primary break-all">
                    {{ canonicalPreview.zone || '—' }}
                </div>
                <p class="text-[11px] text-theme-text-muted">
                    {{ canonicalPreview.hint }}
                </p>
            </div>

            <!-- Zone Kind / Operating Mode -->
            <UiSelect
                id="pdns-zone-kind"
                v-model="form.kind"
                :label="__('Replication Mode (Kind)')"
                :options="kindOptions"
                :error="form.errors.kind"
                required
            />

            <UiAlert v-if="showPrimaryDaemonWarning" variant="warning">
                <div class="flex items-start gap-2 text-xs">
                    <AlertTriangle class="w-4 h-4 shrink-0 mt-0.5" />
                    <div>
                        <span class="font-semibold">{{ __('Global Primary role is currently disabled') }}</span>
                        <p class="mt-0.5">
                            {{ __('Enable primary=yes in Daemon & API Settings so PowerDNS sends outbound NOTIFY packets and answers AXFR requests.') }}
                        </p>
                    </div>
                </div>
            </UiAlert>

            <UiAlert v-if="showSecondaryDaemonWarning" variant="warning">
                <div class="flex items-start gap-2 text-xs">
                    <AlertTriangle class="w-4 h-4 shrink-0 mt-0.5" />
                    <div>
                        <span class="font-semibold">{{ __('Global Secondary role is currently disabled') }}</span>
                        <p class="mt-0.5">
                            {{ __('Enable secondary=yes in Daemon & API Settings so PowerDNS pulls zone transfers from your upstream primary servers.') }}
                        </p>
                    </div>
                </div>
            </UiAlert>

            <!-- Nameservers (for Native / Primary) or Masters (for Secondary) -->
            <div v-if="form.kind !== 'Secondary'" class="space-y-3">
                <UiInput
                    id="pdns-zone-nameservers"
                    v-model="form.nameservers"
                    :label="__('Authoritative Nameservers (Optional)')"
                    placeholder="ns1.example.com., ns2.example.com."
                    :hint="__('Comma-separated FQDNs. Leave blank to auto-assign default nameservers (ns1/ns2).')"
                    :error="form.errors.nameservers"
                />

                <div class="flex flex-wrap items-center gap-2 pt-1">
                    <UiBadge variant="info">
                        <ShieldCheck class="w-3.5 h-3.5" />
                        <span>SOA-EDIT-API = DEFAULT</span>
                    </UiBadge>
                    <UiBadge variant="info">
                        <ShieldCheck class="w-3.5 h-3.5" />
                        <span>API-RECTIFY = 1</span>
                    </UiBadge>
                    <span class="text-[11px] text-theme-text-muted">
                        {{ __('Auto-attached on creation for automatic serial increments and DNSSEC/record rectification.') }}
                    </span>
                </div>
            </div>

            <div v-else class="space-y-2">
                <UiInput
                    id="pdns-zone-masters"
                    v-model="form.masters"
                    :label="__('Upstream Primary Server IPs (Masters)')"
                    placeholder="203.0.113.10, 198.51.100.20:53"
                    :hint="__('Comma-separated IPv4 or IPv6 addresses (with optional :port) from which PowerDNS will transfer this zone via AXFR/IXFR.')"
                    :error="form.errors.masters"
                    required
                />
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-theme-border-subtle">
                <UiButton
                    type="button"
                    variant="secondary"
                    :disabled="form.processing"
                    @click="emit('close')"
                >
                    {{ __('Cancel') }}
                </UiButton>
                <UiButton
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    :disabled="form.processing"
                >
                    {{ form.processing ? __('Creating Zone...') : __('Create DNS Zone') }}
                </UiButton>
            </div>
        </form>
    </UiModal>
</template>
