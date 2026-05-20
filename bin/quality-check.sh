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

run_bg docs_lint php artisan librarian:lint --format=agent --path=docs/domains
run_bg phpstan   vendor/bin/phpstan analyse --memory-limit=512M --no-progress
run_bg rector    vendor/bin/rector process "${RECTOR_ARGS[@]}"
run_bg pint      vendor/bin/pint "${PINT_ARGS[@]}"

php artisan config:clear --ansi >/dev/null 2>&1 || true
php -d memory_limit=512M vendor/pestphp/pest/bin/pest --exclude-group=e2e --exclude-group=slow --parallel --compact "$@"
pest_exit=$?

wait "$docs_lint_PID" 2>/dev/null
wait "$phpstan_PID"   2>/dev/null
wait "$rector_PID"    2>/dev/null
wait "$pint_PID"      2>/dev/null

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
print_log phpstan;   phpstan_exit=$?
print_log rector;    rector_exit=$?
print_log pint;      pint_exit=$?

overall=$((pest_exit | docs_lint_exit | phpstan_exit | rector_exit | pint_exit))
if [ "$overall" -ne 0 ]; then
    echo "Quality gate FAILED (pest=${pest_exit} docs=${docs_lint_exit} phpstan=${phpstan_exit} rector=${rector_exit} pint=${pint_exit})"
fi

exit "$overall"
