import { computed, reactive } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { PANEL_NAVIGATION } from '@/Navigation/panelNavigation';

export function useNavigation() {
    const page = usePage();

    const user = computed(() => page.props.auth?.user || {});
    const isAdmin = computed(() => user.value?.role === 'admin');
    const currentUrl = computed(() => page.url);

    const isRouteActive = (routeUrl) => {
        if (!routeUrl) return false;
        if (routeUrl === '/dashboard') {
            return currentUrl.value === '/dashboard' || currentUrl.value === '/';
        }
        // Remove query parameters for matching
        const cleanCurrent = currentUrl.value.split('?')[0];
        return cleanCurrent === routeUrl || cleanCurrent.startsWith(`${routeUrl}/`);
    };

    const isItemActive = (item) => {
        if (item.route && isRouteActive(item.route)) {
            return true;
        }
        if (item.children) {
            return item.children.some(child => isRouteActive(child.route));
        }
        return false;
    };

    // Filter navigation tree based on user role
    const filteredNavigation = computed(() => {
        return PANEL_NAVIGATION
            .filter(item => !item.adminOnly || isAdmin.value)
            .map(item => {
                if (!item.children) return item;
                const filteredChildren = item.children.filter(child => !child.adminOnly || isAdmin.value);
                return {
                    ...item,
                    children: filteredChildren,
                };
            })
            .filter(item => !item.children || item.children.length > 0);
    });

    // Reactive accordion state
    const openSubmenus = reactive({});

    // Initialize or auto-expand active section
    const initAccordion = () => {
        filteredNavigation.value.forEach(item => {
            if (item.children) {
                if (openSubmenus[item.id] === undefined) {
                    openSubmenus[item.id] = isItemActive(item);
                } else if (isItemActive(item)) {
                    openSubmenus[item.id] = true;
                }
            }
        });
    };

    initAccordion();

    const toggleSubmenu = (menuId) => {
        openSubmenus[menuId] = !openSubmenus[menuId];
    };

    const getLabel = (item, themeLabels = {}) => {
        return themeLabels[item.id] || item.label;
    };

    return {
        user,
        isAdmin,
        currentUrl,
        filteredNavigation,
        openSubmenus,
        toggleSubmenu,
        isRouteActive,
        isItemActive,
        getLabel,
    };
}
