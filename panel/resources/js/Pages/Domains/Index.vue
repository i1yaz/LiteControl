<script setup>
import { shallowRef } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { Globe, Plus, ShieldCheck, ShieldAlert, Folder, Trash2, X } from 'lucide-vue-next';

const props = defineProps({
    domains: Object,
    phpVersions: Array,
    users: Array,
});

const page = usePage();
const isAdmin = page.props.auth?.user?.role === 'admin';

const showModal = shallowRef(false);

const form = useForm({
    domain: '',
    php_version: '83',
    type: 'addon',
    user_id: props.users?.[0]?.id || '',
});

const openAddModal = () => {
    form.reset();
    showModal.value = true;
};

const submit = () => {
    form.post('/domains', {
        onSuccess: () => {
            showModal.value = false;
        },
    });
};

const deleteDomain = (id, name) => {
    if (confirm(`Are you sure you want to terminate domain '${name}'? This removes the vhost configuration and website files.`)) {
        router.delete(`/domains/${id}`);
    }
};

const changePhp = (domainId, newVer) => {
    router.post(`/domains/${domainId}/php`, { php_version: newVer });
};
</script>

<template>
    <Head title="Virtual Host Routing - LiteControl" />

    <div class="space-y-6">
            <!-- Asymmetrical Spec Header -->
            <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 pb-4 border-b border-[#1b222d]">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-[10px] font-mono tracking-widest uppercase text-[#ff6b35]">Network Gateway</span>
                        <span class="text-[#3a4454] font-mono">/</span>
                        <span class="text-[10px] font-mono text-[#6c7d93]">HTTP_VHOST_ROUTER</span>
                    </div>
                    <h1 class="text-2xl font-bold tracking-tight text-white font-mono uppercase flex items-center gap-3">
                        <Globe class="w-6 h-6 text-[#ff6b35]" />
                        <span>Domain Routing Registry</span>
                    </h1>
                </div>

                <button
                    @click="openAddModal"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-[#ff6b35] hover:bg-[#ff8252] text-black text-xs font-mono font-bold uppercase tracking-wider transition-colors shadow-[0_0_15px_rgba(255,107,53,0.25)] rounded-xs"
                >
                    <Plus class="w-4 h-4 stroke-[2.5]" />
                    <span>Provision Domain</span>
                </button>
            </div>

            <!-- VHost Grid Matrix -->
            <div class="bg-[#0b0e14] border border-[#1b2330] rounded-xs overflow-hidden">
                <div class="px-5 py-3 border-b border-[#1b2330] bg-[#0f131a] flex items-center justify-between">
                    <span class="text-[11px] font-mono uppercase text-[#7a8b9f] tracking-wider">
                        Configured Ingress Nodes ({{ domains.total ?? domains.data?.length ?? 0 }})
                    </span>
                    <span class="text-[10px] font-mono text-[#00f0a8]">STATUS: OLS_DAEMON_OK</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs font-mono text-[#cad3e0]">
                        <thead class="bg-[#090c11] text-[10px] font-semibold text-[#5a6b80] uppercase tracking-widest border-b border-[#1b2330]">
                            <tr>
                                <th class="px-5 py-3.5">Target Host FQDN</th>
                                <th v-if="isAdmin" class="px-5 py-3.5">Assigned Principal</th>
                                <th class="px-5 py-3.5">Security Perimeter</th>
                                <th class="px-5 py-3.5">PHP SuEXEC Runtime</th>
                                <th class="px-5 py-3.5">Storage Root</th>
                                <th class="px-5 py-3.5 text-right">Operations</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#151b24]">
                            <tr v-for="dom in domains.data" :key="dom.id" class="hover:bg-[#12161f] transition-colors group">
                                <td class="px-5 py-3.5 font-bold text-white flex items-center gap-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-[#ff6b35]/60 group-hover:bg-[#ff6b35]"></span>
                                    <a
                                        :href="'http://' + dom.domain"
                                        target="_blank"
                                        class="hover:text-[#ff6b35] transition-colors underline decoration-[#2e394b] underline-offset-4"
                                    >
                                        {{ dom.domain }}
                                    </a>
                                    <span class="px-1.5 py-0.2 rounded-xs text-[9px] uppercase bg-[#161c26] text-[#7a8b9f] border border-[#222c3c] ml-1">
                                        {{ dom.type }}
                                    </span>
                                </td>
                                <td v-if="isAdmin" class="px-5 py-3.5 text-[#8ea0b5]">
                                    {{ dom.user?.username || '—' }}
                                </td>
                                <td class="px-5 py-3.5">
                                    <span
                                        v-if="dom.ssl_enabled || dom.ssl_status"
                                        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-xs text-[10px] bg-[#00f0a8]/10 text-[#00f0a8] border border-[#00f0a8]/30"
                                    >
                                        <ShieldCheck class="w-3 h-3" /> TLS Active
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-xs text-[10px] bg-[#ff3366]/10 text-[#ff6685] border border-[#ff3366]/30"
                                    >
                                        <ShieldAlert class="w-3 h-3" /> Plaintext
                                    </span>
                                </td>
                                <td class="px-5 py-3.5">
                                    <select
                                        :value="dom.php_version"
                                        @change="changePhp(dom.id, $event.target.value)"
                                        class="px-2 py-0.5 bg-[#171d27] border border-[#252f3f] text-[#8ea0b5] rounded-xs text-[10px] focus:outline-none focus:border-[#ff6b35]"
                                    >
                                        <option value="74">PHP 7.4</option>
                                        <option value="80">PHP 8.0</option>
                                        <option value="81">PHP 8.1</option>
                                        <option value="82">PHP 8.2</option>
                                        <option value="83">PHP 8.3 (Default)</option>
                                        <option value="84">PHP 8.4</option>
                                        <option value="85">PHP 8.5</option>
                                    </select>
                                </td>
                                <td class="px-5 py-3.5 text-[#5e6f85] truncate max-w-xs" :title="dom.document_root">
                                    <span class="flex items-center gap-1.5">
                                        <Folder class="w-3.5 h-3.5 text-[#3e4b5e] shrink-0" />
                                        {{ dom.document_root }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <button
                                        @click="deleteDomain(dom.id, dom.domain)"
                                        class="text-[#5e6f85] hover:text-[#ff3366] transition-colors p-1"
                                        title="Terminate VHost"
                                    >
                                        <Trash2 class="w-4 h-4" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!domains.data || domains.data.length === 0">
                                <td :colspan="isAdmin ? 6 : 5" class="px-5 py-8 text-center text-[#5a6b80] text-xs font-mono">
                                    NO INGRESS NODES REGISTERED
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add Domain Modal -->
            <Modal :show="showModal" max-width="md" @close="showModal = false">
                <div class="p-6 space-y-5">
                    <div class="flex items-center justify-between border-b border-[#1e2634] pb-3">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 bg-[#ff6b35]"></span>
                            <h3 class="text-sm font-mono font-bold uppercase tracking-wider text-white">Provision Virtual Host</h3>
                        </div>
                        <button @click="showModal = false" class="text-[#64748b] hover:text-white transition-colors">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <form @submit.prevent="submit" class="space-y-4 font-mono text-xs">
                        <div v-if="isAdmin">
                            <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">Target Principal</label>
                            <select
                                v-model="form.user_id"
                                required
                                class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#ff6b35]"
                            >
                                <option v-for="u in users" :key="u.id" :value="u.id">
                                    {{ u.username }} ({{ u.name }})
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">Target FQDN / Domain Name</label>
                            <input
                                v-model="form.domain"
                                type="text"
                                placeholder="app.example.com"
                                required
                                class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#ff6b35]"
                            />
                            <p v-if="form.errors.domain" class="text-[10px] text-[#ff3366] mt-1">{{ form.errors.domain }}</p>
                        </div>

                        <div>
                            <label class="block text-[10px] uppercase tracking-wider text-[#7a8b9f] mb-1.5">SuEXEC PHP Engine Profile</label>
                            <select
                                v-model="form.php_version"
                                class="w-full px-3 py-2 bg-[#080b0f] border border-[#1e2634] rounded-xs text-[#cad3e0] text-xs focus:outline-none focus:border-[#ff6b35]"
                            >
                                <option value="83">PHP 8.3 (Default OLS)</option>
                                <option value="82">PHP 8.2</option>
                                <option value="81">PHP 8.1</option>
                                <option value="80">PHP 8.0</option>
                                <option value="74">PHP 7.4</option>
                            </select>
                        </div>

                        <div class="pt-4 flex items-center justify-end gap-3 border-t border-[#1e2634]">
                            <button
                                type="button"
                                @click="showModal = false"
                                class="px-4 py-2 rounded-xs bg-[#161c26] hover:bg-[#202837] text-xs font-mono uppercase tracking-wider text-[#8ea0b5] transition-colors"
                            >
                                Abort
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="px-5 py-2 rounded-xs bg-[#ff6b35] hover:bg-[#ff8252] disabled:opacity-50 text-xs font-mono font-bold uppercase tracking-wider text-black transition-colors shadow-[0_0_15px_rgba(255,107,53,0.25)]"
                            >
                                {{ form.processing ? 'Deploying...' : 'Provision' }}
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>
        </div>
</template>
