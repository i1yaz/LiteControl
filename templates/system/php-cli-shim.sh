#!/bin/sh
VER=""
DIR="$PWD"
while [ -n "$DIR" ]; do
    if [ -f "$DIR/.php-version" ]; then
        VER=$(head -n 1 "$DIR/.php-version" | tr -d '[:space:]')
        break
    fi
    [ "$DIR" = "/" ] && break
    DIR=$(dirname "$DIR")
done

if [ -z "$VER" ]; then
    USER_NAME=$(id -un 2>/dev/null)
    if [ -n "$USER_NAME" ] && [ -f "/etc/lc-panel/php-cli/users/$USER_NAME" ]; then
        VER=$(head -n 1 "/etc/lc-panel/php-cli/users/$USER_NAME" | tr -d '[:space:]')
    fi
fi

VER="${VER:-8.3}"
VER_NUM=$(echo "$VER" | tr -d '.')
BIN="/usr/local/lsws/lsphp${VER_NUM}/bin/php"

if [ ! -x "$BIN" ]; then
    BIN="/usr/local/lsws/lsphp83/bin/php"
fi

exec "$BIN" "$@"
