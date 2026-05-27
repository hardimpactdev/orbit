#!/usr/bin/env bash

# Parallelized quality gate for Orbit.
#
# Static checks (docs-lint, phpstan, rector, pint) run concurrently in the
# background while the Pest suite runs in the foreground. Each background
# job writes to its own log file so output stays readable, and the gate
# exits non-zero if any single check failed.
#
# Defaults are read-only (rector --dry-run, pint --test). Pass
# `--fix` to apply rector + pint changes the same way the legacy
# `composer rector && composer format` invocation did.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

source "${ROOT}/bin/_orbit-gateway-paths.sh"

FIX_MODE=0
if [ "${1:-}" = "--fix" ]; then
    FIX_MODE=1
    shift
fi

LOG_DIR="$(mktemp -d)"
trap 'rm -rf "$LOG_DIR"' EXIT

RECTOR_ARGS=("--dry-run")
PINT_ARGS=("--test" "--format=agent")
if [ "$FIX_MODE" -eq 1 ]; then
    RECTOR_ARGS=()
    PINT_ARGS=("--format=agent")
fi

run_bg() {
    local label="$1"
    shift
    local log="$LOG_DIR/$label.log"
    ( "$@" >"$log" 2>&1; echo "$?" >"$LOG_DIR/$label.exit" ) &
    eval "${label}_PID=$!"
}

run_bg docs_lint bin/orbit-docs-artisan librarian:lint --format=agent --path=content/domains
run_bg docs_testing bin/orbit-docs-artisan librarian:lint --format=agent --path=content/testing
run_bg gateway_phpstan bin/orbit-gateway-vendor-bin phpstan analyse --memory-limit=512M --no-progress
run_bg gateway_rector bin/orbit-gateway-vendor-bin rector process "${RECTOR_ARGS[@]}"
run_bg gateway_pint bin/orbit-gateway-vendor-bin pint --config ../../pint.json "${PINT_ARGS[@]}"
run_bg cli_pint bash -lc 'cd apps/cli && vendor/bin/pint --config ../../pint.json "$@"' bash "${PINT_ARGS[@]}"
run_bg docs_pint bash -lc 'cd apps/docs && vendor/bin/pint --config ../../pint.json "$@"' bash "${PINT_ARGS[@]}"
run_bg cli_pest bin/orbit-cli-pest --compact
run_bg docs_pest bin/orbit-docs-pest --compact

bin/orbit-gateway-artisan config:clear --ansi >/dev/null 2>&1 || true
bin/orbit-gateway-pest --exclude-group=e2e --exclude-group=slow --parallel --compact "$@"
pest_exit=$?

wait "$docs_lint_PID" 2>/dev/null
wait "$docs_testing_PID" 2>/dev/null
wait "$gateway_phpstan_PID" 2>/dev/null
wait "$gateway_rector_PID"  2>/dev/null
wait "$gateway_pint_PID"    2>/dev/null
wait "$cli_pint_PID"        2>/dev/null
wait "$docs_pint_PID"       2>/dev/null
wait "$cli_pest_PID"        2>/dev/null
wait "$docs_pest_PID"       2>/dev/null

print_log() {
    local label="$1"
    local exit_file="$LOG_DIR/$label.exit"
    local log_file="$LOG_DIR/$label.log"
    local code
    code="$(cat "$exit_file" 2>/dev/null || echo 1)"
    if [ "$code" -ne 0 ] || [ -s "$log_file" ]; then
        echo "─── ${label} (exit=${code}) ───"
        cat "$log_file"
        echo
    fi
    return "$code"
}

print_log docs_lint; docs_lint_exit=$?
print_log docs_testing; docs_testing_exit=$?
print_log gateway_phpstan; gateway_phpstan_exit=$?
print_log gateway_rector;  gateway_rector_exit=$?
print_log gateway_pint;    gateway_pint_exit=$?
print_log cli_pint;        cli_pint_exit=$?
print_log docs_pint;       docs_pint_exit=$?
print_log cli_pest;        cli_pest_exit=$?
print_log docs_pest;       docs_pest_exit=$?

overall=$((pest_exit | docs_lint_exit | docs_testing_exit | gateway_phpstan_exit | gateway_rector_exit | gateway_pint_exit | cli_pint_exit | docs_pint_exit | cli_pest_exit | docs_pest_exit))
if [ "$overall" -ne 0 ]; then
    echo "Quality gate FAILED (gateway_pest=${pest_exit} docs=${docs_lint_exit} docs_testing=${docs_testing_exit} gateway_phpstan=${gateway_phpstan_exit} gateway_rector=${gateway_rector_exit} gateway_pint=${gateway_pint_exit} cli_pint=${cli_pint_exit} docs_pint=${docs_pint_exit} cli_pest=${cli_pest_exit} docs_pest=${docs_pest_exit})"
fi

exit "$overall"
