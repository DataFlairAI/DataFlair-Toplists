#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# DataFlair Toplists — E2E Test Runner
#
# Auto-detects the execution environment:
#   - Local (wp-env Docker): wraps commands in `docker exec … wp eval-file`
#   - Any WP install (prod/CI/bare server): runs `wp eval-file` directly
#
# Usage:
#   ./tests/e2e/run.sh              # run all suites
#   ./tests/e2e/run.sh brands       # brands only
#   ./tests/e2e/run.sh toplists     # toplists only
#   ./tests/e2e/run.sh cron         # cron only
#
# Environment variables (override auto-detection):
#   WP_CLI_CMD   Full command prefix, e.g. "wp --allow-root" or
#                "docker exec my-cli wp --allow-root"
#   PLUGIN_PATH  Absolute path to the plugin inside the WP install.
#                Defaults to the directory containing this script's parent.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR_LOCAL="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# Docker container name used by wp-env for this project
DOCKER_CLI_CONTAINER="a9847bd851c206384f7d6ed5dd767c89-cli-1"
DOCKER_PLUGIN_PATH="/var/www/html/wp-content/plugins/DataFlair-Toplists"

# Colours
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BOLD='\033[1m'; RESET='\033[0m'

SUITE="${1:-all}"
TOTAL_PASS=0
TOTAL_FAIL=0

# ── Detect execution environment ──────────────────────────────────────────────

echo ""
echo -e "${BOLD}DataFlair Toplists — E2E Test Runner${RESET}"
echo "────────────────────────────────────────"

if [ -n "${WP_CLI_CMD:-}" ]; then
    # Explicit override via env var
    WP_CMD="${WP_CLI_CMD}"
    PLUGIN_PATH="${PLUGIN_PATH:-${PLUGIN_DIR_LOCAL}}"
    echo -e "${GREEN}✓${RESET} Using WP_CLI_CMD override: ${WP_CMD}"
elif docker info &>/dev/null 2>&1 && docker ps --format '{{.Names}}' 2>/dev/null | grep -q "^${DOCKER_CLI_CONTAINER}$"; then
    # Local wp-env Docker setup
    WP_CMD="docker exec ${DOCKER_CLI_CONTAINER} wp --allow-root"
    PLUGIN_PATH="${DOCKER_PLUGIN_PATH}"
    echo -e "${GREEN}✓${RESET} Docker wp-env detected — running inside container"
elif command -v wp &>/dev/null && wp --allow-root eval 'echo "ok";' &>/dev/null 2>&1; then
    # WP-CLI available directly (production / CI / bare WP install)
    WP_CMD="wp --allow-root"
    PLUGIN_PATH="${PLUGIN_DIR_LOCAL}"
    echo -e "${GREEN}✓${RESET} Running via local WP-CLI (production/CI mode)"
elif command -v wp &>/dev/null && wp eval 'echo "ok";' &>/dev/null 2>&1; then
    WP_CMD="wp"
    PLUGIN_PATH="${PLUGIN_DIR_LOCAL}"
    echo -e "${GREEN}✓${RESET} Running via local WP-CLI"
else
    echo -e "${RED}✗ Cannot find a working WP-CLI or Docker container.${RESET}"
    echo ""
    echo "Options:"
    echo "  1. Start wp-env:   npx wp-env start"
    echo "  2. Set override:   WP_CLI_CMD='wp --allow-root' ./tests/e2e/run.sh"
    exit 1
fi

# ── Verify WordPress is responsive ───────────────────────────────────────────

if ! ${WP_CMD} eval 'echo "ok";' &>/dev/null 2>&1; then
    echo -e "${RED}✗ WordPress is not responding${RESET}"
    exit 1
fi
echo -e "${GREEN}✓${RESET} WordPress is responsive"

# ── Check API token ───────────────────────────────────────────────────────────

TOKEN=$(${WP_CMD} option get dataflair_api_token 2>/dev/null || echo "")
if [ -z "${TOKEN}" ]; then
    echo -e "${YELLOW}⚠ dataflair_api_token is not set — live sync tests will be skipped${RESET}"
else
    echo -e "${GREEN}✓${RESET} API token is set (${TOKEN:0:12}…)"
fi

echo ""

# ── Suite runner ──────────────────────────────────────────────────────────────

run_suite() {
    local name="$1"
    local file="$2"

    echo -e "${BOLD}Running: ${name}${RESET}"
    echo "────────────────────────────────────────"

    set +e
    ${WP_CMD} eval-file "${PLUGIN_PATH}/tests/e2e/${file}"
    EXIT_CODE=$?
    set -e

    if [ "${EXIT_CODE}" -eq 0 ]; then
        echo -e "${GREEN}${name}: PASSED${RESET}"
        TOTAL_PASS=$((TOTAL_PASS + 1))
    else
        echo -e "${RED}${name}: FAILED${RESET}"
        TOTAL_FAIL=$((TOTAL_FAIL + 1))
    fi
    echo ""
}

case "${SUITE}" in
    brands)         run_suite "Brand Sync"        "test-brand-sync.php" ;;
    toplists)       run_suite "Toplist Sync"      "test-toplist-sync.php" ;;
    cron)           run_suite "Cron Removal"      "test-cron.php" ;;
    review-link)    run_suite "Review Link"       "test-read-review-link.php" ;;
    shortcode)      run_suite "Shortcode + Block" "test-shortcode-and-block.php" ;;
    all)
        run_suite "Brand Sync"        "test-brand-sync.php"
        run_suite "Toplist Sync"      "test-toplist-sync.php"
        run_suite "Cron Removal"      "test-cron.php"
        run_suite "Review Link"       "test-read-review-link.php"
        run_suite "Shortcode + Block" "test-shortcode-and-block.php"
        ;;
    *)
        echo "Unknown suite: ${SUITE}"
        echo "Usage: $0 [all|brands|toplists|cron|review-link|shortcode]"
        echo "Note: 'cron' suite asserts Phase 0B removed all cron registrations."
        exit 1
        ;;
esac

# ── Summary ───────────────────────────────────────────────────────────────────

echo "════════════════════════════════════════"
echo -e "${BOLD}E2E Results: ${GREEN}${TOTAL_PASS} suite(s) passed${RESET}, ${RED}${TOTAL_FAIL} suite(s) failed${RESET}"
echo "════════════════════════════════════════"
echo ""

exit $(( TOTAL_FAIL > 0 ? 1 : 0 ))
