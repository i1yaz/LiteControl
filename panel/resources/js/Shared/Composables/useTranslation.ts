import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

export interface LocaleOption {
    code: string;
    name: string;
    native: string;
    dir: 'ltr' | 'rtl';
}

type Replacements = Record<string, string | number>;

function translate(
    translations: Record<string, string> | undefined,
    key: string,
    replacements: Replacements = {},
): string {
    let text = translations?.[key] ?? key;

    for (const [param, value] of Object.entries(replacements)) {
        text = text.replaceAll(`:${param}`, String(value));
    }

    return text;
}

export function createTranslator(getTranslations: () => Record<string, string> | undefined) {
    return (key: string, replacements: Replacements = {}): string => {
        return translate(getTranslations(), key, replacements);
    };
}

export function useTranslation() {
    const page = usePage();

    const locale = computed<string>(() => (page.props.locale as string) || 'en');
    const availableLocales = computed<LocaleOption[]>(
        () => (page.props.available_locales as LocaleOption[]) || [],
    );
    const translations = computed<Record<string, string>>(
        () => (page.props.translations as Record<string, string>) || {},
    );

    const __ = (key: string, replacements: Replacements = {}): string => {
        return translate(translations.value, key, replacements);
    };

    const switchLocale = (code: string) => {
        if (code === locale.value) {
            return;
        }
        router.post('/locale', { locale: code }, {
            preserveScroll: true,
            onSuccess: () => {
                const target = availableLocales.value.find((l) => l.code === code);
                if (typeof document !== 'undefined') {
                    document.documentElement.lang = code;
                    document.documentElement.dir = target?.dir || 'ltr';
                }
            },
        });
    };

    return {
        __,
        locale,
        availableLocales,
        switchLocale,
    };
}
