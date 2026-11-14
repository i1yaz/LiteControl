#!/usr/bin/env bash
# LiteControl CLI - File Manager operations
# Copyright (c) 2026 LiteControl

cmd_filemanager_list() {
    local target="${1:-}"
    if [[ -z "${target}" ]]; then
        emit_error "Path is required: lc-cli filemanager list <path>"
    fi
    if [[ ! -d "${target}" ]]; then
        emit_error "Directory not found: ${target}"
    fi

    local data_json
    data_json=$(python3 - "${target}" <<'PYEOF'
import json, os, stat, sys
from datetime import datetime, timezone

target = sys.argv[1]
items = []
try:
    entries = sorted(os.scandir(target), key=lambda e: (not e.is_dir(follow_symlinks=False), e.name.lower()))
except OSError as exc:
    sys.stderr.write(str(exc))
    sys.exit(1)

for entry in entries:
    try:
        st = entry.stat(follow_symlinks=False)
        is_dir = stat.S_ISDIR(st.st_mode)
        perms = oct(stat.S_IMODE(st.st_mode))[2:].zfill(4)
        mtime = datetime.fromtimestamp(st.st_mtime, tz=timezone.utc).isoformat()
        items.append({
            "name": entry.name,
            "path": entry.path,
            "type": "dir" if is_dir else "file",
            "size": 0 if is_dir else st.st_size,
            "permissions": perms,
            "modified_at": mtime,
        })
    except OSError:
        continue

print(json.dumps(items))
PYEOF
) || emit_error "Failed to list directory: ${target}"

    emit_success "Directory listed." "${data_json}"
}

cmd_filemanager_read() {
    local target="${1:-}"
    local max_bytes="${2:-5242880}"
    if [[ -z "${target}" ]]; then
        emit_error "Path is required: lc-cli filemanager read <path> [max_bytes]"
    fi
    if [[ ! -f "${target}" ]]; then
        emit_error "File not found: ${target}"
    fi

    local data_json
    data_json=$(python3 - "${target}" "${max_bytes}" <<'PYEOF'
import json, os, sys

target = sys.argv[1]
max_bytes = int(sys.argv[2])
size = os.path.getsize(target)
if size > max_bytes:
    sys.stderr.write(f"File exceeds maximum readable size ({max_bytes} bytes).")
    sys.exit(1)

with open(target, "rb") as f:
    raw = f.read()

content = raw.decode("utf-8", errors="replace")
print(json.dumps({"content": content, "size": size}))
PYEOF
) || emit_error "Failed to read file: ${target}"

    emit_success "File read." "${data_json}"
}

cmd_filemanager_write() {
    local target="${1:-}"
    local b64="${2:-}"
    if [[ -z "${target}" ]]; then
        emit_error "Path is required: lc-cli filemanager write <path> <base64_content>"
    fi

    python3 - "${target}" "${b64}" <<'PYEOF' || emit_error "Failed to write file: ${target}"
import base64, os, sys

target = sys.argv[1]
b64 = sys.argv[2]
os.makedirs(os.path.dirname(target) or ".", exist_ok=True)
data = base64.b64decode(b64) if b64 else b""
with open(target, "wb") as f:
    f.write(data)
PYEOF

    emit_success "File saved." "{}"
}

cmd_filemanager_create() {
    local target="${1:-}"
    local type="${2:-file}"
    if [[ -z "${target}" ]]; then
        emit_error "Path is required: lc-cli filemanager create <path> <file|dir>"
    fi
    if [[ -e "${target}" ]]; then
        emit_error "Entry already exists: ${target}"
    fi

    if [[ "${type}" == "dir" ]]; then
        mkdir -p "${target}" || emit_error "Failed to create directory: ${target}"
    else
        mkdir -p "$(dirname "${target}")" && touch "${target}" || emit_error "Failed to create file: ${target}"
    fi

    emit_success "Entry created." "{}"
}

cmd_filemanager_delete() {
    local target="${1:-}"
    if [[ -z "${target}" || "${target}" == "/" ]]; then
        emit_error "Invalid path: ${target}"
    fi
    if [[ ! -e "${target}" && ! -L "${target}" ]]; then
        emit_error "Entry not found: ${target}"
    fi

    rm -rf "${target}" || emit_error "Failed to delete entry: ${target}"
    emit_success "Entry deleted." "{}"
}

cmd_filemanager_chmod() {
    local target="${1:-}"
    local mode="${2:-}"
    if [[ -z "${target}" || -z "${mode}" ]]; then
        emit_error "Usage: lc-cli filemanager chmod <path> <octal_mode>"
    fi
    if [[ ! -e "${target}" ]]; then
        emit_error "Entry not found: ${target}"
    fi

    chmod "${mode}" "${target}" || emit_error "Failed to change permissions on: ${target}"
    emit_success "Permissions updated." "{\"permissions\":\"${mode}\"}"
}

cmd_filemanager_mkdir() {
    local target="${1:-}"
    if [[ -z "${target}" ]]; then
        emit_error "Path is required: lc-cli filemanager mkdir <path>"
    fi

    mkdir -p "${target}" || emit_error "Failed to create directory: ${target}"
    emit_success "Directory ensured." "{}"
}

cmd_filemanager_main() {
    local sub="${1:-}"
    shift || true
    case "${sub}" in
        list)   cmd_filemanager_list "$@" ;;
        read)   cmd_filemanager_read "$@" ;;
        write)  cmd_filemanager_write "$@" ;;
        create) cmd_filemanager_create "$@" ;;
        delete) cmd_filemanager_delete "$@" ;;
        chmod)  cmd_filemanager_chmod "$@" ;;
        mkdir)  cmd_filemanager_mkdir "$@" ;;
        *)      emit_error "Unknown filemanager subcommand: ${sub}" ;;
    esac
}
