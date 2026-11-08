<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { Sliders, Save, Cpu, Zap, Shield, RefreshCw } from 'lucide-vue-next';

const props = defineProps({
    tuning: Object,
});

const form = useForm({
    maxConnections: props.tuning?.maxConnections ?? 10000,
    maxSSLConnections: props.tuning?.maxSSLConnections ?? 8000,
    connTimeout: props.tuning?.connTimeout ?? 300,
    maxKeepAliveReq: props.tuning?.maxKeepAliveReq ?? 1000,
    keepAliveTimeout: props.tuning?.keepAliveTimeout ?? 5,
    maxReqBodySize: props.tuning?.maxReqBodySize ?? '500M',
    enableGzipCompress: props.tuning?.enableGzipCompress ?? 1,
    gzipCompressLevel: props.tuning?.gzipCompressLevel ?? 6,
    quicEnable: props.tuning?.quicEnable ?? 1,
});

const submit = () => {
    form.post('/configuration/tuning');
};
</script>

<template>
            <Head title="LiteSpeed Tuning" />

        <div class="max-w-4xl mx-auto space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2.5">
                        <Zap class="w-6 h-6 text-amber-400" />
                        <span>OpenLiteSpeed Tuning</span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">
                        High-concurrency connection limits, keep-alive parameters, body size buffers, and QUIC/HTTP3 acceleration.
                    </p>
                </div>
            </div>

            <!-- Form -->
            <form @submit.prevent="submit" class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6">
                <!-- Section 1: Connection Capacity -->
                <div>
                    <h2 class="text-sm font-semibold text-white uppercase tracking-wider text-slate-300">Connection Capacity</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Configure simultaneous concurrent worker connection thresholds.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Max Connections</label>
                        <input
                            v-model.number="form.maxConnections"
                            type="number"
                            min="10"
                            max="100000"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500"
                        />
                        <p class="text-[11px] text-slate-500 mt-1">Maximum concurrent connections allowed (default: 10000).</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Max SSL Connections</label>
                        <input
                            v-model.number="form.maxSSLConnections"
                            type="number"
                            min="10"
                            max="100000"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500"
                        />
                        <p class="text-[11px] text-slate-500 mt-1">Maximum concurrent TLS/HTTPS connections (default: 8000).</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Connection Timeout (seconds)</label>
                        <input
                            v-model.number="form.connTimeout"
                            type="number"
                            min="5"
                            max="3600"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500"
                        />
                        <p class="text-[11px] text-slate-500 mt-1">Idle socket close timeout.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Max Request Body Size</label>
                        <input
                            v-model="form.maxReqBodySize"
                            type="text"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500"
                        />
                        <p class="text-[11px] text-slate-500 mt-1">E.g. 500M or 2047M maximum HTTP POST buffer.</p>
                    </div>
                </div>

                <!-- Section 2: Keep-Alive -->
                <div class="pt-4 border-t border-slate-800">
                    <h2 class="text-sm font-semibold text-white uppercase tracking-wider text-slate-300">Keep-Alive Optimization</h2>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Max Keep-Alive Requests</label>
                        <input
                            v-model.number="form.maxKeepAliveReq"
                            type="number"
                            min="1"
                            max="50000"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Keep-Alive Timeout (seconds)</label>
                        <input
                            v-model.number="form.keepAliveTimeout"
                            type="number"
                            min="1"
                            max="60"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500"
                        />
                    </div>
                </div>

                <!-- Section 3: Compression & Protocols -->
                <div class="pt-4 border-t border-slate-800">
                    <h2 class="text-sm font-semibold text-white uppercase tracking-wider text-slate-300">Compression & HTTP/3 (QUIC)</h2>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">GZIP Compression</label>
                        <select
                            v-model="form.enableGzipCompress"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500"
                        >
                            <option :value="1">Enabled</option>
                            <option :value="0">Disabled</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">GZIP Compression Level</label>
                        <select
                            v-model="form.gzipCompressLevel"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500"
                        >
                            <option v-for="lvl in 9" :key="lvl" :value="lvl">Level {{ lvl }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">HTTP/3 & QUIC Support</label>
                        <select
                            v-model="form.quicEnable"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-amber-500"
                        >
                            <option :value="1">Enabled</option>
                            <option :value="0">Disabled</option>
                        </select>
                    </div>
                </div>

                <!-- Footer Action -->
                <div class="pt-4 border-t border-slate-800 flex items-center justify-between">
                    <span class="text-xs text-slate-400 flex items-center gap-1.5">
                        <RefreshCw class="w-3.5 h-3.5 text-emerald-400" />
                        Safe reload with automatic rollback is applied on save.
                    </span>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-5 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-500 text-white font-medium text-sm flex items-center gap-2 shadow-lg shadow-amber-600/20 disabled:opacity-50 transition-colors"
                    >
                        <Save class="w-4 h-4" />
                        <span>{{ form.processing ? 'Applying...' : 'Apply Tuning' }}</span>
                    </button>
                </div>
            </form>
        </div>
</template>

