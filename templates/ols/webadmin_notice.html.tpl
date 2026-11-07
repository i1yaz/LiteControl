<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebAdmin Console Restricted | LiteControl Mission Control</title>
    <!-- Fonts: Space Grotesk + JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --obsidian-base: #080a0e;
            --obsidian-surface: #0c1017;
            --obsidian-elevated: #151b24;
            --obsidian-border: #1e2634;
            --plasma-orange: #ff6b35;
            --neon-cyan: #00f0a8;
            --signal-amber: #ffd000;
            --laser-crimson: #ff3366;
            --text-main: #cad3e0;
            --text-muted: #6c7d93;
            --text-dim: #455365;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Space Grotesk', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--obsidian-base);
            background-image: 
                linear-gradient(to right, rgba(30, 38, 52, 0.35) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(30, 38, 52, 0.35) 1px, transparent 1px);
            background-size: 3.5rem 3.5rem;
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            position: relative;
        }

        /* Ambient cockpit phosphor glow */
        body::before {
            content: '';
            position: absolute;
            top: 30%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 480px;
            height: 480px;
            background: radial-gradient(circle, rgba(255, 107, 53, 0.08) 0%, transparent 70%);
            pointer-events: none;
        }

        .hud-card {
            width: 100%;
            max-width: 620px;
            background: var(--obsidian-surface);
            border: 1px solid var(--obsidian-border);
            border-radius: 2px;
            padding: 2.25rem 2rem;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.85);
            position: relative;
            z-index: 10;
        }

        /* Chamfer accent bar */
        .hud-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4.5rem;
            height: 2px;
            background: var(--plasma-orange);
        }

        .status-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--obsidian-border);
            margin-bottom: 1.5rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
        }

        .status-tag {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--plasma-orange);
        }

        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--laser-crimson);
            box-shadow: 0 0 8px var(--laser-crimson);
        }

        .telemetry-code {
            color: var(--text-muted);
        }

        .brand-row {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            margin-bottom: 1rem;
        }

        .icon-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            border-radius: 2px;
            background: rgba(255, 107, 53, 0.1);
            border: 1px solid rgba(255, 107, 53, 0.3);
            color: var(--plasma-orange);
            flex-shrink: 0;
        }

        h1 {
            font-family: 'JetBrains Mono', monospace;
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            text-transform: uppercase;
            color: #fff;
        }

        p.desc {
            font-size: 0.875rem;
            line-height: 1.6;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
        }

        .hud-callout {
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
            text-align: left;
            background: rgba(255, 208, 0, 0.05);
            border: 1px solid rgba(255, 208, 0, 0.2);
            border-left: 3px solid var(--signal-amber);
            border-radius: 2px;
            padding: 1rem 1.15rem;
            margin-bottom: 1.75rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.75rem;
            line-height: 1.6;
            color: #d1b85d;
        }

        .hud-callout svg {
            flex-shrink: 0;
            width: 18px;
            height: 18px;
            margin-top: 2px;
            color: var(--signal-amber);
        }

        .hud-callout strong {
            color: #fff;
        }

        .actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .btn-telemetry {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            background: var(--plasma-orange);
            color: #06080b;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 0.85rem 1.5rem;
            border-radius: 2px;
            text-decoration: none;
            transition: all 0.15s ease;
            box-shadow: 0 0 20px rgba(255, 107, 53, 0.25);
            border: 1px solid var(--plasma-orange);
        }

        .btn-telemetry:hover {
            background: #ff8252;
            box-shadow: 0 0 25px rgba(255, 107, 53, 0.4);
            transform: translateY(-1px);
        }

        .cli-console {
            margin-top: 1.75rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--obsidian-border);
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.75rem;
            color: var(--text-muted);
            text-align: left;
        }

        .cli-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #8ea0b5;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            font-size: 0.65rem;
            letter-spacing: 0.1em;
        }

        .cli-code {
            display: block;
            margin-top: 0.4rem;
            background: #080b0f;
            border: 1px solid var(--obsidian-border);
            border-radius: 2px;
            padding: 0.55rem 0.85rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.75rem;
            color: var(--neon-cyan);
            overflow-x: auto;
        }

        .cli-code::before {
            content: "$ ";
            color: var(--plasma-orange);
        }

        footer {
            margin-top: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.65rem;
            color: var(--text-dim);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
    </style>
</head>
<body>
    <div class="hud-card">
        <div class="status-header">
            <div class="status-tag">
                <span class="status-dot"></span>
                <span>SECURITY_PERIMETER // ENFORCED</span>
            </div>
            <div class="telemetry-code">OLS_PORT: 7080 [LOCKED]</div>
        </div>

        <div class="brand-row">
            <div class="icon-box">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="18" height="18" x="3" y="3" rx="1" />
                    <path d="M12 9v4" />
                    <path d="M12 17h.01" />
                </svg>
            </div>
            <div>
                <h1>WebAdmin Console Restricted</h1>
            </div>
        </div>

        <p class="desc">
            For security hardening, OpenLiteSpeed native WebAdmin console access on port 7080 is restricted by default on this server.
        </p>

        <div class="hud-callout">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                <line x1="12" y1="9" x2="12" y2="13" />
                <line x1="12" y1="17" x2="12.01" y2="17" />
            </svg>
            <div>
                Manage all web server listeners, MultiPHP configurations, SSL, tuning, and virtual hosts directly within <strong>LiteControl Panel</strong>, or enable WebAdmin with IP whitelisting when needed.
            </div>
        </div>

        <div class="actions">
            <a href="https://__SERVER_IP__:__PANEL_PORT__/" class="btn-telemetry" id="panelLink">
                <span>Access LiteControl Panel Gateway</span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 12h14" />
                    <path d="m12 5 7 7-7 7" />
                </svg>
            </a>
        </div>

        <div class="cli-console">
            <div class="cli-label">
                <span>CLI Terminal Override Instructions:</span>
            </div>
            <code class="cli-code">sudo lc-cli server webadmin-enable [allowed_ip1,allowed_ip2]</code>
            <code class="cli-code">sudo lc-cli server webadmin-disable</code>
        </div>

        <footer>
            <span>LiteControl Avionics Engine</span>
            <span>PORT_7080_SECURITY_GUARD</span>
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
