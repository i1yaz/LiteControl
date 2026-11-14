import { computed } from 'vue';

const FALLBACK_PHP_VERSIONS = ['74', '80', '81', '82', '83', '84', '85'];

/**
 * Normalizes backend phpVersions (array of objects or strings) into consistent
 * { value, label } options for PHP runtime dropdowns.
 */
export function usePhpVersions(getVersions) {
    const phpOptions = computed(() => {
        const raw = typeof getVersions === 'function' ? getVersions() : getVersions?.value;
        const list = Array.isArray(raw) && raw.length ? raw : FALLBACK_PHP_VERSIONS;

        return list.map((v) => {
            const isObj = typeof v === 'object' && v !== null;
            const ver = isObj ? v.version : v;
            const str = String(ver).replace('.', '');
            const formatted = str.length === 2 ? `${str[0]}.${str[1]}` : str;
            const isDefault = isObj ? Boolean(v.is_default) : str === '83';
            return {
                value: str,
                label: isDefault ? `PHP ${formatted} (Default)` : `PHP ${formatted}`,
            };
        });
    });

    return { phpOptions };
}

