import { computed, reactive } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { PANEL_NAVIGATION } from '@shared/Navigation/panelNavigation';

export function useNavigation() {
    const page = usePage();

    const user = computed(() => page.props.auth?.user || {});
    const isAdmin = computed(() => user.value?.role === 'admin');
    const activeModules = computed(() => page.props.modules || {});
    const isModuleActive = (moduleKey) => Boolean(activeModules.value?.[moduleKey]);
    const currentUrl = computed(() => page.url);

    const isRouteActive = (routeUrl) => {
        if (!routeUrl) return false;
        const fullCurrent = currentUrl.value;
        const cleanCurrent = fullCurrent.split('?')[0];

        // If the nav item specifies a query string (e.g. /modules?module=powerdns),
        // require an exact match including query string.
        if (routeUrl.includes('?')) {
            return fullCurrent === routeUrl;
        }

        // If another nav item matches the full URL with query string (e.g. /modules?module=powerdns),
        // don't also mark the base route (/modules) as active.
        if (fullCurrent.includes('?') && allNavRoutes.value.includes(fullCurrent)) {
            return false;
        }

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
                !other.includes('?') &&
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

    // Filter navigation tree based on user role and active modules
    const filteredNavigation = computed(() => {
        return PANEL_NAVIGATION
            .filter(item => !item.adminOnly || isAdmin.value)
            .map(item => {
                if (item.requiresModule && !isModuleActive(item.requiresModule)) {
                    if (!item.inactiveFallback) {
                        return null;
                    }
                    if (item.inactiveFallback.adminOnly && !isAdmin.value) {
                        return null;
                    }
                    return {
                        ...item,
                        children: [item.inactiveFallback],
                    };
                }
                if (!item.children) return item;
                const filteredChildren = item.children
                    .filter(child => !child.adminOnly || isAdmin.value)
                    .map(child => {
                        if (child.requiresModule && !isModuleActive(child.requiresModule)) {
                            if (!child.inactiveFallback) {
                                return null;
                            }
                            if (child.inactiveFallback.adminOnly && !isAdmin.value) {
                                return null;
                            }
                            return child.inactiveFallback;
                        }
                        return child;
                    })
                    .filter(Boolean);
                return {
                    ...item,
                    children: filteredChildren,
                };
            })
            .filter(Boolean)
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
