#!/usr/bin/env bash
# ==============================================================================
# LiteControl Core 03: Redis Server Installation & Configuration
# Powers Laravel Cache, Sessions, Queue Workers, and User Object Caching
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

install_and_configure_redis() {
    log_step "03. Installing and Configuring Redis Server"

    apt_install redis-server

    local redis_conf="/etc/redis/redis.conf"
    if [[ -f "${redis_conf}" ]]; then
        cp -a "${redis_conf}" "${redis_conf}.bak"
        # Ensure Redis binds strictly to localhost
        sed -i 's/^bind .*/bind 127.0.0.1 -::1/' "${redis_conf}" || true
        # Ensure systemd supervision is enabled
        sed -i 's/^#\?supervised .*/supervised systemd/' "${redis_conf}" || true

        # Set sensible default memory policy if not already configured
        if ! grep -q "^maxmemory " "${redis_conf}"; then
            cat >> "${redis_conf}" <<'EOF'

# LiteControl default tuning
maxmemory 256mb
maxmemory-policy allkeys-lru
EOF
        fi
    fi

    systemctl enable redis-server >/dev/null 2>&1 || true
    systemctl restart redis-server

    if redis-cli ping 2>/dev/null | grep -q "PONG"; then
        mark_module_installed "redis"
        log_ok "Redis server installed and responding on 127.0.0.1:6379."
    else
        log_error "Redis service started but did not respond to PING."
        return 1
    fi
}

main() {
    require_root
    detect_ubuntu_os
    init_litecontrol_dirs
    install_and_configure_redis
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi

