import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import ThemePersistentLayout from './Layouts/ThemePersistentLayout.vue';
import { loadThemeModule } from './Composables/useTheme';

const appName = window.document.getElementsByTagName('title')[0]?.innerText || 'LiteControl';

const canonicalPages = import.meta.glob('./Pages/**/*.vue');
const themePages = import.meta.glob('./Themes/*/Pages/**/*.vue');

const initialThemeId = (typeof document !== 'undefined' && document.documentElement.getAttribute('data-theme')) || 'avionics';
loadThemeModule(initialThemeId);

createInertiaApp({
    title: (title) => title ? `${title} - ${appName}` : appName,
    resolve: async (name) => {
        const currentThemeId = (typeof document !== 'undefined' && document.documentElement.getAttribute('data-theme')) || 'avionics';
        await loadThemeModule(currentThemeId);

        const themePagePath = `./Themes/${currentThemeId}/Pages/${name}.vue`;
        let pageModule;

        if (themePages[themePagePath]) {
            pageModule = await themePages[themePagePath]();
        } else if (canonicalPages[`./Pages/${name}.vue`]) {
            pageModule = await canonicalPages[`./Pages/${name}.vue`]();
        } else {
            throw new Error(`Page not found: ${name}`);
        }

        const page = pageModule.default || pageModule;
        if (page.layout === undefined) {
            const isAuth = name.startsWith('Auth/');
            page.layout = (h, pageVNode) => h(ThemePersistentLayout, { isAuth }, () => pageVNode);
        }

        return page;
    },
    setup({ el, App, props, plugin }) {
        if (props.initialPage?.props?.panel?.theme?.id) {
            loadThemeModule(props.initialPage.props.panel.theme.id);
        }

        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#ff6b35',
    },
});
