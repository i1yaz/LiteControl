<script setup lang="ts">
import { computed, shallowRef } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { FileCode, CheckCircle2, Circle, Search, Download, Trash2, Package } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';

const props = defineProps({
    currentVersion: String,
    extensionsData: Object,
    loadedModules: {
        type: Array,
        default: () => [],
    },
    packages: {
        type: Array,
        default: () => [],
    },
    installedVersions: Array,
});

const page = usePage();
const isAdmin = page.props.auth?.user?.role === 'admin';

const activeTab = shallowRef<'packages' | 'ini' | 'modules'>('packages');
const searchQuery = shallowRef('');
const processingPkg = shallowRef<string | null>(null);

const activeExtensions = computed(() => props.extensionsData?.installed || []);
const availableExtensions = computed(() => props.extensionsData?.available || []);

const filteredPackages = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    const list = (props.packages || []) as Array<{ name: string; package?: string; installed: boolean; description?: string }>;
    if (!q) return list;
    return list.filter((p) =>
        (p.name || '').toLowerCase().includes(q) ||
        (p.package || '').toLowerCase().includes(q) ||
        (p.description || '').toLowerCase().includes(q)
    );
});

const filteredModules = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    const list = (props.loadedModules || []) as string[];
    if (!q) return list;
    return list.filter((m) => String(m).toLowerCase().includes(q));
});

const switchVersion = (ver: string) => {
    router.get('/php/extensions', { version: ver });
};

const toggleExt = (ext: string, currentActive: boolean) => {
    if (!isAdmin) return;
    const action = currentActive ? 'disable' : 'enable';
    router.post('/php/extensions/toggle', {
        version: props.currentVersion,
        extension: ext,
        action: action,
    });
};

const installPackage = async (extName: string) => {
    if (!isAdmin) return;
    if (await confirmDialog({
        title: 'Install PHP Extension',
        message: `Install lsphp${props.currentVersion}-${extName} and restart LiteSpeed?`,
        confirmText: 'Install',
    })) {
        processingPkg.value = extName;
        router.post('/php/extensions/install', {
            version: props.currentVersion,
            extension: extName,
        }, {
            onFinish: () => {
                processingPkg.value = null;
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
        processingPkg.value = extName;
        router.post('/php/extensions/uninstall', {
            version: props.currentVersion,
            extension: extName,
        }, {
            onFinish: () => {
                processingPkg.value = null;
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
                    <span>{{ __('PHP Extensions & Modules') }}</span>
                </h1>
                <p class="text-xs sm:text-sm text-slate-400 mt-1">
                    Install or uninstall PHP packages, toggle INI extensions, and inspect loaded modules for PHP {{ currentVersion }}.
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

        <!-- Section Tabs + Search -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-900 border border-slate-800 rounded-xl p-3">
            <div class="flex items-center gap-1.5">
                <button
                    type="button"
                    @click="activeTab = 'packages'"
                    :class="[
                        'px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors cursor-pointer inline-flex items-center gap-1.5',
                        activeTab === 'packages'
                            ? 'bg-purple-600 text-white shadow-sm'
                            : 'text-slate-400 hover:text-white hover:bg-slate-800'
                    ]"
                >
                    <Package class="w-3.5 h-3.5" />
                    <span>{{ __('Packages') }} ({{ packages.length }})</span>
                </button>
                <button
                    type="button"
                    @click="activeTab = 'ini'"
                    :class="[
                        'px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors cursor-pointer inline-flex items-center gap-1.5',
                        activeTab === 'ini'
                            ? 'bg-purple-600 text-white shadow-sm'
                            : 'text-slate-400 hover:text-white hover:bg-slate-800'
                    ]"
                >
                    <Circle class="w-3.5 h-3.5" />
                    <span>{{ __('INI Extensions') }} ({{ activeExtensions.length + availableExtensions.length }})</span>
                </button>
                <button
                    type="button"
                    @click="activeTab = 'modules'"
                    :class="[
                        'px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors cursor-pointer inline-flex items-center gap-1.5',
                        activeTab === 'modules'
                            ? 'bg-purple-600 text-white shadow-sm'
                            : 'text-slate-400 hover:text-white hover:bg-slate-800'
                    ]"
                >
                    <CheckCircle2 class="w-3.5 h-3.5" />
                    <span>{{ __('Loaded Modules') }} ({{ loadedModules.length }})</span>
                </button>
            </div>

            <div v-if="activeTab !== 'ini'" class="relative w-full sm:w-72">
                <Search class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input
                    v-model="searchQuery"
                    type="text"
                    :placeholder="activeTab === 'packages' ? __('Search packages (e.g. imagick, redis)...') : __('Search loaded modules...')"
                    class="w-full pl-9 pr-4 py-1.5 bg-slate-950/70 border border-slate-800 rounded-lg text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-purple-500 transition-colors"
                />
            </div>
        </div>

        <!-- Tab 1: Packages (Install / Uninstall) -->
        <div v-if="activeTab === 'packages'" class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-300">
                    <thead class="bg-slate-950/50 text-xs font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-800">
                        <tr>
                            <th class="px-5 py-3">{{ __('Extension') }}</th>
                            <th class="px-5 py-3">{{ __('Package') }}</th>
                            <th class="px-5 py-3">{{ __('Status') }}</th>
                            <th v-if="isAdmin" class="px-5 py-3 text-right">{{ __('Action') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        <tr v-for="pkg in filteredPackages" :key="pkg.name" class="hover:bg-slate-800/40 transition-colors">
                            <td class="px-5 py-3 font-semibold text-white">
                                {{ pkg.name }}
                            </td>
                            <td class="px-5 py-3 text-xs font-mono text-slate-400">
                                {{ pkg.package || `lsphp${currentVersion}-${pkg.name}` }}
                            </td>
                            <td class="px-5 py-3">
                                <span
                                    :class="[
                                        'px-2.5 py-0.5 rounded-full text-xs font-semibold border',
                                        pkg.installed
                                            ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/20'
                                            : 'bg-slate-800 text-slate-400 border-slate-700'
                                    ]"
                                >
                                    {{ pkg.installed ? __('Installed') : __('Not Installed') }}
                                </span>
                            </td>
                            <td v-if="isAdmin" class="px-5 py-3 text-right">
                                <button
                                    v-if="!pkg.installed"
                                    type="button"
                                    @click="installPackage(pkg.name)"
                                    :disabled="processingPkg === pkg.name"
                                    class="px-3 py-1 rounded-lg bg-purple-600 hover:bg-purple-500 disabled:opacity-50 text-xs font-semibold text-white transition-colors inline-flex items-center gap-1.5 cursor-pointer"
                                >
                                    <Download class="w-3.5 h-3.5" />
                                    <span>{{ processingPkg === pkg.name ? __('Installing...') : __('Install') }}</span>
                                </button>
                                <button
                                    v-else
                                    type="button"
                                    @click="uninstallPackage(pkg.name)"
                                    :disabled="processingPkg === pkg.name"
                                    class="px-3 py-1 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 border border-rose-500/20 disabled:opacity-50 text-xs font-semibold transition-colors inline-flex items-center gap-1.5 cursor-pointer"
                                >
                                    <Trash2 class="w-3.5 h-3.5" />
                                    <span>{{ processingPkg === pkg.name ? __('Removing...') : __('Uninstall') }}</span>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="filteredPackages.length === 0">
                            <td :colspan="isAdmin ? 4 : 3" class="px-5 py-8 text-center text-slate-500 text-sm">
                                {{ __('No extension packages found for this PHP version.') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tab 2: INI Extensions (Enable / Disable) -->
        <div v-else-if="activeTab === 'ini'" class="space-y-6">
            <div class="space-y-4">
                <h3 class="text-sm font-semibold text-slate-300 uppercase tracking-wider flex items-center gap-2">
                    <CheckCircle2 class="w-4 h-4 text-emerald-400" />
                    <span>Active Extensions ({{ activeExtensions.length }})</span>
                </h3>

                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                    <div
                        v-for="ext in activeExtensions"
                        :key="ext"
                        class="p-3 bg-slate-900 border border-slate-800 rounded-xl flex items-center justify-between hover:border-slate-700 transition-colors"
                    >
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                            <span class="text-xs font-semibold text-white">{{ ext }}</span>
                        </div>
                        <button
                            v-if="isAdmin"
                            @click="toggleExt(ext, true)"
                            :title="__('Disable Extension')"
                            class="text-[10px] text-slate-400 hover:text-rose-400 transition-colors cursor-pointer"
                        >
                            Disable
                        </button>
                    </div>
                </div>
            </div>

            <div class="space-y-4 pt-4 border-t border-slate-800">
                <h3 class="text-sm font-semibold text-slate-400 uppercase tracking-wider flex items-center gap-2">
                    <Circle class="w-4 h-4 text-slate-500" />
                    <span>Available / Inactive Extensions ({{ availableExtensions.length }})</span>
                </h3>

                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                    <div
                        v-for="ext in availableExtensions"
                        :key="ext"
                        class="p-3 bg-slate-950/50 border border-slate-800/60 rounded-xl flex items-center justify-between hover:border-slate-700 transition-colors"
                    >
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-slate-600"></span>
                            <span class="text-xs font-medium text-slate-400">{{ ext }}</span>
                        </div>
                        <button
                            v-if="isAdmin"
                            @click="toggleExt(ext, false)"
                            :title="__('Enable Extension')"
                            class="text-[10px] text-purple-400 hover:text-purple-300 transition-colors cursor-pointer"
                        >
                            Enable
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 3: Loaded Modules (php -m) -->
        <div v-else class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
            <div
                v-for="mod in filteredModules"
                :key="mod"
                class="p-3 bg-slate-900 border border-slate-800 rounded-xl flex items-center gap-2 hover:border-slate-700 transition-colors shadow-sm"
            >
                <CheckCircle2 class="w-4 h-4 text-emerald-400 shrink-0" />
                <span class="text-xs font-semibold text-slate-200 truncate">{{ mod }}</span>
            </div>
            <div v-if="filteredModules.length === 0" class="col-span-full py-8 text-center text-slate-500 text-sm">
                {{ __('No loaded modules found.') }}
            </div>
        </div>
    </div>
</template>
