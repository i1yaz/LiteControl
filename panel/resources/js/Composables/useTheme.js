import { ref, shallowRef, computed } from 'vue';
import { usePage, router } from '@inertiajs/vue3';

// Vite lazy glob of all themes
const themeModules = import.meta.glob('../Themes/*/index.js');

// Global singleton cache across component instances
const activeThemeId = ref('avionics');
const activeThemeModule = shallowRef(null);
const loadedThemes = new Map();

/**
 * Load and activate a theme module by ID
 */
export async function loadThemeModule(themeId = 'avionics') {
    if (loadedThemes.has(themeId)) {
        activeThemeModule.value = loadedThemes.get(themeId);
        activeThemeId.value = themeId;
        return activeThemeModule.value;
    }

    const key = `../Themes/${themeId}/index.js`;
    const fallbackKey = `../Themes/avionics/index.js`;

    const loader = themeModules[key] || themeModules[fallbackKey];
    if (!loader) {
        console.warn(`[LiteControl Themes] No loader found for theme: ${themeId}`);
        return null;
    }

    try {
        const mod = await loader();
        const themeExport = mod.default || mod;
        loadedThemes.set(themeId, themeExport);
        activeThemeModule.value = themeExport;
        activeThemeId.value = themeId;
        return themeExport;
    } catch (err) {
        console.error(`[LiteControl Themes] Failed to load theme "${themeId}":`, err);
        if (themeId !== 'avionics' && themeModules[fallbackKey]) {
            const fallbackMod = await themeModules[fallbackKey]();
            const fallbackExport = fallbackMod.default || fallbackMod;
            loadedThemes.set('avionics', fallbackExport);
            activeThemeModule.value = fallbackExport;
            activeThemeId.value = 'avionics';
            return fallbackExport;
        }
        return null;
    }
}

export function useTheme() {
    const page = usePage();

    const serverTheme = computed(() => page.props.panel?.theme || { id: 'avionics' });
    const availableThemes = computed(() => page.props.panel?.available_themes || []);

    // Ensure client state stays in sync with server shared prop
    if (serverTheme.value?.id && serverTheme.value.id !== activeThemeId.value) {
        loadThemeModule(serverTheme.value.id);
    }

    const switchTheme = (themeId) => {
        router.post('/theme/switch', { theme: themeId }, {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => {
                if (typeof document !== 'undefined') {
                    document.documentElement.setAttribute('data-theme', themeId);
                }
                loadThemeModule(themeId).then(() => {
                    // Reload the Inertia page so dynamic page components re-resolve for the new theme
                    router.reload({ preserveScroll: true });
                });
            },
        });
    };

    const resolveComponent = (name) => {
        if (!activeThemeModule.value?.components) return null;
        return activeThemeModule.value.components[name] || null;
    };

    const resolveIcon = (name) => {
        if (!activeThemeModule.value?.icons) return null;
        return activeThemeModule.value.icons[name] || null;
    };

    const themeLabels = computed(() => activeThemeModule.value?.labels || {});

    return {
        activeThemeId,
        activeThemeModule,
        serverTheme,
        availableThemes,
        switchTheme,
        resolveComponent,
        resolveIcon,
        themeLabels,
    };
}
