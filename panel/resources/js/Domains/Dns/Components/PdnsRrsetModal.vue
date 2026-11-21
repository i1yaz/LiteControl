<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import {
    Plus,
    Trash2,
    Save,
    Layers,
    Clock,
    Sparkles,
    MessageSquare,
    CheckCircle2,
    PauseCircle,
    Info,
} from '@lucide/vue';
import UiModal from '@shared/Ui/UiModal.vue';
import UiInput from '@shared/Ui/UiInput.vue';
import UiSelect from '@shared/Ui/UiSelect.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiAlert from '@shared/Ui/UiAlert.vue';

interface StructuredRecordDraft {
    // Common
    content: string;
    disabled: boolean;
    // MX & SRV
    priority: number;
    // SRV
    weight: number;
    port: number;
    target: string;
    // CAA
    flags: number;
    tag: string;
    value: string;
}

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    zone: {
        type: Object as () => Record<string, any>,
        required: true,
    },
    editingRrset: {
        type: Object as () => Record<string, any> | null,
        default: null,
    },
    initialChangetype: {
        type: String,
        default: 'REPLACE',
    },
    editableRecordTypes: {
        type: Array as () => string[],
        default: () => ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'NS', 'PTR', 'CAA'],
    },
    ttlPresets: {
        type: Array as () => number[],
        default: () => [60, 300, 900, 3600, 14400, 86400],
    },
    submitting: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close', 'save-now', 'stage-operation']);

const name = ref('@');
const type = ref('A');
const ttl = ref(3600);
const changetype = ref<'REPLACE' | 'EXTEND'>('REPLACE');
const comment = ref('');
const records = ref<StructuredRecordDraft[]>([]);
const clientError = ref<string | null>(null);

const createEmptyRecordDraft = (recType: string): StructuredRecordDraft => ({
    content: '',
    disabled: false,
    priority: 10,
    weight: 10,
    port: recType === 'SRV' ? 443 : 0,
    target: '',
    flags: 0,
    tag: 'issue',
    value: '',
});

const hydrateDraftFromRecord = (recType: string, rec: Record<string, any>): StructuredRecordDraft => {
    const parsed = rec.parsed || {};
    const rawContent = String(rec.content || '');
    const draft = createEmptyRecordDraft(recType);
    draft.disabled = Boolean(rec.disabled);

    if (recType === 'MX') {
        draft.priority = parsed.priority !== undefined ? Number(parsed.priority) : 10;
        draft.target = String(parsed.target || rawContent);
    } else if (recType === 'SRV') {
        draft.priority = parsed.priority !== undefined ? Number(parsed.priority) : 10;
        draft.weight = parsed.weight !== undefined ? Number(parsed.weight) : 10;
        draft.port = parsed.port !== undefined ? Number(parsed.port) : 443;
        draft.target = String(parsed.target || rawContent);
    } else if (recType === 'CAA') {
        draft.flags = parsed.flags !== undefined ? Number(parsed.flags) : 0;
        draft.tag = String(parsed.tag || 'issue');
        draft.value = String(parsed.value || rawContent);
    } else if (recType === 'TXT') {
        draft.content = String(parsed.raw_text !== undefined ? parsed.raw_text : rawContent);
    } else {
        draft.content = rawContent;
    }

    return draft;
};

watch(
    () => [props.show, props.editingRrset, props.initialChangetype],
    ([isOpen]) => {
        if (!isOpen) {
            return;
        }
        clientError.value = null;

        if (props.editingRrset) {
            const rr = props.editingRrset;
            name.value = String(rr.relative_name || rr.name || '@');
            type.value = String(rr.type || 'A');
            ttl.value = Number(rr.ttl || 3600);
            changetype.value = 'REPLACE';
            comment.value = String(rr.primary_comment || rr.comments?.[0]?.content || '');

            if (Array.isArray(rr.records) && rr.records.length > 0) {
                records.value = rr.records.map((r: Record<string, any>) =>
                    hydrateDraftFromRecord(type.value, r)
                );
            } else {
                records.value = [createEmptyRecordDraft(type.value)];
            }
        } else {
            name.value = props.zone?.is_reverse ? '1' : '@';
            type.value = props.zone?.is_reverse ? 'PTR' : 'A';
            ttl.value = 3600;
            changetype.value = props.initialChangetype === 'EXTEND' ? 'EXTEND' : 'REPLACE';
            comment.value = '';
            records.value = [createEmptyRecordDraft(type.value)];
        }
    },
    { immediate: true }
);

watch(type, (newType) => {
    clientError.value = null;
    if (newType === 'CNAME' && records.value.length > 1) {
        records.value = [records.value[0]];
    }
});

watch(changetype, (newMode) => {
    if (newMode === 'EXTEND') {
        if (records.value.length > 1) {
            records.value = [records.value[0]];
        }
        comment.value = '';
    }
});

const isEditMode = computed(() => Boolean(props.editingRrset));

const typeOptions = computed(() =>
    props.editableRecordTypes.map((t) => ({
        value: t,
        label: t,
    }))
);

const changetypeOptions = [
    {
        value: 'REPLACE',
        label: 'REPLACE — Atomically set all records & comments for this Name + Type',
    },
    {
        value: 'EXTEND',
        label: 'EXTEND — Quick-add single record to existing RRSet (empty comments)',
    },
];

const caaFlagOptions = [
    { value: 0, label: '0 — Non-critical (Standard)' },
    { value: 128, label: '128 — Issuer Critical' },
];

const caaTagOptions = [
    { value: 'issue', label: 'issue (Authorize CA for single/all hostnames)' },
    { value: 'issuewild', label: 'issuewild (Authorize CA for wildcard certificates)' },
    { value: 'iodef', label: 'iodef (Policy violation reporting URL/mailto)' },
];

const canonicalNamePreview = computed(() => {
    const raw = name.value.trim();
    const zoneFqdn = String(props.zone?.name || 'example.com.');
    const bareZone = zoneFqdn.replace(/\.$/, '');

    if (!raw || raw === '@') {
        return zoneFqdn;
    }
    if (raw.endsWith('.')) {
        return raw.toLowerCase();
    }
    if (raw.toLowerCase() === bareZone.toLowerCase() || raw.toLowerCase().endsWith(`.${bareZone.toLowerCase()}`)) {
        return `${raw.toLowerCase()}.`;
    }
    return `${raw.toLowerCase()}.${zoneFqdn}`;
});

const canAddMultipleRecords = computed(() => {
    return changetype.value === 'REPLACE' && type.value !== 'CNAME';
});

const addRecordRow = () => {
    if (!canAddMultipleRecords.value) {
        return;
    }
    records.value.push(createEmptyRecordDraft(type.value));
};

const removeRecordRow = (idx: number) => {
    if (records.value.length <= 1) {
        return;
    }
    records.value.splice(idx, 1);
};

const applyTxtPreset = (idx: number, presetKey: string) => {
    const bareZone = String(props.zone?.ascii_name || 'example.com');
    const row = records.value[idx];
    if (!row) return;

    switch (presetKey) {
        case 'spf':
            row.content = 'v=spf1 a mx ~all';
            break;
        case 'dmarc':
            if (name.value === '@' || name.value === '') {
                name.value = '_dmarc';
            }
            row.content = `v=DMARC1; p=quarantine; rua=mailto:postmaster@${bareZone}; pct=100`;
            break;
        case 'dkim':
            if (name.value === '@' || name.value === '') {
                name.value = 'default._domainkey';
            }
            row.content =
                'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAv1234567890abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdefghijklmnopqrstuvwxIDAQAB';
            break;
        case 'acme':
            name.value = '_acme-challenge';
            ttl.value = 60;
            row.content = 'acme-dns01-validation-token-string';
            break;
    }
};

const txtChunkCount = (text: string): number => {
    const len = new TextEncoder().encode(text || '').length;
    return Math.max(1, Math.ceil(len / 255));
};

const txtByteLength = (text: string): number => {
    return new TextEncoder().encode(text || '').length;
};

const formatTtlPreset = (seconds: number): string => {
    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) return `${seconds / 60}m`;
    if (seconds < 86400) return `${seconds / 3600}h`;
    return `${seconds / 86400}d`;
};

const buildOperationPayload = (): Record<string, any> | null => {
    clientError.value = null;

    const trimmedName = name.value.trim();
    if (!trimmedName) {
        clientError.value = 'Record owner name is required (use @ for the zone apex).';
        return null;
    }

    const numericTtl = Number(ttl.value);
    if (!Number.isInteger(numericTtl) || numericTtl < 60 || numericTtl > 604800) {
        clientError.value = 'Record TTL must be an integer between 60 and 604800 seconds.';
        return null;
    }

    if (type.value === 'CNAME' && (trimmedName === '@' || canonicalNamePreview.value === props.zone?.name)) {
        clientError.value = 'CNAME record is not permitted at the zone apex (@) per RFC 1034/1912.';
        return null;
    }

    const builtRecords: Array<Record<string, any>> = [];

    for (let i = 0; i < records.value.length; i++) {
        const row = records.value[i];
        const disabled = Boolean(row.disabled);

        if (type.value === 'MX') {
            const target = row.target.trim();
            if (!target) {
                clientError.value = `MX record #${i + 1} requires a mail server target hostname.`;
                return null;
            }
            builtRecords.push({
                priority: Number(row.priority),
                target,
                disabled,
            });
        } else if (type.value === 'SRV') {
            const target = row.target.trim();
            if (!target) {
                clientError.value = `SRV record #${i + 1} requires a target hostname.`;
                return null;
            }
            builtRecords.push({
                priority: Number(row.priority),
                weight: Number(row.weight),
                port: Number(row.port),
                target,
                disabled,
            });
        } else if (type.value === 'CAA') {
            const value = row.value.trim();
            if (!value) {
                clientError.value = `CAA record #${i + 1} requires a CA domain or reporting value.`;
                return null;
            }
            builtRecords.push({
                flags: Number(row.flags),
                tag: row.tag,
                value,
                disabled,
            });
        } else {
            const content = row.content.trim();
            if (!content) {
                clientError.value = `${type.value} record #${i + 1} content cannot be empty.`;
                return null;
            }
            builtRecords.push({
                content,
                disabled,
            });
        }
    }

    const trimmedComment = comment.value.trim();
    const commentsPayload =
        changetype.value === 'REPLACE' && trimmedComment !== ''
            ? [{ content: trimmedComment, account: 'litecontrol' }]
            : [];

    return {
        name: trimmedName,
        canonical_preview: canonicalNamePreview.value,
        type: type.value,
        ttl: numericTtl,
        changetype: changetype.value,
        records: changetype.value === 'EXTEND' ? [builtRecords[0]] : builtRecords,
        comments: commentsPayload,
    };
};

const handleSaveNow = () => {
    const op = buildOperationPayload();
    if (!op) return;
    emit('save-now', op);
};

const handleStageDraft = () => {
    const op = buildOperationPayload();
    if (!op) return;
    emit('stage-operation', op);
    emit('close');
};
</script>

<template>
    <UiModal
        :show="show"
        :title="
            isEditMode
                ? __('Edit DNS Record Set (RRSet)')
                : changetype === 'EXTEND'
                  ? __('Quick-Add Single DNS Record (EXTEND)')
                  : __('Create / Replace DNS Record Set (RRSet)')
        "
        max-width="2xl"
        @close="emit('close')"
    >
        <form @submit.prevent="handleSaveNow" class="space-y-5">
            <UiAlert v-if="clientError" variant="danger">
                <div class="text-xs font-medium">{{ clientError }}</div>
            </UiAlert>

            <!-- Operation Mode (REPLACE vs EXTEND) when creating -->
            <div v-if="!isEditMode" class="p-3 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle">
                <UiSelect
                    id="pdns-rrset-changetype"
                    v-model="changetype"
                    :label="__('PowerDNS Atomic RRSet Operation Mode (changetype)')"
                    :options="changetypeOptions"
                />
            </div>

            <!-- Owner Name & Record Type -->
            <div class="grid grid-cols-1 sm:grid-cols-12 gap-4">
                <div class="sm:col-span-7 space-y-1.5">
                    <UiInput
                        id="pdns-rrset-name"
                        v-model="name"
                        :label="__('Record Owner Name (Host / Label)')"
                        :placeholder="zone?.is_reverse ? '10 (Last IP octet or nibble)' : '@ for apex, www, mail, _dmarc, *'"
                        :disabled="isEditMode"
                        required
                    />
                    <div class="flex items-center justify-between text-[11px] text-theme-text-muted">
                        <span>{{ __('Canonical FQDN sent to PowerDNS:') }}</span>
                        <code class="font-mono font-semibold text-theme-text-primary">{{ canonicalNamePreview }}</code>
                    </div>
                </div>

                <div class="sm:col-span-5">
                    <UiSelect
                        id="pdns-rrset-type"
                        v-model="type"
                        :label="__('Record Type')"
                        :options="typeOptions"
                        :disabled="isEditMode"
                        required
                    />
                </div>
            </div>

            <!-- TTL Presets & Custom TTL -->
            <div class="p-3.5 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xs font-semibold text-theme-text-secondary flex items-center gap-1.5">
                        <Clock class="w-3.5 h-3.5 text-theme-accent" />
                        <span>{{ __('RRSet Time-To-Live (TTL: 60s – 604800s)') }}</span>
                    </span>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <button
                            v-for="preset in ttlPresets"
                            :key="preset"
                            type="button"
                            class="px-2.5 py-1 rounded-theme-sm text-xs font-mono font-semibold border transition-colors cursor-pointer"
                            :class="
                                Number(ttl) === preset
                                    ? 'bg-theme-accent text-white border-theme-accent'
                                    : 'bg-theme-bg-card text-theme-text-secondary border-theme-border hover:text-theme-text-primary'
                            "
                            @click="ttl = preset"
                        >
                            {{ preset }}s ({{ formatTtlPreset(preset) }})
                        </button>
                    </div>
                </div>

                <UiInput
                    id="pdns-rrset-ttl"
                    v-model="ttl"
                    type="number"
                    :label="__('Custom TTL (Seconds)')"
                    placeholder="3600"
                    required
                />
            </div>

            <!-- Type-Aware Guided Record Inputs -->
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-theme-text-secondary">
                        {{ __('RRSet Record Values') }} ({{ records.length }})
                    </span>

                    <UiButton
                        v-if="canAddMultipleRecords"
                        type="button"
                        variant="secondary"
                        size="sm"
                        @click="addRecordRow"
                    >
                        <Plus class="w-3.5 h-3.5" />
                        <span>{{ __('Add Round-Robin Value to RRSet') }}</span>
                    </UiButton>
                </div>

                <div class="space-y-3 max-h-96 overflow-y-auto pr-1">
                    <div
                        v-for="(rec, idx) in records"
                        :key="idx"
                        class="p-3.5 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle space-y-3"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-theme-text-secondary font-mono">
                                {{ type }} #{{ idx + 1 }}
                            </span>
                            <div class="flex items-center gap-3">
                                <!-- Per-Record Disabled Toggle -->
                                <label class="inline-flex items-center gap-1.5 text-xs font-medium cursor-pointer">
                                    <input
                                        v-model="rec.disabled"
                                        type="checkbox"
                                        class="rounded border-theme-border text-theme-warning focus:ring-theme-warning"
                                    />
                                    <UiBadge :variant="rec.disabled ? 'warning' : 'success'">
                                        <PauseCircle v-if="rec.disabled" class="w-3 h-3" />
                                        <CheckCircle2 v-else class="w-3 h-3" />
                                        <span>{{ rec.disabled ? __('Disabled (Staged)') : __('Active') }}</span>
                                    </UiBadge>
                                </label>

                                <UiButton
                                    v-if="records.length > 1"
                                    type="button"
                                    variant="danger"
                                    size="sm"
                                    @click="removeRecordRow(idx)"
                                >
                                    <Trash2 class="w-3.5 h-3.5" />
                                </UiButton>
                            </div>
                        </div>

                        <!-- MX Structured Inputs (Priority + Mail Server) -->
                        <div v-if="type === 'MX'" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
                            <div class="sm:col-span-4">
                                <UiInput
                                    :id="`mx-priority-${idx}`"
                                    v-model="rec.priority"
                                    type="number"
                                    :label="__('Priority (0–65535)')"
                                    placeholder="10"
                                    required
                                />
                            </div>
                            <div class="sm:col-span-8">
                                <UiInput
                                    :id="`mx-target-${idx}`"
                                    v-model="rec.target"
                                    :label="__('Mail Server Hostname (FQDN or . for Null MX)')"
                                    placeholder="mail.example.com."
                                    required
                                />
                            </div>
                        </div>

                        <!-- SRV Structured Inputs (Priority, Weight, Port, Target) -->
                        <div v-else-if="type === 'SRV'" class="space-y-3">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <UiInput
                                    :id="`srv-priority-${idx}`"
                                    v-model="rec.priority"
                                    type="number"
                                    :label="__('Priority (0–65535)')"
                                    placeholder="10"
                                    required
                                />
                                <UiInput
                                    :id="`srv-weight-${idx}`"
                                    v-model="rec.weight"
                                    type="number"
                                    :label="__('Weight (0–65535)')"
                                    placeholder="20"
                                    required
                                />
                                <UiInput
                                    :id="`srv-port-${idx}`"
                                    v-model="rec.port"
                                    type="number"
                                    :label="__('TCP/UDP Port (0–65535)')"
                                    placeholder="5060"
                                    required
                                />
                            </div>
                            <UiInput
                                :id="`srv-target-${idx}`"
                                v-model="rec.target"
                                :label="__('Target Server Hostname (FQDN)')"
                                placeholder="sip.example.com."
                                required
                            />
                        </div>

                        <!-- CAA Structured Inputs (Flags, Tag, Value) -->
                        <div v-else-if="type === 'CAA'" class="space-y-3">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <UiSelect
                                    :id="`caa-flags-${idx}`"
                                    v-model="rec.flags"
                                    :label="__('Flags')"
                                    :options="caaFlagOptions"
                                />
                                <UiSelect
                                    :id="`caa-tag-${idx}`"
                                    v-model="rec.tag"
                                    :label="__('Property Tag')"
                                    :options="caaTagOptions"
                                />
                            </div>
                            <UiInput
                                :id="`caa-value-${idx}`"
                                v-model="rec.value"
                                :label="__('CA Domain or Reporting URI (Auto-Quoted)')"
                                placeholder="letsencrypt.org"
                                required
                            />
                        </div>

                        <!-- TXT Guided Editor with SPF/DKIM/DMARC/ACME Helpers & 255-byte Auto-Split Preview -->
                        <div v-else-if="type === 'TXT'" class="space-y-2.5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-xs font-medium text-theme-text-secondary flex items-center gap-1">
                                    <Sparkles class="w-3.5 h-3.5 text-theme-accent" />
                                    <span>{{ __('Quick TXT Presets:') }}</span>
                                </span>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <button
                                        type="button"
                                        class="px-2 py-0.5 rounded-theme-sm text-[11px] font-semibold bg-theme-bg-card border border-theme-border text-theme-text-secondary hover:text-theme-text-primary cursor-pointer"
                                        @click="applyTxtPreset(idx, 'spf')"
                                    >
                                        SPF
                                    </button>
                                    <button
                                        type="button"
                                        class="px-2 py-0.5 rounded-theme-sm text-[11px] font-semibold bg-theme-bg-card border border-theme-border text-theme-text-secondary hover:text-theme-text-primary cursor-pointer"
                                        @click="applyTxtPreset(idx, 'dmarc')"
                                    >
                                        DMARC
                                    </button>
                                    <button
                                        type="button"
                                        class="px-2 py-0.5 rounded-theme-sm text-[11px] font-semibold bg-theme-bg-card border border-theme-border text-theme-text-secondary hover:text-theme-text-primary cursor-pointer"
                                        @click="applyTxtPreset(idx, 'dkim')"
                                    >
                                        2048-bit DKIM
                                    </button>
                                    <button
                                        type="button"
                                        class="px-2 py-0.5 rounded-theme-sm text-[11px] font-semibold bg-theme-bg-card border border-theme-border text-theme-text-secondary hover:text-theme-text-primary cursor-pointer"
                                        @click="applyTxtPreset(idx, 'acme')"
                                    >
                                        ACME Challenge
                                    </button>
                                </div>
                            </div>

                            <textarea
                                :id="`txt-content-${idx}`"
                                v-model="rec.content"
                                rows="3"
                                class="w-full rounded-theme-md border border-theme-border bg-theme-bg-card px-3 py-2 text-xs font-mono text-theme-text-primary focus:outline-none focus:ring-2 focus:ring-theme-accent"
                                :placeholder="__('Enter raw TXT value (automatically wrapped in double quotes and chunked at 255 bytes)...')"
                                required
                            />

                            <div class="flex flex-wrap items-center justify-between gap-2 text-[11px] text-theme-text-muted">
                                <span>
                                    {{ __('Auto-wrapped in quotes. Strings > 255 bytes split into space-separated 255-byte chunks.') }}
                                </span>
                                <UiBadge :variant="txtChunkCount(rec.content) > 1 ? 'info' : 'neutral'">
                                    <span class="font-mono">
                                        {{ txtByteLength(rec.content) }} {{ __('bytes') }} ({{ txtChunkCount(rec.content) }}
                                        {{ txtChunkCount(rec.content) === 1 ? __('chunk') : __('chunks') }})
                                    </span>
                                </UiBadge>
                            </div>
                        </div>

                        <!-- A / AAAA / CNAME / NS / PTR Single Value Input -->
                        <div v-else>
                            <UiInput
                                :id="`std-content-${idx}`"
                                v-model="rec.content"
                                :label="
                                    type === 'A'
                                        ? __('IPv4 Address')
                                        : type === 'AAAA'
                                          ? __('IPv6 Address')
                                          : type === 'CNAME'
                                            ? __('Canonical Target Hostname (FQDN)')
                                            : type === 'NS'
                                              ? __('Authoritative Nameserver Hostname (FQDN)')
                                              : __('Target Hostname (FQDN)')
                                "
                                :placeholder="
                                    type === 'A'
                                        ? '203.0.113.10'
                                        : type === 'AAAA'
                                          ? '2001:db8::10'
                                          : 'target.example.com.'
                                "
                                required
                            />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Per-RRSet Comment (REPLACE only; EXTEND requires empty comments per PowerDNS API invariant) -->
            <div v-if="changetype === 'REPLACE'" class="space-y-1.5">
                <UiInput
                    id="pdns-rrset-comment"
                    v-model="comment"
                    :label="__('RRSet Comment (Optional)')"
                    :placeholder="__('e.g., Primary web load balancer or ticket reference')"
                />
                <p class="text-[11px] text-theme-text-muted flex items-center gap-1">
                    <MessageSquare class="w-3 h-3 shrink-0" />
                    <span>{{ __('Stored alongside the RRSet in PowerDNS and searchable via Global Record Search.') }}</span>
                </p>
            </div>
            <div v-else class="p-2.5 rounded-theme-md bg-theme-bg-surface border border-theme-border-subtle text-xs text-theme-text-muted flex items-center gap-2">
                <Info class="w-4 h-4 text-theme-accent shrink-0" />
                <span>
                    {{ __('PowerDNS EXTEND mode appends a single record to the RRSet and enforces empty comments per API specification.') }}
                </span>
            </div>

            <!-- Footer Actions: Stage in Bulk Draft OR Save Immediately -->
            <div class="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-theme-border-subtle">
                <UiButton
                    type="button"
                    variant="secondary"
                    :disabled="submitting"
                    @click="emit('close')"
                >
                    {{ __('Cancel') }}
                </UiButton>

                <div class="flex flex-wrap items-center gap-2.5">
                    <UiButton
                        type="button"
                        variant="secondary"
                        :disabled="submitting"
                        @click="handleStageDraft"
                    >
                        <Layers class="w-4 h-4" />
                        <span>{{ __('Queue in Bulk Draft') }}</span>
                    </UiButton>

                    <UiButton
                        type="submit"
                        variant="primary"
                        :loading="submitting"
                        :disabled="submitting"
                    >
                        <Save class="w-4 h-4" />
                        <span>{{ submitting ? __('Applying PATCH...') : __('Save Immediately (PATCH)') }}</span>
                    </UiButton>
                </div>
            </div>
        </form>
    </UiModal>
</template>
