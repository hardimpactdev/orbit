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

run_bg docs_lint bin/orbit-docs-artisan librarian:lint --format=agent --path=domains
run_bg docs_testing bin/orbit-docs-artisan librarian:lint --format=agent --path=testing
run_bg docs_references bin/orbit-docs-artisan librarian:lint --format=agent --group=references

run_bg gateway_phpstan bin/orbit-gateway-vendor-bin phpstan analyse --memory-limit=512M --no-progress
run_bg cli_phpstan bash -lc 'cd apps/cli && vendor/bin/phpstan analyse --memory-limit=512M --no-progress'
run_bg docs_phpstan bash -lc 'cd apps/docs && vendor/bin/phpstan analyse --memory-limit=512M --no-progress'
run_bg core_phpstan bash -lc 'cd packages/core && vendor/bin/phpstan analyse --memory-limit=512M --no-progress'
run_bg sdk_phpstan bash -lc 'cd packages/sdk && vendor/bin/phpstan analyse --memory-limit=512M --no-progress'
run_bg e2e_phpstan bash -lc 'cd apps/e2e && vendor/bin/phpstan analyse --memory-limit=512M --no-progress'

run_bg gateway_rector bin/orbit-gateway-vendor-bin rector process "${RECTOR_ARGS[@]}"
run_bg cli_rector bash -lc 'cd apps/cli && vendor/bin/rector process "$@"' bash "${RECTOR_ARGS[@]}"
run_bg docs_rector bash -lc 'cd apps/docs && vendor/bin/rector process "$@"' bash "${RECTOR_ARGS[@]}"
run_bg core_rector bash -lc 'cd packages/core && vendor/bin/rector process "$@"' bash "${RECTOR_ARGS[@]}"
run_bg sdk_rector bash -lc 'cd packages/sdk && vendor/bin/rector process "$@"' bash "${RECTOR_ARGS[@]}"
run_bg e2e_rector bash -lc 'cd apps/e2e && vendor/bin/rector process "$@"' bash "${RECTOR_ARGS[@]}"

run_bg gateway_pint bin/orbit-gateway-vendor-bin pint "${PINT_ARGS[@]}"
run_bg cli_pint bash -lc 'cd apps/cli && vendor/bin/pint "$@"' bash "${PINT_ARGS[@]}"
run_bg docs_pint bash -lc 'cd apps/docs && vendor/bin/pint "$@"' bash "${PINT_ARGS[@]}"
run_bg core_pint bash -lc 'cd packages/core && vendor/bin/pint "$@"' bash "${PINT_ARGS[@]}"
run_bg sdk_pint bash -lc 'cd packages/sdk && vendor/bin/pint "$@"' bash "${PINT_ARGS[@]}"
run_bg e2e_pint bash -lc 'cd apps/e2e && vendor/bin/pint "$@"' bash "${PINT_ARGS[@]}"

run_bg cli_pest bin/orbit-cli-pest --compact
run_bg docs_pest bin/orbit-docs-pest --compact

bin/orbit-gateway-artisan config:clear --ansi >/dev/null 2>&1 || true
bin/orbit-gateway-pest --exclude-group=e2e --exclude-group=slow --parallel --compact "$@"
pest_exit=$?

CHECK_LABELS=(
    docs_lint
    docs_testing
    docs_references
    gateway_phpstan
    cli_phpstan
    docs_phpstan
    core_phpstan
    sdk_phpstan
    e2e_phpstan
    gateway_rector
    cli_rector
    docs_rector
    core_rector
    sdk_rector
    e2e_rector
    gateway_pint
    cli_pint
    docs_pint
    core_pint
    sdk_pint
    e2e_pint
    cli_pest
    docs_pest
    core_pest
    sdk_pest
    e2e_pest
)

for label in "${CHECK_LABELS[@]}"; do
    if [ "$label" = core_pest ] || [ "$label" = sdk_pest ] || [ "$label" = e2e_pest ]; then
        continue
    fi

    pid_var="${label}_PID"
    wait "${!pid_var}" 2>/dev/null
done

# The E2E support tests compute checkout archive hashes from the working tree.
# Run them after static/style lanes so generated cache metadata cannot change
# the tree hash mid-test.
( cd apps/e2e && vendor/bin/pest --exclude-group=e2e-binary --exclude-group=e2e-binary-acceptance --exclude-group=e2e-feature --exclude-group=e2e-provision --exclude-group=e2e-topology-contract --compact >"$LOG_DIR/e2e_pest.log" 2>&1; echo "$?" >"$LOG_DIR/e2e_pest.exit" )

# The core progress tests intentionally fork short-lived ticker children. Keep
# this lane out of the background fan-out so unrelated Pest suites cannot
# deliver process-group signals to the core Pest parent.
( cd packages/core && vendor/bin/pest --compact >"$LOG_DIR/core_pest.log" 2>&1; echo "$?" >"$LOG_DIR/core_pest.exit" )

( cd packages/sdk && vendor/bin/pest --compact >"$LOG_DIR/sdk_pest.log" 2>&1; echo "$?" >"$LOG_DIR/sdk_pest.exit" )

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

overall="$pest_exit"
summary="gateway_pest=${pest_exit}"

for label in "${CHECK_LABELS[@]}"; do
    print_log "$label"
    code=$?
    overall=$((overall | code))
    summary="${summary} ${label}=${code}"
done

if [ "$overall" -ne 0 ]; then
    echo "Quality gate FAILED (${summary})"
fi

exit "$overall"
