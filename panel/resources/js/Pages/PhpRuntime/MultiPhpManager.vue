<script setup lang="ts">
import { shallowRef, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { FileCode, Globe, Download } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiButton from '@shared/Ui/UiButton.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiDataTable from '@shared/Ui/UiDataTable.vue';

const props = defineProps({
    domains: Array,
    installedVersions: Array,
});

const page = usePage();
const supportedVersions = computed(() => (page.props.php as { supported_versions?: string[] })?.supported_versions || []);

const uninstalledVersions = computed(() => {
    const installed = new Set((props.installedVersions || []).map((v: any) => String(v.version)));
    return supportedVersions.value.filter((v) => !installed.has(v));
});

const versionToInstall = shallowRef(uninstalledVersions.value[0] || '');
const installingPhp = shallowRef(false);

const formatPhpVersion = (ver: string) => {
    if (!ver || ver.length < 2) return ver;
    return `${ver[0]}.${ver.slice(1)}`;
};

const installPhpVersion = async (ver: string) => {
    if (!ver) return;
    if (await confirmDialog({
        title: 'Install PHP Version',
        message: `Install lsphp${ver} (PHP ${formatPhpVersion(ver)}) from the official LiteSpeed repository?`,
        confirmText: 'Install',
    })) {
        installingPhp.value = true;
        router.post('/php/versions/install', { version: ver }, {
            onFinish: () => {
                installingPhp.value = false;
            },
        });
    }
};

const switchSingle = (domainId: number | string, ver: string) => {
    router.post(`/domains/${domainId}/php`, { php_version: ver });
};
</script>

<template>
    <Head :title="__('MultiPHP Manager')" />

    <div class="space-y-6">
        <!-- Header -->
        <UiPageHeader
            :title="__('MultiPHP Manager')"
            subtitle="Assign dedicated SuEXEC PHP processor engines to your virtual hosts or install new PHP versions."
        >
            <template #icon>
                <FileCode class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Install PHP Version Toolbar -->
        <div
            v-if="uninstalledVersions.length > 0"
            class="p-4 bg-theme-bg-card border border-theme-border rounded-theme-lg flex flex-wrap items-center justify-between gap-4 shadow-xs"
        >
            <div class="text-xs text-theme-text-secondary font-semibold theme-ui-control">
                {{ __('Install New PHP Version') }}
            </div>

            <div class="flex items-center gap-3">
                <select
                    v-model="versionToInstall"
                    class="px-3 py-1.5 bg-theme-bg-input border border-theme-border rounded-theme-md text-xs text-theme-text-primary focus:outline-none focus:border-theme-accent"
                >
                    <option v-for="ver in uninstalledVersions" :key="ver" :value="ver">
                        PHP {{ formatPhpVersion(ver) }} (lsphp{{ ver }})
                    </option>
                </select>

                <UiButton
                    variant="primary"
                    size="sm"
                    @click="installPhpVersion(versionToInstall)"
                    :disabled="!versionToInstall || installingPhp"
                    :loading="installingPhp"
                >
                    <Download v-if="!installingPhp" class="w-3.5 h-3.5" />
                    <span>{{ installingPhp ? 'Queuing...' : 'Install PHP' }}</span>
                </UiButton>
            </div>
        </div>

        <!-- Table -->
        <UiDataTable
            :headers="[
                __('Domain'),
                __('Account User'),
                __('Current PHP Engine'),
                { label: __('Change PHP'), class: 'text-right' },
            ]"
            :items-count="domains?.length || 0"
            empty-message="No domains configured."
        >
            <tr v-for="dom in (domains as any[])" :key="dom.id" class="hover:bg-theme-bg-surface/40 transition-colors">
                <td class="px-4 py-3.5">
                    <div class="font-semibold text-theme-text-primary flex items-center gap-2">
                        <Globe class="w-4 h-4 text-theme-success" />
                        <span>{{ dom.domain }}</span>
                    </div>
                </td>
                <td class="px-4 py-3.5 text-xs text-theme-text-secondary">
                    {{ dom.user?.username || '—' }}
                </td>
                <td class="px-4 py-3.5">
                    <UiBadge variant="primary">
                        lsphp{{ dom.php_version }}
                    </UiBadge>
                </td>
                <td class="px-4 py-3.5 text-right">
                    <select
                        :value="dom.php_version"
                        @change="switchSingle(dom.id, ($event.target as HTMLSelectElement).value)"
                        class="px-2.5 py-1 rounded-theme-sm bg-theme-bg-input border border-theme-border text-theme-text-primary text-xs focus:outline-none focus:border-theme-accent"
                    >
                        <option v-for="v in (installedVersions as any[])" :key="v.version" :value="v.version">
                            PHP {{ v.version }}
                        </option>
                    </select>
                </td>
            </tr>
        </UiDataTable>
    </div>
</template>
