<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Globe, Eraser } from '@lucide/vue';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiTabs from '@shared/Ui/UiTabs.vue';
import { useDialog } from '@shared/Composables/useDialog';
import PdnsStatusOverview from '@domains/Dns/Components/PdnsStatusOverview.vue';
import PdnsDaemonConfigForm from '@domains/Dns/Components/PdnsDaemonConfigForm.vue';
import PdnsAutoprimariesTab from '@domains/Dns/Components/PdnsAutoprimariesTab.vue';

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
    autoprimaries: {
        type: Array as () => Array<{
            ip: string;
            nameserver: string;
            display_nameserver?: string;
            is_idn?: boolean;
            account?: string;
        }>,
        default: () => [],
    },
    autoprimariesError: {
        type: String,
        default: null,
    },
});

const { confirm } = useDialog();

const activeSectionTab = ref<'daemon' | 'autoprimaries'>('daemon');
const testingConnection = ref(false);
const rotatingKey = ref(false);
const flushingGlobalCache = ref(false);

const sectionTabs = computed(() => [
    {
        id: 'daemon',
        label: 'Daemon & HTTP API Config',
    },
    {
        id: 'autoprimaries',
        label: `Autoprimaries / Supermasters (${props.autoprimaries.length})`,
    },
]);

const capabilities = computed(() => ({
    has_api_key: Boolean(props.settings?.has_api_key),
    primary_enabled: Boolean(props.settings?.primary_enabled),
    secondary_enabled: Boolean(props.settings?.secondary_enabled),
    autosecondary_enabled: Boolean(props.settings?.autosecondary_enabled),
}));

const form = useForm({
    host: props.settings?.host ?? '127.0.0.1',
    port: props.settings?.port ?? 8081,
    webserver_allow_from: props.settings?.webserver_allow_from ?? '127.0.0.1,::1',
    webserver_password: '',
    primary_enabled: Boolean(props.settings?.primary_enabled ?? true),
    secondary_enabled: Boolean(props.settings?.secondary_enabled ?? false),
    autosecondary_enabled: Boolean(props.settings?.autosecondary_enabled ?? false),
    allow_axfr_ips: props.settings?.allow_axfr_ips ?? '',
    also_notify: props.settings?.also_notify ?? '',
});

const submitConfig = () => {
    form.post('/dns/settings', {
        preserveScroll: true,
        onSuccess: () => {
            form.webserver_password = '';
        },
    });
};

const handleTestConnection = () => {
    testingConnection.value = true;
    router.post(
        '/dns/settings/test-connection',
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                testingConnection.value = false;
            },
        }
    );
};

const handleRotateKey = async () => {
    const confirmed = await confirm({
        title: 'Rotate PowerDNS API Key',
        message:
            'This will generate a new 64-character hex API secret, validate 99-litecontrol.conf with pdns_server --config-check, restart pdns, and probe /api/v1/servers/localhost.\n\nIf the post-restart health probe fails, LiteControl will automatically roll back to the previous configuration.',
        confirmText: 'Rotate API Key',
        cancelText: 'Cancel',
        variant: 'warning',
    });

    if (!confirmed) {
        return;
    }

    rotatingKey.value = true;
    router.post(
        '/dns/settings/rotate-key',
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                rotatingKey.value = false;
            },
        }
    );
};

const handleFlushGlobalCache = () => {
    flushingGlobalCache.value = true;
    router.put(
        '/dns/settings/cache/flush',
        { domain: '.' },
        {
            preserveScroll: true,
            onFinish: () => {
                flushingGlobalCache.value = false;
            },
        }
    );
};
</script>

<template>
    <Head :title="__('PowerDNS Configuration')" />

    <div class="space-y-6">
        <UiPageHeader
            :title="__('PowerDNS Daemon & HTTP API Configuration')"
            :subtitle="__('Manage OS drop-in configuration (99-litecontrol.conf), API key rotation, global cache flush, and Autoprimaries.')"
        >
            <template #icon>
                <Globe class="w-6 h-6 text-theme-accent" />
            </template>
            <template #actions>
                <UiButton
                    type="button"
                    variant="secondary"
                    :loading="flushingGlobalCache"
                    :disabled="flushingGlobalCache || testingConnection || rotatingKey"
                    @click="handleFlushGlobalCache"
                >
                    <Eraser v-if="!flushingGlobalCache" class="w-4 h-4" />
                    <span>{{ flushingGlobalCache ? __('Flushing Cache...') : __('Flush Global Cache') }}</span>
                </UiButton>
            </template>
        </UiPageHeader>

        <PdnsStatusOverview
            :settings="settings"
            :daemon="daemon"
            :api-health="apiHealth"
            :testing-connection="testingConnection"
            :rotating-key="rotatingKey"
            :flushing-cache="flushingGlobalCache"
            @test-connection="handleTestConnection"
            @rotate-key="handleRotateKey"
            @flush-cache="handleFlushGlobalCache"
        />

        <UiTabs
            v-model="activeSectionTab"
            :tabs="sectionTabs"
        />

        <PdnsDaemonConfigForm
            v-if="activeSectionTab === 'daemon'"
            :form="form"
            :has-webserver-password="Boolean(settings?.has_webserver_password)"
            @submit="submitConfig"
        />

        <PdnsAutoprimariesTab
            v-else
            :autoprimaries="autoprimaries"
            :capabilities="capabilities"
            :show-settings-link="false"
            :api-error="autoprimariesError"
        />
    </div>
</template>
