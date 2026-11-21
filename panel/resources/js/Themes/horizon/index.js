import './theme.css';
import manifest from './theme.json';
import AppLayout from './Layouts/AppLayout.vue';
import AuthLayout from './Layouts/AuthLayout.vue';
import {
    LayoutDashboard, Users, Globe, Database, FolderTree,
    FileCode, Sliders, Server, Shield, Mail, Layers, Plus,
    Trash2, RefreshCw, Key, Lock, Check, X, Search,
    ExternalLink, AlertCircle, CheckCircle2, ChevronRight
} from '@lucide/vue';

export default {
    ...manifest,
    layouts: {
        default: AppLayout,
        auth: AuthLayout,
    },
    labels: {
        'dashboard': 'Dashboard',
        'users': 'Users & Packages',
        'domains': 'Domains & Hosts',
        'configuration': 'Configuration',
        'server': 'Server & System',
        'security': 'Security',
    },
    components: {},
    icons: {
        dashboard: LayoutDashboard,
        users: Users,
        domains: Globe,
        dns: Globe,
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
        brand: Globe,
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
