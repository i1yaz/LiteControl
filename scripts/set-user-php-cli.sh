#!/usr/bin/env bash
set -euo pipefail

USERNAME="${1:-}"
VERSION="${2:-}"

if [[ ! "$USERNAME" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]]; then
    echo "ERR_INVALID_USER: Username '$USERNAME' failed validation" >&2
    exit 2
fi

if [[ ! "$VERSION" =~ ^[5-9]\.[0-9]$ ]]; then
    echo "ERR_INVALID_VERSION: Version '$VERSION' must match X.Y" >&2
    exit 2
fi

id "$USERNAME" >/dev/null 2>&1 || { echo "ERR_USER_NOT_FOUND: OS user '$USERNAME' does not exist" >&2; exit 3; }

LS_VER="${VERSION//./}"
TARGET_BIN="/usr/local/lsws/lsphp${LS_VER}/bin/php"
[[ -x "$TARGET_BIN" ]] || { echo "ERR_BINARY_MISSING: '$TARGET_BIN' is not installed or executable" >&2; exit 4; }

CFG_DIR="/etc/lc-panel/php-cli/users"
install -d -m 0755 -o root -g root "$CFG_DIR"

TMP_FILE="${CFG_DIR}/.${USERNAME}.tmp.$$"
printf "%s\n" "$VERSION" > "$TMP_FILE"
chmod 0644 "$TMP_FILE"
mv -f "$TMP_FILE" "${CFG_DIR}/${USERNAME}"

# Verify effective CLI version AND ensure SAPI is 'cli' (not 'litespeed' or 'cgi-fcgi')
VERIFY_OUT=$(sudo -u "$USERNAME" -i /usr/local/bin/php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.':'.PHP_SAPI;")
EXPECTED_OUT="${VERSION}:cli"

if [[ "$VERIFY_OUT" != "$EXPECTED_OUT" ]]; then
    echo "ERR_VERIFICATION_FAILED: Expected '$EXPECTED_OUT' but resolved '$VERIFY_OUT'" >&2
    exit 5
fi
