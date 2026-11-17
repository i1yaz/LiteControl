<script setup lang="ts">
import { computed, shallowRef } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { FileCode, Search, Download, Trash2 } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';

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
const isAdmin = page.props.auth?.user?.role === 'admin';

const searchQuery = shallowRef('');
const processingExt = shallowRef<string | null>(null);

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
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                    <FileCode class="w-6 h-6 text-purple-400" />
                    <span>{{ __('PHP Extensions') }}</span>
                </h1>
                <p class="text-xs sm:text-sm text-slate-400 mt-1">
                    Install or uninstall PHP extensions for PHP {{ currentVersion }}.
                </p>
            </div>

            <!-- Version Selector Tabs -->
            <div class="flex items-center gap-1.5 p-1 bg-slate-900 border border-slate-800 rounded-xl overflow-x-auto">
                <button
                    v-for="v in installedVersions"
                    :key="v.version"
                    @click="switchVersion(v.version)"
                    :class="[
                        'px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors cursor-pointer',
                        currentVersion === v.version
                            ? 'bg-purple-600 text-white shadow-sm'
                            : 'text-slate-400 hover:text-white hover:bg-slate-800'
                    ]"
                >
                    PHP {{ v.version }}
                </button>
            </div>
        </div>

        <!-- Search Bar -->
        <div class="flex items-center justify-between gap-3 bg-slate-900 border border-slate-800 rounded-xl p-3">
            <div class="relative flex-1">
                <Search class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input
                    v-model="searchQuery"
                    type="text"
                    :placeholder="__('Search extensions (e.g. imagick, redis, intl, opcache)...')"
                    class="w-full pl-9 pr-4 py-1.5 bg-slate-950/70 border border-slate-800 rounded-lg text-sm text-slate-200 placeholder-slate-500 focus:outline-none focus:border-purple-500 transition-colors"
                />
            </div>
            <span class="text-xs text-slate-400 px-2 font-mono">
                {{ filteredRows.length }} extensions
            </span>
        </div>

        <!-- Unified Extensions Table -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-300">
                    <thead class="bg-slate-950/50 text-xs font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-800">
                        <tr>
                            <th class="px-5 py-3">{{ __('Extension') }}</th>
                            <th class="px-5 py-3">{{ __('Package') }}</th>
                            <th class="px-5 py-3">{{ __('Status') }}</th>
                            <th v-if="isAdmin" class="px-5 py-3 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        <tr v-for="row in filteredRows" :key="row.name" class="hover:bg-slate-800/40 transition-colors">
                            <td class="px-5 py-3 font-semibold text-white">
                                {{ row.name }}
                            </td>
                            <td class="px-5 py-3 text-xs font-mono text-slate-400">
                                {{ row.package }}
                            </td>
                            <td class="px-5 py-3">
                                <span
                                    v-if="!row.installed"
                                    class="px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-slate-800 text-slate-400 border-slate-700"
                                >
                                    {{ __('Not Installed') }}
                                </span>
                                <span
                                    v-else-if="row.name === 'common'"
                                    class="px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-emerald-500/10 text-emerald-300 border-emerald-500/20"
                                >
                                    {{ __('Installed') }}
                                </span>
                                <span
                                    v-else-if="row.enabled"
                                    class="px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-emerald-500/10 text-emerald-300 border-emerald-500/20"
                                >
                                    {{ __('Installed · Enabled') }}
                                </span>
                                <span
                                    v-else
                                    class="px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-amber-500/10 text-amber-300 border-amber-500/20"
                                >
                                    {{ __('Installed · Disabled') }}
                                </span>
                            </td>
                            <td v-if="isAdmin" class="px-5 py-3 text-right">
                                <span
                                    v-if="row.name === 'common'"
                                    class="px-2.5 py-1 rounded-lg bg-slate-800/70 text-slate-400 border border-slate-700/60 text-xs font-medium"
                                >
                                    {{ __('Core · Readonly') }}
                                </span>
                                <div v-else class="inline-flex items-center gap-2">
                                    <button
                                        v-if="!row.installed"
                                        type="button"
                                        @click="installPackage(row.name)"
                                        :disabled="processingExt === row.name"
                                        class="px-3 py-1 rounded-lg bg-purple-600 hover:bg-purple-500 disabled:opacity-50 text-xs font-semibold text-white transition-colors inline-flex items-center gap-1.5 cursor-pointer"
                                    >
                                        <Download class="w-3.5 h-3.5" />
                                        <span>{{ processingExt === row.name ? __('Installing...') : __('Install') }}</span>
                                    </button>
                                    <template v-else>
                                        <button
                                            type="button"
                                            @click="uninstallPackage(row.name)"
                                            :disabled="processingExt === row.name"
                                            class="px-3 py-1 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 border border-rose-500/20 disabled:opacity-50 text-xs font-semibold transition-colors inline-flex items-center gap-1.5 cursor-pointer"
                                        >
                                            <Trash2 class="w-3.5 h-3.5" />
                                            <span>{{ __('Uninstall') }}</span>
                                        </button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="filteredRows.length === 0">
                            <td :colspan="isAdmin ? 4 : 3" class="px-5 py-8 text-center text-slate-500 text-sm">
                                {{ __('No PHP extensions found for this version.') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
