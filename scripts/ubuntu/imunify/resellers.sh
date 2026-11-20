#!/usr/bin/env bash
set -euo pipefail
LC_PANEL_DIR="${LC_PANEL_DIR:-/usr/local/litecontrol/panel}"
exec php "${LC_PANEL_DIR}/artisan" imunify:integration-data resellers
