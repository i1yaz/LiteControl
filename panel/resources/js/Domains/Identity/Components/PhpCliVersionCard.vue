<script setup>
import { computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    user: {
        type: Object,
        required: true,
    },
    installedVersions: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const savedVersion = computed(() => props.user.php_cli_version ?? '8.3');

const form = useForm({
    version: savedVersion.value,
});

watch(savedVersion, (next) => {
    form.version = next;
});

const errorMessage = computed(() => form.errors.version || page.props.flash?.error || null);
const isUnchanged = computed(() => form.version === savedVersion.value);

const submit = () => {
    form.patch(route('users.php-cli-version', props.user.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
            Default PHP CLI Version
        </h3>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            Select the default PHP CLI binary used for SSH sessions and cron jobs for
            <span class="font-mono font-medium">{{ user.username }}</span>.
        </p>

        <form @submit.prevent="submit" class="mt-4 space-y-4">
            <div>
                <label
                    for="php-cli-version"
                    class="block text-sm font-medium text-gray-700 dark:text-gray-300"
                >
                    PHP CLI Version
                </label>
                <div class="mt-1 flex items-center gap-3">
                    <select
                        id="php-cli-version"
                        v-model="form.version"
                        :aria-invalid="Boolean(errorMessage)"
                        :aria-describedby="errorMessage ? 'php-cli-version-error' : 'php-cli-version-help'"
                        class="block w-48 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    >
                        <option
                            v-for="ver in installedVersions"
                            :key="ver"
                            :value="ver"
                        >
                            PHP {{ ver }}
                        </option>
                    </select>

                    <button
                        type="submit"
                        :disabled="form.processing || isUnchanged"
                        class="inline-flex items-center rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ form.processing ? 'Saving...' : 'Save' }}
                    </button>
                </div>
                <p
                    v-if="errorMessage"
                    id="php-cli-version-error"
                    role="alert"
                    class="mt-1.5 text-sm text-red-600 dark:text-red-400"
                >
                    {{ errorMessage }}
                </p>
            </div>

            <div id="php-cli-version-help" class="space-y-2 rounded-md bg-gray-50 p-3 text-xs text-gray-600 dark:bg-gray-900/50 dark:text-gray-400">
                <p>
                    <strong>Immediate Effect:</strong>
                    Changes apply immediately to SSH sessions and Composer (<code>/usr/local/bin/php</code>).
                </p>
                <p>
                    <strong>Per-Directory Override:</strong>
                    Placing a <code>.php-version</code> file (e.g. <code>8.2</code>) in a project root overrides this default for that directory.
                </p>
                <p>
                    <strong>Shared INI &amp; Workers:</strong>
                    OpenLiteSpeed CLI shares <code>php.ini</code> with the <code>lsphp</code> web handler, and background workers (Supervisor/queues) must be restarted to use the new binary.
                </p>
            </div>
        </form>
    </div>
</template>
