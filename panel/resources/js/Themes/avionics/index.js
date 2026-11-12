import './theme.css';
import { defineAsyncComponent } from 'vue';
import AppLayout from './Layouts/AppLayout.vue';
import AuthLayout from './Layouts/AuthLayout.vue';
import {
    LayoutDashboard, Users, Globe, Database, FolderTree,
    FileCode, Sliders, Server, Shield, Mail, Layers, Plus,
    Trash2, Cpu, RefreshCw, Key, Lock, Check, X, Search,
    ExternalLink, AlertCircle, CheckCircle2, ChevronRight
} from '@lucide/vue';

export default {
    id: 'avionics',
    name: 'Avionics Telemetry',
    layouts: {
        default: AppLayout,
        auth: AuthLayout,
    },
    labels: {
        'dashboard': 'Dashboard Matrix',
        'users': 'Users & Quotas',
        'domains': 'Virtual Hosts',
        'configuration': 'OLS Parameters',
        'server': 'Host System',
        'security': 'Defense & SSL',
    },
    components: {
        Button: defineAsyncComponent(() => import('./Components/Button.vue')),
        Card: defineAsyncComponent(() => import('./Components/Card.vue')),
        DataTable: defineAsyncComponent(() => import('./Components/DataTable.vue')),
        Input: defineAsyncComponent(() => import('./Components/Input.vue')),
        Select: defineAsyncComponent(() => import('./Components/Select.vue')),
        Badge: defineAsyncComponent(() => import('./Components/Badge.vue')),
        Modal: defineAsyncComponent(() => import('./Components/Modal.vue')),
        PageHeader: defineAsyncComponent(() => import('./Components/PageHeader.vue')),
        Alert: defineAsyncComponent(() => import('./Components/Alert.vue')),
    },
    icons: {
        dashboard: LayoutDashboard,
        users: Users,
        domains: Globe,
        database: Database,
        files: FolderTree,
        php: FileCode,
        configuration: Sliders,
        server: Server,
        security: Shield,
        modules: Layers,
        email: Mail,
        plus: Plus,
        trash: Trash2,
        brand: Cpu,
        refresh: RefreshCw,
        key: Key,
        lock: Lock,
        check: Check,
        close: X,
        search: Search,
        external: ExternalLink,
        alert: AlertCircle,
        success: CheckCircle2,
        chevronRight: ChevronRight,
    },
};
