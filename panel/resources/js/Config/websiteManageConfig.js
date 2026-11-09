/**
 * Declarative Configuration for Website Management Panel
 * Organized to allow tabs, action buttons, and snippets to be easily added, reordered, or moved.
 */

export const WEBSITE_MANAGE_TABS = [
    { id: 'overview', label: 'Overview', icon: 'LayoutGrid' },
    { id: 'domains',  label: 'Domains',  icon: 'GitFork' },
    { id: 'logs',     label: 'Logs',     icon: 'FileText' },
    { id: 'config',   label: 'Config',   icon: 'SlidersHorizontal' },
    { id: 'ssl',      label: 'SSL',      icon: 'Lock' },
    { id: 'files',    label: 'Files',    icon: 'Folder' },
];

export const WEBSITE_MANAGE_ACTIONS = [
    // Domains Tab
    {
        id: 'domain-alias',
        tabId: 'domains',
        title: 'Domain Alias',
        description: 'Point secondary domains or aliases to this website',
        icon: 'Globe',
        type: 'panel',
        panelKey: 'DomainAliasPanel',
    },

    // Logs Tab
    {
        id: 'access-logs',
        tabId: 'logs',
        title: 'Access Logs',
        description: 'Real-time HTTP traffic and ingress requests',
        icon: 'FileSpreadsheet',
        type: 'panel',
        panelKey: 'LogViewerPanel',
        props: { logType: 'access', title: 'HTTP Access Log' },
    },
    {
        id: 'error-logs',
        tabId: 'logs',
        title: 'Error Logs',
        description: 'Web server runtime and PHP execution diagnostics',
        icon: 'FileWarning',
        type: 'panel',
        panelKey: 'LogViewerPanel',
        props: { logType: 'error', title: 'Web Server Error Log' },
    },

    // Config Tab
    {
        id: 'vhost-conf',
        tabId: 'config',
        title: 'vHost Conf',
        description: 'View live OpenLiteSpeed VirtualHost directives',
        icon: 'FileCode',
        type: 'panel',
        panelKey: 'VhostConfPanel',
    },
    {
        id: 'rewrite-rules',
        tabId: 'config',
        title: 'Rewrite Rules',
        description: 'Edit .htaccess rules and inject common rewrite presets',
        icon: 'FileEdit',
        type: 'panel',
        panelKey: 'RewriteRulesPanel',
    },
    {
        id: 'change-php',
        tabId: 'config',
        title: 'Change PHP',
        description: 'Switch SuEXEC PHP engine profile for this website',
        icon: 'Cpu',
        type: 'panel',
        panelKey: 'ChangePhpPanel',
    },

    // SSL Tab
    {
        id: 'issue-renew-ssl',
        tabId: 'ssl',
        title: 'Issue / Renew SSL',
        description: 'Automated Let\'s Encrypt certificate issuance and renewal',
        icon: 'ShieldCheck',
        type: 'panel',
        panelKey: 'IssueSslPanel',
    },
    {
        id: 'add-ssl',
        tabId: 'ssl',
        title: 'Add SSL',
        description: 'Install custom enterprise SSL certificate and private key',
        icon: 'KeyRound',
        type: 'panel',
        panelKey: 'CustomSslPanel',
    },

    // Files Tab
    {
        id: 'file-manager',
        tabId: 'files',
        title: 'File Manager',
        description: 'Open file explorer jailed directly to website root',
        icon: 'FolderOpen',
        type: 'route',
        getUrl: (domain) => `/files?domain_id=${domain.id}`,
    },
    {
        id: 'create-ftp',
        tabId: 'files',
        title: 'Create FTP Acct',
        description: 'Provision Pure-FTPd virtual user with custom path and quota',
        icon: 'UserPlus',
        type: 'panel',
        panelKey: 'CreateFtpPanel',
    },
    {
        id: 'delete-ftp',
        tabId: 'files',
        title: 'Delete FTP Acct',
        description: 'Inspect and remove existing Pure-FTPd accounts',
        icon: 'UserMinus',
        type: 'panel',
        panelKey: 'DeleteFtpPanel',
    },
];

export const REWRITE_TEMPLATES = [
    {
        id: 'https',
        label: 'Force HTTP → HTTPS',
        snippet: `### Rewrite Rules Added by LiteControl Rewrite Rule Generator
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^/?(.*) https://%{SERVER_NAME}/$1 [R,L]
### End LiteControl Generated Rules.`,
    },
    {
        id: 'non_www',
        label: 'Force WWW → NON-WWW',
        snippet: `### Rewrite Rules Added by LiteControl Rewrite Rule Generator
RewriteEngine On
RewriteCond %{HTTP_HOST} ^www\\.(.*)$
RewriteRule ^(.*)$ http://%1/$1 [L,R=301]
### End LiteControl Generated Rules.`,
    },
    {
        id: 'force_www',
        label: 'Force NON-WWW → WWW',
        snippet: `### Rewrite Rules Added by LiteControl Rewrite Rule Generator
RewriteEngine On
RewriteCond %{HTTP_HOST} !^www\\. [NC]
RewriteRule ^(.*)$ http://www.%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
### End LiteControl Generated Rules.`,
    },
    {
        id: 'wp_xmlrpc',
        label: 'Disable WordPress XMLRPC & Trackback',
        snippet: `### Rewrite Rules Added by LiteControl Rewrite Rule Generator
RewriteEngine On
RewriteRule ^/(xmlrpc|wp-trackback).php - [F,L,NC]
### End LiteControl Generated Rules.`,
    },
];

