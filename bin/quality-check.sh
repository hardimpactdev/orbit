#!/usr/bin/env bash

# Parallelized quality gate for Orbit.
#
# Static checks (docs-lint, phpstan, rector, pint) run concurrently in a
# capped background pool while Pest lanes run alongside them. Each background
# job writes to its own log file so output stays readable, and the gate exits
# non-zero if any single check failed.
#
# Defaults are read-only (rector --dry-run, pint --test). Pass
# `--fix` to apply rector + pint changes the same way the legacy
# `composer rector && composer format` invocation did.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

source "${ROOT}/bin/_orbit-gateway-paths.sh"

STARTED_AT="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
ARTIFACT_DIR="${ORBIT_QUALITY_GATES_DIR:-${ROOT}/.orbit/quality-gates}"
GIT_BRANCH="$(git -C "$ROOT" branch --show-current 2>/dev/null || echo unknown)"
GIT_COMMIT="$(git -C "$ROOT" rev-parse HEAD 2>/dev/null || echo unknown)"

FIX_MODE=0
if [ "${1:-}" = "--fix" ]; then
    FIX_MODE=1
    shift
fi

MODE="check"
COMMAND="composer quality-check"
if [ "$FIX_MODE" -eq 1 ]; then
    MODE="fix"
    COMMAND="composer quality-check:fix"
fi

LOG_DIR="$(mktemp -d)"
trap 'rm -rf "$LOG_DIR"' EXIT

RECTOR_ARGS=("--dry-run")
PINT_ARGS=("--test" "--format=agent")
if [ "$FIX_MODE" -eq 1 ]; then
    RECTOR_ARGS=()
    PINT_ARGS=("--format=agent")
fi

quality_check_default_max_background_jobs() {
    local detected_jobs

    detected_jobs="$(getconf _NPROCESSORS_ONLN 2>/dev/null || sysctl -n hw.logicalcpu 2>/dev/null || echo 8)"

    if ! [[ "$detected_jobs" =~ ^[0-9]+$ ]] || [ "$detected_jobs" -lt 1 ]; then
        echo 4
        return
    fi

    if [ "$detected_jobs" -le 4 ]; then
        echo 2
        return
    fi

    echo $((detected_jobs / 2))
}

DEFAULT_MAX_BACKGROUND_JOBS="$(quality_check_default_max_background_jobs)"
MAX_BACKGROUND_JOBS="${ORBIT_QUALITY_CHECK_MAX_BACKGROUND_JOBS:-$DEFAULT_MAX_BACKGROUND_JOBS}"

if ! [[ "$MAX_BACKGROUND_JOBS" =~ ^[0-9]+$ ]] || [ "$MAX_BACKGROUND_JOBS" -lt 1 ]; then
    MAX_BACKGROUND_JOBS="$DEFAULT_MAX_BACKGROUND_JOBS"
fi

running_bg_jobs() {
    jobs -pr | wc -l | tr -d '[:space:]'
}

wait_for_bg_label() {
    local label="$1"
    local pid_var="${label}_PID"

    wait "${!pid_var}" 2>/dev/null
}

wait_for_bg_slot() {
    while [ "$(running_bg_jobs)" -ge "$MAX_BACKGROUND_JOBS" ]; do
        sleep 0.2
    done
}

record_subgate_start() {
    local label="$1"
    php -r 'file_put_contents($argv[1], (string) microtime(true));' "$LOG_DIR/$label.start"
}

record_subgate_duration() {
    local label="$1"
    local start_file="$LOG_DIR/$label.start"

    if [ ! -f "$start_file" ]; then
        return
    fi

    php -r 'echo round(max(0, microtime(true) - (float) file_get_contents($argv[1])), 1);' "$start_file" >"$LOG_DIR/$label.duration"
}

subgate_duration_seconds() {
    local label="$1"
    local duration_file="$LOG_DIR/$label.duration"

    if [ ! -f "$duration_file" ]; then
        return
    fi

    cat "$duration_file"
}

run_bg() {
    local label="$1"
    shift
    local log="$LOG_DIR/$label.log"
    wait_for_bg_slot
    record_subgate_start "$label"
    ( "$@" >"$log" 2>&1; code="$?"; record_subgate_duration "$label"; echo "$code" >"$LOG_DIR/$label.exit"; exit "$code" ) &
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

run_bg cli_pest bin/orbit-cli-pest --exclude-group=slow --compact
run_bg docs_pest bin/orbit-docs-pest --compact

bin/orbit-gateway-artisan config:clear --ansi >/dev/null 2>&1 || true
run_bg gateway_pest bin/orbit-gateway-pest --exclude-group=e2e --exclude-group=slow --parallel --compact "$@"

STATIC_CHECK_LABELS=(
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
)

LONG_RUNNING_PEST_LABELS=(
    gateway_pest
    cli_pest
    docs_pest
)

CHECK_LABELS=(
    gateway_pest
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

for label in "${STATIC_CHECK_LABELS[@]}"; do
    wait_for_bg_label "$label"
done

# The E2E support tests compute checkout archive hashes from the working tree.
for label in "${LONG_RUNNING_PEST_LABELS[@]}"; do
    wait_for_bg_label "$label"
done

# Run them after static/style and other Pest lanes so generated cache/runtime
# metadata cannot change the tree hash mid-test.
record_subgate_start e2e_pest
( cd apps/e2e && vendor/bin/pest --exclude-group=e2e-binary --exclude-group=e2e-binary-acceptance --exclude-group=e2e-feature --exclude-group=e2e-provision --exclude-group=e2e-topology-contract --compact >"$LOG_DIR/e2e_pest.log" 2>&1; echo "$?" >"$LOG_DIR/e2e_pest.exit" )
record_subgate_duration e2e_pest

# The core progress tests intentionally fork short-lived ticker children. Keep
# this lane out of the background fan-out so unrelated Pest suites cannot
# deliver process-group signals to the core Pest parent.
record_subgate_start core_pest
( cd packages/core && vendor/bin/pest --compact >"$LOG_DIR/core_pest.log" 2>&1; echo "$?" >"$LOG_DIR/core_pest.exit" )
record_subgate_duration core_pest

record_subgate_start sdk_pest
( cd packages/sdk && vendor/bin/pest --compact >"$LOG_DIR/sdk_pest.log" 2>&1; echo "$?" >"$LOG_DIR/sdk_pest.exit" )
record_subgate_duration sdk_pest

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

overall=0
summary=""
SUBGATE_ARGS=()
SUBGATE_DURATION_ARGS=()

for label in "${CHECK_LABELS[@]}"; do
    print_log "$label"
    code=$?
    overall=$((overall | code))
    if [ -z "$summary" ]; then
        summary="${label}=${code}"
    else
        summary="${summary} ${label}=${code}"
    fi
    SUBGATE_ARGS+=(--subgate="${label}=${code}")

    duration="$(subgate_duration_seconds "$label")"
    if [ -n "$duration" ]; then
        SUBGATE_DURATION_ARGS+=(--subgate-duration="${label}=${duration}")
    fi
done

if [ "$overall" -ne 0 ]; then
    echo "Quality gate FAILED (${summary})"
fi

ENDED_AT="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
php "${ROOT}/bin/quality-gate-write-artifact" \
    --gate=quality-check \
    --command="$COMMAND" \
    --mode="$MODE" \
    --started-at="$STARTED_AT" \
    --ended-at="$ENDED_AT" \
    --exit-code="$overall" \
    --git-branch="$GIT_BRANCH" \
    --git-commit="$GIT_COMMIT" \
    --artifact-dir="$ARTIFACT_DIR" \
    "${SUBGATE_ARGS[@]}" \
    "${SUBGATE_DURATION_ARGS[@]}" >/dev/null 2>&1 || true

exit "$overall"
