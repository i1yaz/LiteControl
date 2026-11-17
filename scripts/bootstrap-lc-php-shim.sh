#!/usr/bin/env bash
set -euo pipefail

DEFAULT_VERSION="${1:-8.3}"
SHIM_BIN="/usr/local/lib/lc-panel/php-shim"
CFG_DIR="/etc/lc-panel/php-cli"

# 1. Create configuration and library directories
install -d -m 0755 -o root -g root "${CFG_DIR}/users"
install -d -m 0755 -o root -g root "/usr/local/lib/lc-panel"

# 2. Write default server PHP version if not already set
if [[ ! -f "${CFG_DIR}/default" ]]; then
    printf "%s\n" "$DEFAULT_VERSION" > "${CFG_DIR}/default"
    chmod 0644 "${CFG_DIR}/default"
fi

# 3. Build static Go shim binary
CGO_ENABLED=0 go build -ldflags="-s -w" -o "${SHIM_BIN}.tmp" ./shim/main.go
chmod 0755 "${SHIM_BIN}.tmp"
mv -f "${SHIM_BIN}.tmp" "$SHIM_BIN"

# 4. Symlink CLI tools in /usr/local/bin
for tool in php phar phar.phar phpize php-config pecl pear; do
    ln -sfn "$SHIM_BIN" "/usr/local/bin/${tool}"
done

# 5. Protect OS /usr/bin/php: if it was previously linked to our shim, restore it safely
if [[ -L "/usr/bin/php" ]] && [[ "$(readlink -f /usr/bin/php)" == "$SHIM_BIN" ]]; then
    if [[ -e "/etc/alternatives/php" ]]; then
        ln -sfn "/etc/alternatives/php" "/usr/bin/php"
    else
        rm -f "/usr/bin/php"
        if command -v rpm >/dev/null 2>&1 && rpm -q php-cli >/dev/null 2>&1; then
            dnf reinstall -y php-cli >/dev/null 2>&1 || yum reinstall -y php-cli >/dev/null 2>&1 || true
        fi
    fi
fi

# 6. Ensure /usr/local/bin has priority in login shells across Debian/Ubuntu and RHEL/Alma/Rocky
cat << 'EOF' > /etc/profile.d/lc-panel-path.sh
case ":$PATH:" in
    /usr/local/bin:*) ;;
    *) export PATH="/usr/local/bin:$PATH" ;;
esac
EOF
chmod 0644 /etc/profile.d/lc-panel-path.sh

# 7. Restore SELinux contexts on RHEL/AlmaLinux/Rocky if restorecon is available
if command -v restorecon >/dev/null 2>&1; then
    restorecon -Rv /usr/local/lib/lc-panel /usr/local/bin /etc/lc-panel >/dev/null 2>&1 || true
fi
