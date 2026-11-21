<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import {
    Server,
    Plus,
    Trash2,
    Save,
    ShieldCheck,
    Info,
    CheckSquare,
} from '@lucide/vue';
import UiCard from '@shared/Ui/UiCard.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';

interface NameserverEntry {
    hostname: string;
    ipv4: string;
    ipv6: string;
    base_domain?: string;
}

const props = defineProps({
    defaultNameservers: {
        type: Array as () => NameserverEntry[],
        default: () => [],
    },
});

const buildInitialRows = (): NameserverEntry[] => {
    if (props.defaultNameservers && props.defaultNameservers.length > 0) {
        return props.defaultNameservers.map((ns) => ({
            hostname: ns.hostname || '',
            ipv4: ns.ipv4 || '',
            ipv6: ns.ipv6 || '',
        }));
    }

    return [
        { hostname: 'ns1.example.com.', ipv4: '', ipv6: '' },
        { hostname: 'ns2.example.com.', ipv4: '', ipv6: '' },
    ];
};

const form = useForm({
    default_nameservers: buildInitialRows(),
});

watch(
    () => props.defaultNameservers,
    () => {
        form.default_nameservers = buildInitialRows();
    },
    { deep: true }
);

const addNameserverRow = () => {
    if (form.default_nameservers.length >= 8) {
        return;
    }
    const nextIndex = form.default_nameservers.length + 1;
    form.default_nameservers.push({
        hostname: `ns${nextIndex}.example.com.`,
        ipv4: '',
        ipv6: '',
    });
};

const removeNameserverRow = (index: number) => {
    if (form.default_nameservers.length <= 1) {
        return;
    }
    form.default_nameservers.splice(index, 1);
};

const extractBaseDomain = (hostname: string): string => {
    const cleaned = hostname.trim().toLowerCase().replace(/\.+$/, '');
    if (!cleaned) {
        return '';
    }
    const labels = cleaned.split('.').filter(Boolean);
    if (labels.length <= 2) {
        return `${cleaned}.`;
    }
    return `${labels.slice(1).join('.')}.`;
};

const detectedBaseDomains = computed(() => {
    const map = new Map<string, Array<{ hostname: string; ipv4: string; ipv6: string }>>();

    for (const row of form.default_nameservers) {
        const host = row.hostname.trim().toLowerCase();
        if (!host) {
            continue;
        }
        const canonicalHost = host.endsWith('.') ? host : `${host}.`;
        const base = extractBaseDomain(canonicalHost);
        if (!base) {
            continue;
        }
        if (!map.has(base)) {
            map.set(base, []);
        }
        map.get(base)!.push({
            hostname: canonicalHost,
            ipv4: row.ipv4.trim(),
            ipv6: row.ipv6.trim(),
        });
    }

    return Array.from(map.entries()).map(([baseDomain, nameservers]) => ({
        baseDomain,
        nameservers,
    }));
});

const fieldError = (index: number, field: string): string | undefined => {
    const errors = form.errors as Record<string, string>;
    return errors[`default_nameservers.${index}.${field}`];
};

const submit = () => {
    form.post('/dns/nameservers', {
        preserveScroll: true,
    });
};
</script>

<template>
    <UiCard>
        <div class="space-y-5">
            <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3 pb-4 border-b border-theme-border-subtle">
                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <Server class="w-5 h-5 text-theme-accent" />
                        <h2 class="text-base font-bold text-theme-text-primary">
                            {{ __('Default Authoritative Nameservers & Glue Guidance') }}
                        </h2>
                    </div>
                    <p
                        class="text-xs text-theme-text-secondary"
                        v-text="__('Configure default NS hostnames ({{NS1}}, {{NS2}}) and their VPS IP addresses. Used automatically when creating new zones and rendering templates.')"
                    />
                </div>

                <UiButton
                    type="button"
                    variant="secondary"
                    size="sm"
                    :disabled="form.default_nameservers.length >= 8"
                    @click="addNameserverRow"
                >
                    <Plus class="w-4 h-4" />
                    <span>{{ __('Add Nameserver') }}</span>
                </UiButton>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div class="space-y-3">
                    <div
                        v-for="(ns, index) in form.default_nameservers"
                        :key="index"
                        class="grid grid-cols-1 sm:grid-cols-12 gap-3 p-3.5 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle items-start"
                    >
                        <div class="sm:col-span-5">
                            <UiInput
                                :id="`default-ns-hostname-${index}`"
                                v-model="ns.hostname"
                                :label="
                                    index === 0
                                        ? __('Nameserver Hostname ({{NS1}})')
                                        : index === 1
                                          ? __('Nameserver Hostname ({{NS2}})')
                                          : `${__('Nameserver Hostname')} #${index + 1}`
                                "
                                placeholder="ns1.paneldomain.com."
                                :error="fieldError(index, 'hostname')"
                                required
                            />
                        </div>

                        <div class="sm:col-span-3">
                            <UiInput
                                :id="`default-ns-ipv4-${index}`"
                                v-model="ns.ipv4"
                                :label="__('Glue IPv4 Address')"
                                placeholder="203.0.113.10"
                                :error="fieldError(index, 'ipv4')"
                            />
                        </div>

                        <div class="sm:col-span-3">
                            <UiInput
                                :id="`default-ns-ipv6-${index}`"
                                v-model="ns.ipv6"
                                :label="__('Glue IPv6 (Optional)')"
                                placeholder="2001:db8::10"
                                :error="fieldError(index, 'ipv6')"
                            />
                        </div>

                        <div class="sm:col-span-1 flex sm:justify-end pt-6">
                            <UiButton
                                type="button"
                                variant="danger"
                                size="sm"
                                :disabled="form.default_nameservers.length <= 1"
                                :title="__('Remove nameserver')"
                                @click="removeNameserverRow(index)"
                            >
                                <Trash2 class="w-4 h-4" />
                            </UiButton>
                        </div>
                    </div>
                </div>

                <!-- Glue Record Checklist & Guidance Box -->
                <UiAlert variant="info">
                    <div class="space-y-2.5 text-xs">
                        <div class="flex items-center gap-2 font-semibold text-theme-text-primary">
                            <Info class="w-4 h-4 text-theme-accent shrink-0" />
                            <span>{{ __('Glue Record Checklist & In-Bailiwick Nameserver Guidance') }}</span>
                        </div>
                        <p class="text-theme-text-secondary">
                            {{ __('When your nameservers are subdomains of a zone hosted on this server (in-bailiwick), resolvers cannot look up the zone without already knowing the nameserver IPs. Follow this two-step checklist for each base domain below:') }}
                        </p>

                        <div
                            v-if="detectedBaseDomains.length > 0"
                            class="grid grid-cols-1 md:grid-cols-2 gap-2.5 pt-1"
                        >
                            <div
                                v-for="group in detectedBaseDomains"
                                :key="group.baseDomain"
                                class="p-3 rounded-theme-md bg-theme-bg-card border border-theme-border space-y-2"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-mono font-bold text-theme-text-primary">
                                        {{ group.baseDomain }}
                                    </span>
                                    <UiBadge variant="info">
                                        <ShieldCheck class="w-3.5 h-3.5" />
                                        <span>{{ __('Glue Base Zone') }}</span>
                                    </UiBadge>
                                </div>
                                <ul class="space-y-1 text-[11px] text-theme-text-secondary">
                                    <li class="flex items-start gap-1.5">
                                        <CheckSquare class="w-3.5 h-3.5 text-theme-accent shrink-0 mt-0.5" />
                                        <span>
                                            <strong>{{ __('1. Local A/AAAA Glue Records:') }}</strong>
                                            {{ __('Inside the') }}
                                            <code class="font-mono text-theme-text-primary">{{ group.baseDomain }}</code>
                                            {{ __('zone, create A/AAAA records for:') }}
                                            <span class="font-mono text-theme-text-primary">
                                                {{
                                                    group.nameservers
                                                        .map((n) => `${n.hostname} (${n.ipv4 || 'IPv4 required'}${n.ipv6 ? ', ' + n.ipv6 : ''})`)
                                                        .join('; ')
                                                }}
                                            </span>
                                        </span>
                                    </li>
                                    <li class="flex items-start gap-1.5">
                                        <CheckSquare class="w-3.5 h-3.5 text-theme-accent shrink-0 mt-0.5" />
                                        <span>
                                            <strong>{{ __('2. Registrar Glue (Child Nameservers):') }}</strong>
                                            {{ __('Register the same hostnames and IP addresses as Glue / Host Objects at your domain registrar.') }}
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </UiAlert>

                <div class="flex items-center justify-end pt-2">
                    <UiButton
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                        :disabled="form.processing"
                    >
                        <Save class="w-4 h-4" />
                        <span>{{ form.processing ? __('Saving Nameservers...') : __('Save Default Nameservers') }}</span>
                    </UiButton>
                </div>
            </form>
        </div>
    </UiCard>
</template>
