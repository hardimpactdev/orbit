#!/usr/bin/env bash

# Parallelized quality gate for Orbit.
#
# Static checks (docs-lint, Mago, rector) run concurrently in a
# capped background pool while Pest lanes run alongside them. Each background
# job writes to its own log file so output stays readable, and the gate exits
# non-zero if any single check failed.
#
# Defaults are read-only (rector --dry-run, mago format --check). Pass
# `--fix` to apply rector + Mago changes the same way the legacy
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

quality_check_cleanup() {
    if [ -n "${PROGRESS_TICKER_PID:-}" ]; then
        : >"$LOG_DIR/progress.stop"
        wait "$PROGRESS_TICKER_PID" 2>/dev/null || true
    fi

    if [ "${PROGRESS_CURSOR_HIDDEN:-0}" -eq 1 ]; then
        printf '\e[?25h'
    fi

    rm -rf "$LOG_DIR"
}

trap quality_check_cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

RECTOR_ARGS=("--dry-run")
MAGO_LINT_ARGS=("--reporting-format=medium")
MAGO_FORMAT_ARGS=("--check")
if [ "$FIX_MODE" -eq 1 ]; then
    RECTOR_ARGS=()
    MAGO_LINT_ARGS=("--fix" "--format-after-fix" "--fail-on-remaining")
    MAGO_FORMAT_ARGS=()
fi

quality_check_default_max_background_jobs() {
    local detected_jobs

    detected_jobs="$(getconf _NPROCESSORS_ONLN 2>/dev/null || sysctl -n hw.logicalcpu 2>/dev/null || echo 8)"

    if ! [[ "$detected_jobs" =~ ^[0-9]+$ ]] || [ "$detected_jobs" -lt 1 ]; then
        echo 4
        return
    fi

    if [ "$detected_jobs" -le 1 ]; then
        echo 1
        return
    fi

    echo 2
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

PROGRESS_AREAS=(
    apps/gateway
    apps/cli
    apps/docs
    apps/e2e
    apps/reverb
    packages/core
    packages/sdk
)

PROGRESS_DIM=$'\e[38;5;242m'
PROGRESS_ACCENT=$'\e[97m'
PROGRESS_GREEN=$'\e[32m'
PROGRESS_RED=$'\e[31m'
PROGRESS_CYAN=$'\e[36m'
PROGRESS_RESET=$'\e[39m'

if [ -n "${NO_COLOR:-}" ]; then
    PROGRESS_DIM=""
    PROGRESS_ACCENT=""
    PROGRESS_GREEN=""
    PROGRESS_RED=""
    PROGRESS_CYAN=""
    PROGRESS_RESET=""
fi

PROGRESS_SPINNER_FRAMES=(
    "${PROGRESS_CYAN}○${PROGRESS_RESET}"
    "${PROGRESS_CYAN}◉${PROGRESS_RESET}"
)
PROGRESS_TICKER_PID=""
PROGRESS_TREE_LINES=0
PROGRESS_CURSOR_HIDDEN=0

quality_check_progress_enabled() {
    [ -t 1 ]
}

quality_check_progress_print_line() {
    printf '\e[2K'
    printf "$@"
}

quality_check_label_area() {
    case "$1" in
        gateway_*)
            echo apps/gateway
            ;;
        cli_*)
            echo apps/cli
            ;;
        docs_*)
            echo apps/docs
            ;;
        e2e_*)
            echo apps/e2e
            ;;
        reverb_*)
            echo apps/reverb
            ;;
        core_*)
            echo packages/core
            ;;
        sdk_*)
            echo packages/sdk
            ;;
        *)
            return 1
            ;;
    esac
}

quality_check_area_row_state() {
    local area="$1"
    local total=0
    local started=0
    local completed=0
    local failures=0
    local label
    local label_area
    local code

    for label in "${CHECK_LABELS[@]}"; do
        label_area="$(quality_check_label_area "$label" || true)"

        if [ "$label_area" != "$area" ]; then
            continue
        fi

        total=$((total + 1))

        if [ -f "$LOG_DIR/$label.start" ] || [ -f "$LOG_DIR/$label.exit" ]; then
            started=$((started + 1))
        fi

        if [ -f "$LOG_DIR/$label.exit" ]; then
            completed=$((completed + 1))
            code="$(cat "$LOG_DIR/$label.exit" 2>/dev/null || echo 1)"

            if [ "$code" -ne 0 ]; then
                failures=$((failures + 1))
            fi
        fi
    done

    if [ "$total" -eq 0 ]; then
        echo waiting
        return
    fi

    if [ "$completed" -eq "$total" ]; then
        if [ "$failures" -gt 0 ]; then
            echo failed
        else
            echo passed
        fi

        return
    fi

    if [ "$started" -gt 0 ]; then
        echo running
        return
    fi

    echo waiting
}

quality_check_area_row_line() {
    local area="$1"
    local state="$2"
    local tick="$3"
    local frame

    case "$state" in
        waiting)
            quality_check_progress_print_line '  %s○%s  %s%s%s\n' "$PROGRESS_DIM" "$PROGRESS_RESET" "$PROGRESS_DIM" "$area" "$PROGRESS_RESET"
            ;;
        running)
            frame="${PROGRESS_SPINNER_FRAMES[$((tick % ${#PROGRESS_SPINNER_FRAMES[@]}))]}"
            quality_check_progress_print_line '  %s  %s    Running\n' "$frame" "$area"
            ;;
        passed)
            quality_check_progress_print_line '  %s●%s  %s    Passed\n' "$PROGRESS_GREEN" "$PROGRESS_RESET" "$area"
            ;;
        failed)
            quality_check_progress_print_line '  %s●%s  %s    Failed\n' "$PROGRESS_RED" "$PROGRESS_RESET" "$area"
            ;;
        *)
            quality_check_progress_print_line '  %s○%s  %s%s%s\n' "$PROGRESS_DIM" "$PROGRESS_RESET" "$PROGRESS_DIM" "$area" "$PROGRESS_RESET"
            ;;
    esac
}

quality_check_progress_tree_line_count() {
    echo $((1 + (2 * ${#PROGRESS_AREAS[@]}) + 1 + 1))
}

quality_check_progress_overall_failed() {
    local area
    local state

    for area in "${PROGRESS_AREAS[@]}"; do
        state="$(quality_check_area_row_state "$area")"

        if [ "$state" = "failed" ]; then
            return 0
        fi
    done

    return 1
}

quality_check_progress_footer_line() {
    local phase="$1"
    local failed=0

    if [ "$phase" = "final" ] && quality_check_progress_overall_failed; then
        failed=1
    fi

    if [ "$phase" = "pending" ]; then
        quality_check_progress_print_line '  %s└%s  %sWorking...%s\n' "$PROGRESS_DIM" "$PROGRESS_RESET" "$PROGRESS_DIM" "$PROGRESS_RESET"
        return
    fi

    if [ "$failed" -ne 0 ]; then
        quality_check_progress_print_line '  %s└%s  %sQuality checks failed%s\n' "$PROGRESS_DIM" "$PROGRESS_RESET" "$PROGRESS_RED" "$PROGRESS_RESET"
        return
    fi

    quality_check_progress_print_line '  %s└%s  %sQuality checks passed%s\n' "$PROGRESS_DIM" "$PROGRESS_RESET" "$PROGRESS_ACCENT" "$PROGRESS_RESET"
}

quality_check_progress_draw_tree() {
    local tick="${1:-0}"
    local phase="${2:-pending}"
    local include_leading_blank="${3:-0}"
    local area
    local state

    if [ "$include_leading_blank" -eq 1 ]; then
        echo
    fi

    quality_check_progress_print_line '  %s┌%s  %sRunning quality checks%s\n' "$PROGRESS_DIM" "$PROGRESS_RESET" "$PROGRESS_ACCENT" "$PROGRESS_RESET"

    for area in "${PROGRESS_AREAS[@]}"; do
        quality_check_progress_print_line '  %s│%s\n' "$PROGRESS_DIM" "$PROGRESS_RESET"
        state="$(quality_check_area_row_state "$area")"
        quality_check_area_row_line "$area" "$state" "$tick"
    done

    quality_check_progress_print_line '  %s│%s\n' "$PROGRESS_DIM" "$PROGRESS_RESET"
    quality_check_progress_footer_line "$phase"
}

quality_check_progress_all_complete() {
    local label

    for label in "${CHECK_LABELS[@]}"; do
        if [ ! -f "$LOG_DIR/$label.exit" ]; then
            return 1
        fi
    done

    return 0
}

quality_check_progress_start_ticker() {
    local tick=0

    if ! quality_check_progress_enabled; then
        return
    fi

    PROGRESS_TREE_LINES="$(quality_check_progress_tree_line_count)"
    quality_check_progress_draw_tree 0 pending 1
    printf '\e[?25l'
    PROGRESS_CURSOR_HIDDEN=1

    (
        while [ ! -f "$LOG_DIR/progress.stop" ]; do
            if quality_check_progress_all_complete; then
                break
            fi

            printf '\e[%sA' "$PROGRESS_TREE_LINES"
            quality_check_progress_draw_tree "$tick" pending 0
            tick=$((tick + 1))
            sleep 0.3
        done
    ) &
    PROGRESS_TICKER_PID=$!
}

quality_check_progress_stop_ticker() {
    if [ -n "$PROGRESS_TICKER_PID" ]; then
        : >"$LOG_DIR/progress.stop"
        wait "$PROGRESS_TICKER_PID" 2>/dev/null || true
        PROGRESS_TICKER_PID=""
    fi
}

quality_check_progress_render_final() {
    if ! quality_check_progress_enabled; then
        return
    fi

    if [ -n "$PROGRESS_TICKER_PID" ]; then
        quality_check_progress_stop_ticker
    fi

    if [ "$PROGRESS_TREE_LINES" -gt 0 ]; then
        printf '\e[%sA' "$PROGRESS_TREE_LINES"
    fi

    quality_check_progress_draw_tree 0 final 0
    printf '\e[?25h'
    PROGRESS_CURSOR_HIDDEN=0
    echo
}

quality_check_progress_area_label_count() {
    local area="$1"
    local count=0
    local label
    local label_area

    for label in "${CHECK_LABELS[@]}"; do
        label_area="$(quality_check_label_area "$label" || true)"

        if [ "$label_area" = "$area" ]; then
            count=$((count + 1))
        fi
    done

    echo "$count"
}

quality_check_progress_self_test() {
    local area
    local count
    local passed_areas=()
    local failed_areas=()

    LOG_DIR="$(mktemp -d)"
    touch "$LOG_DIR/progress.stop"

    for area in "${PROGRESS_AREAS[@]}"; do
        count="$(quality_check_progress_area_label_count "$area")"
        echo "${area}=${count}"
    done

    for label in "${CHECK_LABELS[@]}"; do
        echo 0 >"$LOG_DIR/$label.exit"
    done

    for area in "${PROGRESS_AREAS[@]}"; do
        passed_areas+=("$area")
    done

    echo "passed=$(IFS=,; echo "${passed_areas[*]}")"

    echo 1 >"$LOG_DIR/gateway_pest.exit"
    failed_areas=()

    for area in "${PROGRESS_AREAS[@]}"; do
        state="$(quality_check_area_row_state "$area")"

        if [ "$state" = "failed" ]; then
            failed_areas+=("$area")
        fi
    done

    echo "failed=$(IFS=,; echo "${failed_areas[*]}")"

    rm -rf "$LOG_DIR"
}

STATIC_CHECK_LABELS=(
    docs_lint
    docs_testing
    docs_references
    gateway_mago_analyze
    cli_mago_analyze
    docs_mago_analyze
    reverb_mago_analyze
    core_mago_analyze
    sdk_mago_analyze
    e2e_mago_analyze
    gateway_mago_lint
    cli_mago_lint
    docs_mago_lint
    reverb_mago_lint
    core_mago_lint
    sdk_mago_lint
    e2e_mago_lint
    gateway_rector
    cli_rector
    docs_rector
    core_rector
    sdk_rector
    e2e_rector
    gateway_mago_format
    cli_mago_format
    docs_mago_format
    reverb_mago_format
    core_mago_format
    sdk_mago_format
    e2e_mago_format
)

LONG_RUNNING_PEST_LABELS=(
    cli_pest
    gateway_pest
    docs_pest
    sdk_pest
)

CHECK_LABELS=(
    gateway_pest
    docs_lint
    docs_testing
    docs_references
    gateway_mago_analyze
    cli_mago_analyze
    docs_mago_analyze
    reverb_mago_analyze
    core_mago_analyze
    sdk_mago_analyze
    e2e_mago_analyze
    gateway_mago_lint
    cli_mago_lint
    docs_mago_lint
    reverb_mago_lint
    core_mago_lint
    sdk_mago_lint
    e2e_mago_lint
    gateway_rector
    cli_rector
    docs_rector
    core_rector
    sdk_rector
    e2e_rector
    gateway_mago_format
    cli_mago_format
    docs_mago_format
    reverb_mago_format
    core_mago_format
    sdk_mago_format
    e2e_mago_format
    cli_pest
    docs_pest
    core_pest
    sdk_pest
)

if [ "${ORBIT_QUALITY_CHECK_PROGRESS_SELF_TEST:-}" = "1" ]; then
    quality_check_progress_self_test
    exit 0
fi

run_bg() {
    local label="$1"
    shift
    local log="$LOG_DIR/$label.log"
    wait_for_bg_slot
    record_subgate_start "$label"
    ( "$@" >"$log" 2>&1; code="$?"; record_subgate_duration "$label"; echo "$code" >"$LOG_DIR/$label.exit"; exit "$code" ) &
    eval "${label}_PID=$!"
}

quality_check_progress_start_ticker

run_bg docs_lint bin/orbit-docs-artisan librarian:lint --format=agent --path=domains
run_bg docs_testing bin/orbit-docs-artisan librarian:lint --format=agent --path=testing
run_bg docs_references bin/orbit-docs-artisan librarian:lint --format=agent --group=references

run_bg gateway_mago_analyze bin/orbit-gateway-vendor-bin mago analyze app config database --reporting-format=medium
run_bg cli_mago_analyze bash -lc 'cd apps/cli && vendor/bin/mago analyze app config --reporting-format=medium'
run_bg docs_mago_analyze bash -lc 'cd apps/docs && vendor/bin/mago analyze app config database --reporting-format=medium'
run_bg reverb_mago_analyze bin/orbit-gateway-vendor-bin mago --workspace ../reverb analyze bootstrap config routes --reporting-format=medium
run_bg core_mago_analyze bash -lc 'cd packages/core && vendor/bin/mago analyze src --reporting-format=medium'
run_bg sdk_mago_analyze bash -lc 'cd packages/sdk && vendor/bin/mago analyze src --reporting-format=medium'
run_bg e2e_mago_analyze bash -lc 'cd apps/e2e && vendor/bin/mago analyze app config database --reporting-format=medium'

run_bg gateway_mago_lint bin/orbit-gateway-vendor-bin mago lint "${MAGO_LINT_ARGS[@]}"
run_bg cli_mago_lint bash -lc 'cd apps/cli && vendor/bin/mago lint "$@"' bash "${MAGO_LINT_ARGS[@]}"
run_bg docs_mago_lint bash -lc 'cd apps/docs && vendor/bin/mago lint "$@"' bash "${MAGO_LINT_ARGS[@]}"
run_bg reverb_mago_lint bin/orbit-gateway-vendor-bin mago --workspace ../reverb lint "${MAGO_LINT_ARGS[@]}"
run_bg core_mago_lint bash -lc 'cd packages/core && vendor/bin/mago lint "$@"' bash "${MAGO_LINT_ARGS[@]}"
run_bg sdk_mago_lint bash -lc 'cd packages/sdk && vendor/bin/mago lint "$@"' bash "${MAGO_LINT_ARGS[@]}"
run_bg e2e_mago_lint bash -lc 'cd apps/e2e && vendor/bin/mago lint "$@"' bash "${MAGO_LINT_ARGS[@]}"

run_bg gateway_rector bin/orbit-gateway-vendor-bin rector process "${RECTOR_ARGS[@]}"
run_bg cli_rector bash -lc 'cd apps/cli && vendor/bin/rector process "$@"' bash "${RECTOR_ARGS[@]}"
run_bg docs_rector bash -lc 'cd apps/docs && vendor/bin/rector process "$@"' bash "${RECTOR_ARGS[@]}"
run_bg core_rector bash -lc 'cd packages/core && vendor/bin/rector process "$@"' bash "${RECTOR_ARGS[@]}"
run_bg sdk_rector bash -lc 'cd packages/sdk && vendor/bin/rector process "$@"' bash "${RECTOR_ARGS[@]}"
run_bg e2e_rector bash -lc 'cd apps/e2e && vendor/bin/rector process "$@"' bash "${RECTOR_ARGS[@]}"

run_bg gateway_mago_format bin/orbit-gateway-vendor-bin mago format "${MAGO_FORMAT_ARGS[@]}"
run_bg cli_mago_format bash -lc 'cd apps/cli && vendor/bin/mago format "$@"' bash "${MAGO_FORMAT_ARGS[@]}"
run_bg docs_mago_format bash -lc 'cd apps/docs && vendor/bin/mago format "$@"' bash "${MAGO_FORMAT_ARGS[@]}"
run_bg reverb_mago_format bin/orbit-gateway-vendor-bin mago --workspace ../reverb format "${MAGO_FORMAT_ARGS[@]}"
run_bg core_mago_format bash -lc 'cd packages/core && vendor/bin/mago format "$@"' bash "${MAGO_FORMAT_ARGS[@]}"
run_bg sdk_mago_format bash -lc 'cd packages/sdk && vendor/bin/mago format "$@"' bash "${MAGO_FORMAT_ARGS[@]}"
run_bg e2e_mago_format bash -lc 'cd apps/e2e && vendor/bin/mago format "$@"' bash "${MAGO_FORMAT_ARGS[@]}"

run_bg cli_pest bin/orbit-cli-pest-quality --exclude-group=slow --compact
run_bg docs_pest bin/orbit-docs-pest --compact

bin/orbit-gateway-artisan config:clear --ansi >/dev/null 2>&1 || true
run_bg gateway_pest bin/orbit-gateway-pest --exclude-group=e2e --exclude-group=slow --parallel --compact "$@"

run_bg sdk_pest bash -lc 'cd packages/sdk && vendor/bin/pest --compact'

for label in "${STATIC_CHECK_LABELS[@]}"; do
    wait_for_bg_label "$label"
done

for label in "${LONG_RUNNING_PEST_LABELS[@]}"; do
    wait_for_bg_label "$label"
done

# The core progress tests intentionally fork short-lived ticker children. Keep
# this lane out of the background fan-out so unrelated Pest suites cannot
# deliver process-group signals to the core Pest parent.
record_subgate_start core_pest
( cd packages/core && vendor/bin/pest --compact >"$LOG_DIR/core_pest.log" 2>&1; echo "$?" >"$LOG_DIR/core_pest.exit" )
record_subgate_duration core_pest

quality_check_progress_render_final

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
