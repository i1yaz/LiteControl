<script setup lang="ts">
import { shallowRef, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { FileCode, Globe, Download } from '@lucide/vue';
import { confirmDialog } from '@shared/Composables/useDialog';

const props = defineProps({
    domains: Array,
    installedVersions: Array,
});

const page = usePage();
const supportedVersions = computed(() => (page.props.php as { supported_versions?: string[] })?.supported_versions || []);

const uninstalledVersions = computed(() => {
    const installed = new Set((props.installedVersions || []).map((v) => String(v.version)));
    return supportedVersions.value.filter((v) => !installed.has(v));
});

const versionToInstall = shallowRef(uninstalledVersions.value[0] || '');
const installingPhp = shallowRef(false);

const formatPhpVersion = (ver) => {
    if (!ver || ver.length < 2) return ver;
    return `${ver[0]}.${ver.slice(1)}`;
};

const installPhpVersion = async (ver) => {
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

const switchSingle = (domainId, ver) => {
    router.post(`/domains/${domainId}/php`, { php_version: ver });
};
</script>

<template>
    <Head :title="__('MultiPHP Manager')" />

    <div class="space-y-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                    <FileCode class="w-6 h-6 text-purple-400" />
                    <span>{{ __('MultiPHP Manager') }}</span>
                </h1>
                <p class="text-xs sm:text-sm text-slate-400 mt-1">
                    Assign dedicated SuEXEC PHP processor engines to your virtual hosts or install new PHP versions.
                </p>
            </div>
        </div>

        <!-- Install PHP Version Toolbar -->
        <div
            v-if="uninstalledVersions.length > 0"
            class="p-4 bg-slate-900 border border-slate-800 rounded-xl flex flex-wrap items-center justify-between gap-4 shadow-sm"
        >
            <div class="text-xs text-slate-300 font-semibold">
                {{ __('Install New PHP Version') }}
            </div>

            <div class="flex items-center gap-3">
                <select
                    v-model="versionToInstall"
                    class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-lg text-xs text-slate-200 focus:outline-none focus:border-purple-500"
                >
                    <option v-for="ver in uninstalledVersions" :key="ver" :value="ver">
                        PHP {{ formatPhpVersion(ver) }} (lsphp{{ ver }})
                    </option>
                </select>

                <button
                    type="button"
                    @click="installPhpVersion(versionToInstall)"
                    :disabled="!versionToInstall || installingPhp"
                    class="px-4 py-1.5 rounded-lg bg-purple-600 hover:bg-purple-500 disabled:opacity-50 text-xs font-semibold text-white transition-colors shadow-sm inline-flex items-center gap-1.5 cursor-pointer"
                >
                    <Download class="w-3.5 h-3.5" />
                    <span>{{ installingPhp ? 'Queuing...' : 'Install PHP' }}</span>
                </button>
            </div>
        </div>

        <!-- Table -->
        <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-300">
                    <thead class="bg-slate-950/50 text-xs font-semibold text-slate-400 uppercase tracking-wider border-b border-slate-800">
                        <tr>
                            <th class="px-5 py-3">{{ __('Domain') }}</th>
                            <th class="px-5 py-3">{{ __('Account User') }}</th>
                            <th class="px-5 py-3">{{ __('Current PHP Engine') }}</th>
                            <th class="px-5 py-3 text-right">{{ __('Change PHP') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60">
                        <tr v-for="dom in domains" :key="dom.id" class="hover:bg-slate-800/40 transition-colors">
                            <td class="px-5 py-3.5">
                                <div class="font-semibold text-white flex items-center gap-2">
                                    <Globe class="w-4 h-4 text-emerald-400" />
                                    <span>{{ dom.domain }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3.5 text-xs text-slate-400">
                                {{ dom.user?.username || '—' }}
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="px-2.5 py-1 rounded-md text-xs font-semibold bg-purple-500/10 text-purple-300 border border-purple-500/20">
                                    lsphp{{ dom.php_version }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <select
                                    :value="dom.php_version"
                                    @change="switchSingle(dom.id, $event.target.value)"
                                    class="px-2.5 py-1 rounded bg-slate-950 border border-slate-800 text-slate-300 text-xs focus:outline-none focus:border-purple-500"
                                >
                                    <option v-for="v in installedVersions" :key="v.version" :value="v.version">
                                        PHP {{ v.version }}
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr v-if="!domains || domains.length === 0">
                            <td colspan="4" class="px-5 py-8 text-center text-slate-500 text-sm">
                                No domains configured.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
