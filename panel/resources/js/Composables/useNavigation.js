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
        const cleanCurrent = currentUrl.value.split('?')[0];
        if (routeUrl === '/dashboard') {
            return cleanCurrent === '/dashboard' || cleanCurrent === '/';
        }
        if (cleanCurrent === routeUrl) {
            return true;
        }
        if (!cleanCurrent.startsWith(`${routeUrl}/`)) {
            return false;
        }
        // Only match as a prefix if no more-specific nav route matches cleanCurrent
        return !allNavRoutes.value.some(
            other =>
                other !== routeUrl &&
                other.length > routeUrl.length &&
                (cleanCurrent === other || cleanCurrent.startsWith(`${other}/`))
        );
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

    const allNavRoutes = computed(() => {
        const routes = [];
        for (const item of filteredNavigation.value) {
            if (item.route) routes.push(item.route);
            if (item.children) {
                for (const child of item.children) {
                    if (child.route) routes.push(child.route);
                }
            }
        }
        return routes;
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
