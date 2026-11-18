<script setup lang="ts">
import { computed, shallowRef } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { FileCode, Search, Download, Trash2 } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';
import UiPageHeader from '@shared/Ui/UiPageHeader.vue';
import UiTabs from '@shared/Ui/UiTabs.vue';
import UiDataTable from '@shared/Ui/UiDataTable.vue';
import UiBadge from '@shared/Ui/UiBadge.vue';
import UiButton from '@shared/Ui/UiButton.vue';

const props = defineProps({
    currentVersion: String,
    extensionsData: Object,
    packages: {
        type: Array,
        default: () => [],
    },
    installedVersions: Array,
});

const page = usePage();
const isAdmin = (page.props.auth as any)?.user?.role === 'admin';

const searchQuery = shallowRef('');
const processingExt = shallowRef<string | null>(null);

const versionTabs = computed(() =>
    ((props.installedVersions || []) as any[]).map((v) => ({
        id: String(v.version),
        label: `PHP ${v.version}`,
    }))
);

const tableHeaders = computed(() => {
    const base: Array<string | { label: string; class: string }> = [
        'Extension',
        'Package',
        'Status',
    ];
    if (isAdmin) {
        base.push({ label: 'Actions', class: 'text-right' });
    }
    return base;
});

const enabledSet = computed(() => {
    const data = props.extensionsData as any;
    if (Array.isArray(data)) {
        return new Set(
            data.filter((e) => e.enabled).map((e) => String(e.extension).toLowerCase())
        );
    }
    return new Set((data?.installed || []).map((e: string) => String(e).toLowerCase()));
});

const unifiedRows = computed(() => {
    const map = new Map<string, { name: string; package: string; installed: boolean; enabled: boolean }>();

    for (const pkg of (props.packages || []) as Array<{ name: string; package?: string; installed: boolean }>) {
        const key = pkg.name.toLowerCase();
        map.set(key, {
            name: pkg.name,
            package: pkg.package || `lsphp${props.currentVersion}-${pkg.name}`,
            installed: Boolean(pkg.installed),
            enabled: enabledSet.value.has(key),
        });
    }

    for (const ext of (props.extensionsData?.installed || []) as string[]) {
        const key = ext.toLowerCase();
        if (!map.has(key)) {
            map.set(key, {
                name: ext,
                package: `lsphp${props.currentVersion}-${ext}`,
                installed: true,
                enabled: true,
            });
        }
    }

    for (const ext of (props.extensionsData?.available || []) as string[]) {
        const key = ext.toLowerCase();
        if (!map.has(key)) {
            map.set(key, {
                name: ext,
                package: `lsphp${props.currentVersion}-${ext}`,
                installed: true,
                enabled: false,
            });
        }
    }

    return Array.from(map.values()).sort((a, b) => a.name.localeCompare(b.name));
});

const filteredRows = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return unifiedRows.value;
    return unifiedRows.value.filter((r) =>
        r.name.toLowerCase().includes(q) || r.package.toLowerCase().includes(q)
    );
});

const switchVersion = (ver: string) => {
    router.get('/php/extensions', { version: ver });
};

const installPackage = async (extName: string) => {
    if (!isAdmin) return;
    if (await confirmDialog({
        title: 'Install PHP Extension',
        message: `Install lsphp${props.currentVersion}-${extName} and restart LiteSpeed?`,
        confirmText: 'Install',
    })) {
        processingExt.value = extName;
        router.post('/php/extensions/install', {
            version: props.currentVersion,
            extension: extName,
        }, {
            onFinish: () => {
                processingExt.value = null;
            },
        });
    }
};

const uninstallPackage = async (extName: string) => {
    if (!isAdmin) return;
    if (await confirmDialog({
        title: 'Uninstall PHP Extension',
        message: `Uninstall lsphp${props.currentVersion}-${extName} and restart LiteSpeed?`,
        confirmText: 'Uninstall',
        variant: 'danger',
    })) {
        processingExt.value = extName;
        router.post('/php/extensions/uninstall', {
            version: props.currentVersion,
            extension: extName,
        }, {
            onFinish: () => {
                processingExt.value = null;
            },
        });
    }
};
</script>

<template>
    <Head :title="__('PHP Extensions')" />

    <div class="space-y-6">
        <!-- Header -->
        <UiPageHeader
            :title="__('PHP Extensions')"
            :subtitle="`Install or uninstall PHP extensions for PHP ${currentVersion}.`"
        >
            <template #icon>
                <FileCode class="w-6 h-6 text-theme-accent" />
            </template>
        </UiPageHeader>

        <!-- Version Selector Tabs -->
        <UiTabs
            :model-value="String(currentVersion || '')"
            :tabs="versionTabs"
            @select="switchVersion"
        />

        <!-- Search Bar -->
        <div class="flex items-center justify-between gap-3 bg-theme-bg-card border border-theme-border rounded-theme-lg p-3 shadow-xs">
            <div class="relative flex-1">
                <Search class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-theme-text-muted" />
                <input
                    v-model="searchQuery"
                    type="text"
                    :placeholder="__('Search extensions (e.g. imagick, redis, intl, opcache)...')"
                    class="w-full pl-9 pr-4 py-1.5 bg-theme-bg-input border border-theme-border rounded-theme-md text-sm text-theme-text-primary placeholder-theme-text-muted focus:outline-none focus:border-theme-accent transition-colors"
                />
            </div>
            <span class="text-xs text-theme-text-secondary px-2 font-mono">
                {{ filteredRows.length }} extensions
            </span>
        </div>

        <!-- Unified Extensions Table -->
        <UiDataTable
            :headers="tableHeaders"
            :items-count="filteredRows.length"
            :empty-message="__('No PHP extensions found for this version.')"
        >
            <tr v-for="row in filteredRows" :key="row.name" class="hover:bg-theme-bg-surface/40 transition-colors">
                <td class="px-4 py-3 font-semibold text-theme-text-primary">
                    {{ row.name }}
                </td>
                <td class="px-4 py-3 text-xs font-mono text-theme-text-secondary">
                    {{ row.package }}
                </td>
                <td class="px-4 py-3">
                    <UiBadge v-if="!row.installed" variant="neutral">
                        {{ __('Not Installed') }}
                    </UiBadge>
                    <UiBadge v-else variant="success">
                        {{ __('Installed') }}
                    </UiBadge>
                </td>
                <td v-if="isAdmin" class="px-4 py-3 text-right">
                    <UiBadge v-if="row.name === 'common'" variant="neutral">
                        {{ __('Core · Readonly') }}
                    </UiBadge>
                    <div v-else class="inline-flex items-center gap-2">
                        <UiButton
                            v-if="!row.installed"
                            variant="primary"
                            size="xs"
                            @click="installPackage(row.name)"
                            :disabled="processingExt === row.name"
                            :loading="processingExt === row.name"
                        >
                            <Download v-if="processingExt !== row.name" class="w-3.5 h-3.5" />
                            <span>{{ processingExt === row.name ? __('Installing...') : __('Install') }}</span>
                        </UiButton>
                        <template v-else>
                            <UiButton
                                variant="danger-soft"
                                size="xs"
                                @click="uninstallPackage(row.name)"
                                :disabled="processingExt === row.name"
                            >
                                <Trash2 class="w-3.5 h-3.5" />
                                <span>{{ __('Uninstall') }}</span>
                            </UiButton>
                        </template>
                    </div>
                </td>
            </tr>
        </UiDataTable>
    </div>
</template>
