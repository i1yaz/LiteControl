<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenLiteSpeed WebAdmin Console is Disabled | LiteControl</title>
    <style>
        :root {
            --bg-color: #0b1120;
            --card-bg: rgba(15, 23, 42, 0.85);
            --border-color: #1e293b;
            --primary: #38bdf8;
            --primary-hover: #0ea5e9;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --warning-bg: rgba(245, 158, 11, 0.1);
            --warning-border: rgba(245, 158, 11, 0.25);
            --warning-text: #fbbf24;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(at 0% 0%, rgba(56, 189, 248, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(99, 102, 241, 0.08) 0px, transparent 50%);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .container {
            width: 100%;
            max-width: 580px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 2.75rem 2.25rem;
            backdrop-filter: blur(16px);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
            text-align: center;
        }

        .logo-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background: rgba(56, 189, 248, 0.1);
            border: 1px solid rgba(56, 189, 248, 0.2);
            color: var(--primary);
            margin-bottom: 1.5rem;
        }

        .logo-wrap svg {
            width: 32px;
            height: 32px;
        }

        h1 {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -0.025em;
            color: var(--text-main);
            margin-bottom: 0.75rem;
        }

        p.desc {
            font-size: 0.95rem;
            line-height: 1.6;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
        }

        .badge-callout {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            text-align: left;
            background: var(--warning-bg);
            border: 1px solid var(--warning-border);
            border-radius: 12px;
            padding: 1rem 1.15rem;
            margin-bottom: 2rem;
            font-size: 0.85rem;
            line-height: 1.5;
            color: var(--warning-text);
        }

        .badge-callout svg {
            flex-shrink: 0;
            width: 20px;
            height: 20px;
            margin-top: 1px;
        }

        .actions {
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            background: var(--primary);
            color: #041325;
            font-size: 0.95rem;
            font-weight: 600;
            padding: 0.85rem 1.5rem;
            border-radius: 12px;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(56, 189, 248, 0.3);
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(56, 189, 248, 0.45);
        }

        .cli-tip {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            font-size: 0.8rem;
            color: #64748b;
            text-align: left;
        }

        .cli-tip strong {
            color: #cbd5e1;
        }

        .cli-code {
            display: block;
            margin-top: 0.5rem;
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid #1e293b;
            border-radius: 8px;
            padding: 0.65rem 0.85rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.8rem;
            color: #38bdf8;
            overflow-x: auto;
        }

        footer {
            margin-top: 1.75rem;
            font-size: 0.75rem;
            color: #475569;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect width="18" height="18" x="3" y="3" rx="2" />
                <path d="M12 9v4" />
                <path d="M12 17h.01" />
            </svg>
        </div>

        <h1>WebAdmin Console is Disabled</h1>
        <p class="desc">
            For security hardening, OpenLiteSpeed native WebAdmin console access on port 7080 is restricted by default on this server.
        </p>

        <div class="badge-callout">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                <line x1="12" y1="9" x2="12" y2="13" />
                <line x1="12" y1="17" x2="12.01" y2="17" />
            </svg>
            <div>
                You can manage all web server listeners, MultiPHP configurations, SSL, tuning, and domains directly within <strong>LiteControl Panel</strong>, or enable WebAdmin with IP whitelisting when needed.
            </div>
        </div>

        <div class="actions">
            <a href="https://__SERVER_IP__:__PANEL_PORT__/" class="btn-primary" id="panelLink">
                <span>Open LiteControl Panel</span>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 12h14" />
                    <path d="m12 5 7 7-7 7" />
                </svg>
            </a>
        </div>

        <div class="cli-tip">
            <strong>Terminal Access:</strong> To toggle WebAdmin console access via SSH CLI:
            <code class="cli-code">sudo lc-cli server webadmin-enable [allowed_ip1,allowed_ip2]</code>
            <code class="cli-code">sudo lc-cli server webadmin-disable</code>
        </div>

        <footer>
            LiteControl OpenLiteSpeed Management Engine &bull; Port 7080
        </footer>
    </div>

    <script>
        // Dynamically replace host and default panel port if opened directly via IP:7080
        (function() {
            var currentHost = window.location.hostname;
            var link = document.getElementById('panelLink');
            if (link && currentHost) {
                var currentHref = link.getAttribute('href');
                if (currentHref.indexOf('__SERVER_IP__') !== -1) {
                    link.setAttribute('href', 'https://' + currentHost + ':8090/');
                }
            }
        })();
    </script>
</body>
</html>
