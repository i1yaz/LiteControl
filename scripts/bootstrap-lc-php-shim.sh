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

# 5. Also link /usr/bin/php so restricted PATH environments (cron, systemd) hit the shim
ln -sfn "$SHIM_BIN" "/usr/bin/php"
