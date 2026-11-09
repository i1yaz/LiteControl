/**
 * Canonical LiteControl Navigation Structure.
 * Themes consume this structured schema and can map icons, terminology, and layout styles.
 */
export const PANEL_NAVIGATION = [
    {
        id: 'dashboard',
        label: 'Dashboard',
        route: '/dashboard',
        icon: 'dashboard',
        adminOnly: false,
    },
    {
        id: 'users',
        section: 'Administration',
        label: 'Users & Quotas',
        icon: 'users',
        adminOnly: true,
        children: [
            { id: 'users.index', label: 'User Directory', route: '/users' },
            { id: 'users.create', label: 'Provision User', route: '/users/create' },
            { id: 'packages.index', label: 'Quota Profiles', route: '/packages' },
        ],
    },
    {
        id: 'domains',
        section: 'Web Services',
        label: 'Virtual Hosts',
        icon: 'domains',
        adminOnly: false,
        children: [
            { id: 'domains.index', label: 'Domains', route: '/domains' },
            { id: 'subdomains.index', label: 'Subdomains', route: '/subdomains' },
        ],
    },
    {
        id: 'database',
        label: 'Databases',
        icon: 'database',
        adminOnly: false,
        children: [
            { id: 'databases.index', label: 'MySQL Engines', route: '/databases' },
            { id: 'databases.pma', label: 'phpMyAdmin', route: '/phpmyadmin/', external: true },
        ],
    },
    {
        id: 'files',
        label: 'File System',
        icon: 'files',
        adminOnly: false,
        children: [
            { id: 'files.index', label: 'File Explorer', route: '/files' },
            { id: 'backups.index', label: 'Snapshots & Backup', route: '/backups' },
        ],
    },
    {
        id: 'php',
        label: 'PHP Processors',
        icon: 'php',
        adminOnly: false,
        children: [
            { id: 'php.manager', label: 'MultiPHP Manager', route: '/php/manager' },
            { id: 'php.ini', label: 'INI Directives', route: '/php/ini' },
            { id: 'php.extensions', label: 'Extensions', route: '/php/extensions' },
            { id: 'php.versions', label: 'PHP Version', route: '/php/versions', adminOnly: true },
            { id: 'php.modules', label: 'PHP Modules', route: '/php/modules' },
        ],
    },
    {
        id: 'configuration',
        section: 'Server Configuration',
        label: 'OLS Parameters',
        icon: 'configuration',
        adminOnly: true,
        children: [
            { id: 'configuration.settings', label: 'Core Settings', route: '/configuration/settings' },
            { id: 'configuration.tuning', label: 'Tuning & Workers', route: '/configuration/tuning' },
            { id: 'configuration.php-limit', label: 'PHP Limit', route: '/configuration/php-limit' },
            { id: 'configuration.error-logs', label: 'Audit Log', route: '/configuration/error-logs' },
        ],
    },
    {
        id: 'server',
        label: 'Host System',
        icon: 'server',
        adminOnly: true,
        children: [
            { id: 'server.services', label: 'System Services', route: '/server/services' },
            { id: 'server.processes', label: 'Process Table', route: '/server/processes' },
            { id: 'server.timezone', label: 'Time Zone', route: '/server/timezone' },
            { id: 'server.restart', label: 'Daemon Cycle', route: '/server/restart' },
            { id: 'server.hostname', label: 'Hostname', route: '/server/hostname' },
            { id: 'server.statistics', label: 'System Statistics', route: '/server/statistics' },
        ],
    },
    {
        id: 'security',
        section: 'Security & Defense',
        label: 'Defense & SSL',
        icon: 'security',
        adminOnly: false,
        children: [
            { id: 'security.ssl', label: 'SSL Certificates', route: '/security/ssl' },
            { id: 'security.csf', label: 'CSF Firewall', route: '/security/csf', adminOnly: true },
            { id: 'security.ufw', label: 'UFW Packet Filter', route: '/security/ufw', adminOnly: true },
            { id: 'security.port', label: 'Port Firewall', route: '/security/port', adminOnly: true },
            { id: 'security.modsecurity', label: 'ModSecurity WAF', route: '/security/modsecurity', adminOnly: true },
            { id: 'security.panel-port', label: 'Panel Port', route: '/security/panel-port', adminOnly: true },
            { id: 'security.ssh-port', label: 'SSH Port', route: '/security/ssh-port', adminOnly: true },
            { id: 'security.panel-ssl', label: 'Panel SSL', route: '/security/panel-ssl', adminOnly: true },
            { id: 'security.imunifyav', label: 'ImunifyAV', route: '/security/imunifyav', adminOnly: true },
            { id: 'security.password', label: 'Password', route: '/security/password' },
            { id: 'security.two-factor', label: '2-Factor Auth', route: '/security/two-factor' },
        ],
    },
    {
        id: 'modules',
        label: 'Module Manager',
        icon: 'modules',
        route: '/modules',
        adminOnly: true,
    },
    {
        id: 'email',
        label: 'Email Transport',
        icon: 'email',
        adminOnly: false,
        children: [
            { id: 'email.accounts', label: 'Mail Accounts', route: '/dashboard' },
            { id: 'email.forwarders', label: 'Forwarding Rules', route: '/dashboard' },
            { id: 'email.dkim', label: 'DKIM Signatures', route: '/dashboard' },
        ],
    },
];
