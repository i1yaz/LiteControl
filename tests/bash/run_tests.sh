#!/usr/bin/env bash
# ==============================================================================
# LiteControl - Automated Bash Unit Test Runner
# Runs on both Linux and Windows (via Git Bash) without requiring root privileges
# ==============================================================================

set -euo pipefail

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${TESTS_DIR}/../.." && pwd)"

TOTAL_SUITES=0
PASSED_SUITES=0
FAILED_SUITES=0

echo "======================================================================"
echo " LiteControl Bash Unit Test Runner"
echo " Workspace: ${REPO_ROOT}"
echo "======================================================================"

filter="${1:-}"

for test_file in "${TESTS_DIR}"/test_*.sh; do
    [[ -e "${test_file}" ]] || continue
    suite_name="$(basename "${test_file}")"

    if [[ -n "${filter}" && "${suite_name}" != *"${filter}"* ]]; then
        continue
    fi

    TOTAL_SUITES=$((TOTAL_SUITES + 1))
    echo ""
    echo "---> Running Suite: ${suite_name}"
    if bash "${test_file}"; then
        PASSED_SUITES=$((PASSED_SUITES + 1))
        echo "[PASS] Suite ${suite_name} succeeded."
    else
        FAILED_SUITES=$((FAILED_SUITES + 1))
        echo "[FAIL] Suite ${suite_name} failed!"
    fi
done

echo ""
echo "======================================================================"
echo " Test Summary: ${PASSED_SUITES}/${TOTAL_SUITES} suites passed (${FAILED_SUITES} failed)"
echo "======================================================================"

if [[ "${FAILED_SUITES}" -gt 0 ]]; then
    exit 1
fi
exit 0

