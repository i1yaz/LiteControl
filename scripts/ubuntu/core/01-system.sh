#!/usr/bin/env bash
# ==============================================================================
# LiteControl Core 01: System Preparation, Base Packages, Swap & Panel User
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/common.sh
source "${SCRIPT_DIR}/../lib/common.sh"

suppress_needrestart() {
    if [[ -f /etc/needrestart/needrestart.conf ]]; then
        log_info "Configuring needrestart for non-interactive service restarts..."
        sed -i 's/^#\?\(\$nrconf{kernelhints} = \).*/\1 0;/' /etc/needrestart/needrestart.conf || true
        sed -i 's/^#\?\(\$nrconf{restart} = \).*/\1 "a";/' /etc/needrestart/needrestart.conf || true
    fi
}

install_base_packages() {
    log_step "01. Installing Essential Ubuntu Base Packages"
    suppress_needrestart
    apt_update
    apt_install \
        curl \
        wget \
        zip \
        unzip \
        tar \
        gzip \
        bzip2 \
        ca-certificates \
        gnupg \
        lsb-release \
        software-properties-common \
        apt-transport-https \
        acl \
        jq \
        openssl \
        cron \
        rsync \
        psmisc \
        bind9-dnsutils \
        socat \
        git \
         quota
    log_ok "Base packages installed."
}

setup_swap_if_needed() {
    local total_ram_mb
    total_ram_mb=$(free -m | awk '/^Mem:/{print $2}')
    local active_swap_mb
    active_swap_mb=$(free -m | awk '/^Swap:/{print $2}')

    if [[ "${active_swap_mb}" -gt 0 || -f /swapfile ]]; then
        log_info "Swap is already present (${active_swap_mb} MB). Skipping swap creation."
        return 0
    fi

    if [[ "${total_ram_mb}" -lt 2048 ]]; then
        log_info "Low memory detected (${total_ram_mb} MB RAM). Creating 2GB /swapfile..."
        if fallocate -l 2G /swapfile 2>/dev/null || dd if=/dev/zero of=/swapfile bs=1M count=2048 status=none; then
            chmod 600 /swapfile
            mkswap /swapfile >/dev/null
            swapon /swapfile || log_warn "Could not activate swapfile (containerized environment?)."
            if ! grep -q '/swapfile' /etc/fstab; then
                echo '/swapfile none swap sw 0 0' >> /etc/fstab
            fi
            log_ok "2GB swapfile configured."
        fi
    fi
}

create_litecontrol_user() {
    log_info "Ensuring isolated system user '${LC_USER}' exists..."
    if ! getent group "${LC_GROUP}" >/dev/null; then
        groupadd --system "${LC_GROUP}"
    fi

    if ! id -u "${LC_USER}" >/dev/null 2>&1; then
        useradd --system \
            --gid "${LC_GROUP}" \
            --home-dir "${LC_BASE_DIR}" \
            --no-create-home \
            --shell /usr/sbin/nologin \
            --comment "LiteControl Panel Service User" \
            "${LC_USER}"
        log_ok "Created system user '${LC_USER}'."
    else
        log_info "System user '${LC_USER}' already exists."
    fi

    # Ensure standard nobody/nogroup exist for OLS worker compatibility
    if ! getent group nogroup >/dev/null; then
        groupadd --system nogroup || true
    fi
}

save_os_metadata() {
    init_litecontrol_dirs
    printf "%s" "${OS_ID}" > "${LC_CONF_DIR}/os_name"
    printf "%s" "${OS_VERSION_FULL}" > "${LC_CONF_DIR}/os_version"
    printf "%s" "${ARCH}" > "${LC_CONF_DIR}/arch"
}

main() {
    require_root
    detect_ubuntu_os
    init_litecontrol_dirs
    install_base_packages
    setup_swap_if_needed
    create_litecontrol_user
    save_os_metadata
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi

