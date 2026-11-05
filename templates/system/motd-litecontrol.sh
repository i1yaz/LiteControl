#!/usr/bin/env bash
# LiteControl Login Banner (/etc/profile.d/litecontrol.sh)

if [[ -z "${PS1:-}" ]]; then
    return 0 2>/dev/null || exit 0
fi

LC_PORT=$(cat /etc/litecontrol/port 2>/dev/null || echo "8090")
LC_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
LC_TIME=$(date '+%Y-%m-%d %H:%M:%S %Z')
LC_LOAD=$(uptime | awk -F'load average:' '{print $2}' | xargs)
LC_RAM=$(free -m 2>/dev/null | awk 'NR==2{printf "%s/%s MB (%.1f%%)", $3, $2, ($3*100)/$2}')
LC_DISK=$(df -h / 2>/dev/null | awk 'NR==2{printf "%s/%s (%s)", $3, $2, $5}')
LC_UPTIME=$(uptime -p 2>/dev/null || uptime)

echo ""
echo "  _      _ _        _____            _             _ "
echo " | |    (_) |      / ____|          | |           | |"
echo " | |     _| |_ ___| |     ___  _ __ | |_ _ __ ___ | |"
echo " | |    | | __/ _ \ |    / _ \| '_ \| __| '__/ _ \| |"
echo " | |____| | ||  __/ |___| (_) | | | | |_| | | (_) | |"
echo " |______|_|\__\___|\_____\___/|_| |_|\__|_|  \___/|_|"
echo ""
echo " Panel URL          : https://${LC_IP}:${LC_PORT}"
echo " Server Time        : ${LC_TIME}"
echo " CPU Load Average   : ${LC_LOAD}"
echo " RAM Usage          : ${LC_RAM}"
echo " Disk Usage (/)     : ${LC_DISK}"
echo " System Uptime      : ${LC_UPTIME}"
echo ""

