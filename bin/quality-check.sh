#!/usr/bin/env bash

# CPU-budgeted quality gate for Orbit. Components are admitted in order when
# their declared peak demand fits the host budget, then run their owned
# subgates sequentially. This keeps progress truthful and prevents Pest, Cargo,
# Mago, and Rector from competing through hidden nested fan-out.
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
GIT_START_CLEAN=0
if GIT_START_STATUS="$(git -C "$ROOT" status --porcelain --untracked-files=all 2>/dev/null)" && [ -z "$GIT_START_STATUS" ]; then
    GIT_START_CLEAN=1
fi

FIX_MODE=0
if [ "${1:-}" = "--fix" ]; then
    FIX_MODE=1
    shift
fi

QUALITY_CHECK_ARGS=("$@")

MODE="check"
COMMAND="composer quality-check"
if [ "$FIX_MODE" -eq 1 ]; then
    MODE="fix"
    COMMAND="composer quality-check:fix"
fi

LOG_DIR="$(mktemp -d)"
COMPONENT_WORKERS=()

terminate_process_tree() {
    local parent_pid="$1"
    local signal="${2:-TERM}"
    local child_pid
    local child_pids

    child_pids="$(pgrep -P "$parent_pid" 2>/dev/null || true)"
    kill -"$signal" "$parent_pid" 2>/dev/null || true

    for child_pid in $child_pids; do
        terminate_process_tree "$child_pid" "$signal"
    done
}

quality_check_cleanup() {
    local key
    local pid_var
    local pid

    if [ -n "${PROGRESS_TICKER_PID:-}" ]; then
        : >"$LOG_DIR/progress.stop"
        wait "$PROGRESS_TICKER_PID" 2>/dev/null || true
    fi

    for key in ${COMPONENT_WORKERS[@]+"${COMPONENT_WORKERS[@]}"}; do
        pid_var="${key}_COMPONENT_PID"
        pid="${!pid_var:-}"

        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            terminate_process_tree "$pid"
        fi
    done

    for key in ${COMPONENT_WORKERS[@]+"${COMPONENT_WORKERS[@]}"}; do
        pid_var="${key}_COMPONENT_PID"
        pid="${!pid_var:-}"

        if [ -n "$pid" ]; then
            wait "$pid" 2>/dev/null || true
        fi
    done

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

quality_check_default_cpu_budget() {
    local detected_jobs

    detected_jobs="$(getconf _NPROCESSORS_ONLN 2>/dev/null || sysctl -n hw.logicalcpu 2>/dev/null || echo 8)"

    if ! [[ "$detected_jobs" =~ ^[0-9]+$ ]] || [ "$detected_jobs" -lt 1 ]; then
        echo 6
        return
    fi

    if [ "$detected_jobs" -le 1 ]; then
        echo 1
        return
    fi

    if [ "$detected_jobs" -le 4 ]; then
        echo $((detected_jobs - 1))
        return
    fi

    if [ "$detected_jobs" -gt 14 ]; then
        echo 14
        return
    fi

    echo $((detected_jobs - 2))
}

DEFAULT_CPU_BUDGET="$(quality_check_default_cpu_budget)"
CPU_BUDGET="${ORBIT_QUALITY_CHECK_CPU_BUDGET:-$DEFAULT_CPU_BUDGET}"

if ! [[ "$CPU_BUDGET" =~ ^[0-9]+$ ]] || [ "$CPU_BUDGET" -lt 1 ]; then
    CPU_BUDGET="$DEFAULT_CPU_BUDGET"
fi

GATEWAY_PEST_PROCESSES=$((CPU_BUDGET / 2))
[ "$GATEWAY_PEST_PROCESSES" -lt 1 ] && GATEWAY_PEST_PROCESSES=1
[ "$CPU_BUDGET" -ge 14 ] && GATEWAY_PEST_PROCESSES=8
[ "$GATEWAY_PEST_PROCESSES" -gt 8 ] && GATEWAY_PEST_PROCESSES=8

GATEWAY_COMPONENT_DEMAND="$GATEWAY_PEST_PROCESSES"
CLI_COMPONENT_DEMAND=5
DOCS_COMPONENT_DEMAND=1
E2E_COMPONENT_DEMAND=1
REVERB_COMPONENT_DEMAND=1
CARGO_COMPONENT_DEMAND=3
CORE_COMPONENT_DEMAND=1
SDK_COMPONENT_DEMAND=1

GATEWAY_STATIC_MAGO_THREADS=$(((GATEWAY_COMPONENT_DEMAND - 1) / 3))
[ "$GATEWAY_STATIC_MAGO_THREADS" -lt 1 ] && GATEWAY_STATIC_MAGO_THREADS=1
CLI_STATIC_MAGO_THREADS=1
HEAVY_PEST_PHASE_COORDINATION=0

if [ "$FIX_MODE" -eq 0 ] && [ $((GATEWAY_COMPONENT_DEMAND + CLI_COMPONENT_DEMAND)) -le "$CPU_BUDGET" ]; then
    HEAVY_PEST_PHASE_COORDINATION=1
fi

MAX_CLI_COMPONENT_DEMAND=$((CPU_BUDGET - CORE_COMPONENT_DEMAND))
[ "$MAX_CLI_COMPONENT_DEMAND" -lt 1 ] && MAX_CLI_COMPONENT_DEMAND=1

if [ "$CLI_COMPONENT_DEMAND" -gt "$MAX_CLI_COMPONENT_DEMAND" ]; then
    CLI_COMPONENT_DEMAND="$MAX_CLI_COMPONENT_DEMAND"
fi

if [ "$CARGO_COMPONENT_DEMAND" -gt "$CPU_BUDGET" ]; then
    CARGO_COMPONENT_DEMAND="$CPU_BUDGET"
fi

running_bg_jobs() {
    local count=0
    local pid

    for pid in $(jobs -pr); do
        if [ -n "${PROGRESS_TICKER_PID:-}" ] && [ "$pid" = "$PROGRESS_TICKER_PID" ]; then
            continue
        fi

        count=$((count + 1))
    done

    echo "$count"
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

record_subgate_running() {
    local label="$1"

    : >"$LOG_DIR/$label.running"
}

clear_subgate_running() {
    local label="$1"

    rm -f "$LOG_DIR/$label.running"
}

quality_check_area_marker_path() {
    local area="$1"
    local safe_area="${area//\//_}"

    echo "$LOG_DIR/area_${safe_area}.admitted"
}

record_area_admitted() {
    local area="$1"

    : >"$(quality_check_area_marker_path "$area")"
}

record_label_area_admitted() {
    local label="$1"
    local area

    area="$(quality_check_label_area "$label" || true)"

    if [ -n "$area" ]; then
        record_area_admitted "$area"
    fi
}

quality_check_area_admitted() {
    local area="$1"

    [ -f "$(quality_check_area_marker_path "$area")" ]
}

PROGRESS_AREAS=(
    apps/gateway
    apps/cli
    apps/docs
    apps/e2e
    apps/reverb
    apps/agent
    apps/macos
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
PROGRESS_AREA_WIDTH=15

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
        agent_*)
            echo apps/agent
            ;;
        macos_*)
            echo apps/macos
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

quality_check_label_running() {
    local label="$1"
    local pid_file="$LOG_DIR/$label.pid"
    local pid

    if [ ! -f "$LOG_DIR/$label.running" ]; then
        return 1
    fi

    if [ -f "$LOG_DIR/$label.exit" ]; then
        return 1
    fi

    if [ ! -f "$pid_file" ]; then
        return 0
    fi

    pid="$(cat "$pid_file" 2>/dev/null || true)"

    if [ -z "$pid" ]; then
        return 0
    fi

    kill -0 "$pid" 2>/dev/null
}

quality_check_area_row_state() {
    local area="$1"
    local total=0
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

    if quality_check_area_admitted "$area"; then
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
    local padded_area

    printf -v padded_area "%-${PROGRESS_AREA_WIDTH}s" "$area"

    case "$state" in
        waiting)
            quality_check_progress_print_line '  %s○%s  %s%s%s %sQueued%s\n' "$PROGRESS_DIM" "$PROGRESS_RESET" "$PROGRESS_DIM" "$padded_area" "$PROGRESS_RESET" "$PROGRESS_DIM" "$PROGRESS_RESET"
            ;;
        running)
            frame="${PROGRESS_SPINNER_FRAMES[$((tick % ${#PROGRESS_SPINNER_FRAMES[@]}))]}"
            quality_check_progress_print_line '  %s  %s Running\n' "$frame" "$padded_area"
            ;;
        passed)
            quality_check_progress_print_line '  %s●%s  %s Passed\n' "$PROGRESS_GREEN" "$PROGRESS_RESET" "$padded_area"
            ;;
        failed)
            quality_check_progress_print_line '  %s●%s  %s Failed\n' "$PROGRESS_RED" "$PROGRESS_RESET" "$padded_area"
            ;;
        *)
            quality_check_progress_print_line '  %s○%s  %s%s%s %sQueued%s\n' "$PROGRESS_DIM" "$PROGRESS_RESET" "$PROGRESS_DIM" "$padded_area" "$PROGRESS_RESET" "$PROGRESS_DIM" "$PROGRESS_RESET"
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

quality_check_progress_spawn_ticker() {
    local tick=0

    rm -f "$LOG_DIR/progress.stop"
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

quality_check_progress_start_ticker() {
    if ! quality_check_progress_enabled; then
        return
    fi

    PROGRESS_TREE_LINES="$(quality_check_progress_tree_line_count)"
    quality_check_progress_draw_tree 0 pending 1
    printf '\e[?25l'
    PROGRESS_CURSOR_HIDDEN=1
    quality_check_progress_spawn_ticker
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

quality_check_progress_render_pending() {
    if ! quality_check_progress_enabled; then
        return
    fi

    if [ -n "$PROGRESS_TICKER_PID" ]; then
        quality_check_progress_stop_ticker
    fi

    if [ "$PROGRESS_TREE_LINES" -eq 0 ]; then
        PROGRESS_TREE_LINES="$(quality_check_progress_tree_line_count)"
    else
        printf '\e[%sA' "$PROGRESS_TREE_LINES"
    fi

    quality_check_progress_draw_tree 0 pending 0
    quality_check_progress_spawn_ticker
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

run_with_quality_check_args() {
    if [ "${#QUALITY_CHECK_ARGS[@]}" -eq 0 ]; then
        "$@"

        return
    fi

    "$@" "${QUALITY_CHECK_ARGS[@]}"
}

quality_check_args_self_test() {
    local case_label="$1"
    local argument
    shift

    printf 'quality_check_args_%s=%s' "$case_label" "$#"

    for argument in "$@"; do
        printf '|%s' "$argument"
    done

    printf '\n'
}

quality_check_progress_self_test() {
    local area
    local count
    local passed_areas=()
    local failed_areas=()

    LOG_DIR="$(mktemp -d)"
    touch "$LOG_DIR/progress.stop"

    COMPONENT_WORKERS=()
    echo "component_workers_empty=$(component_cpu_in_use)"

    QUALITY_CHECK_ARGS=()
    run_with_quality_check_args quality_check_args_self_test empty

    QUALITY_CHECK_ARGS=("--filter=alpha beta" "--compact")
    run_with_quality_check_args quality_check_args_self_test non_empty

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

quality_check_progress_state_self_test() {
    local state
    local label
    local sleeper_pid
    local ticker_pid
    local worker_pid

    LOG_DIR="$(mktemp -d)"
    touch "$LOG_DIR/progress.stop"

    state="$(quality_check_area_row_state apps/gateway)"
    echo "initial=${state}"

    record_area_admitted apps/gateway
    record_subgate_start gateway_mago_analyze
    echo 0 >"$LOG_DIR/gateway_mago_analyze.exit"
    state="$(quality_check_area_row_state apps/gateway)"
    echo "admitted-completed-subgate=${state}"

    record_area_admitted apps/cli
    record_subgate_start cli_mago_analyze
    record_subgate_running cli_mago_analyze
    (sleep 1) &
    sleeper_pid=$!
    echo "$sleeper_pid" >"$LOG_DIR/cli_mago_analyze.pid"

    state="$(quality_check_area_row_state apps/cli)"
    echo "active=${state}"

    kill "$sleeper_pid" 2>/dev/null || true
    wait "$sleeper_pid" 2>/dev/null || true
    clear_subgate_running cli_mago_analyze
    echo 0 >"$LOG_DIR/cli_mago_analyze.exit"

    state="$(quality_check_area_row_state apps/cli)"
    echo "admitted-after-active=${state}"

    for label in "${CHECK_LABELS[@]}"; do
        if [ "$(quality_check_label_area "$label" || true)" = "apps/cli" ]; then
            echo 0 >"$LOG_DIR/$label.exit"
        fi
    done

    state="$(quality_check_area_row_state apps/cli)"
    echo "completed=${state}"

    (sleep 1) &
    ticker_pid=$!
    PROGRESS_TICKER_PID="$ticker_pid"

    (sleep 1) &
    worker_pid=$!

    echo "background-count=$(running_bg_jobs)"

    kill "$worker_pid" "$ticker_pid" 2>/dev/null || true
    wait "$worker_pid" 2>/dev/null || true
    wait "$ticker_pid" 2>/dev/null || true
    PROGRESS_TICKER_PID=""

    rm -rf "$LOG_DIR"
}

APP_BEFORE_PACKAGE_CHECK_LABELS=(
    gateway_mago_analyze
    gateway_mago_lint
    gateway_rector
    gateway_mago_format
    cli_mago_analyze
    cli_mago_lint
    cli_rector
    cli_mago_format
    docs_lint
    docs_testing
    docs_references
    docs_mago_analyze
    docs_mago_lint
    docs_rector
    docs_mago_format
    docs_pest
    e2e_mago_analyze
    e2e_mago_lint
    e2e_rector
    e2e_mago_format
    reverb_mago_analyze
    reverb_mago_lint
    reverb_mago_format
    agent_cargo_test
    agent_cargo_fmt
    agent_cargo_check
    agent_cargo_clippy
    macos_cargo_test
    macos_cargo_fmt
    macos_cargo_check
    macos_cargo_clippy
)

APP_REMAINING_CHECK_LABELS=(
    gateway_pest
    cli_pest
)

PACKAGE_BACKGROUND_CHECK_LABELS=(
    core_mago_analyze
    sdk_mago_analyze
    core_mago_lint
    sdk_mago_lint
    core_rector
    sdk_rector
    core_mago_format
    sdk_mago_format
    sdk_pest
)

BACKGROUND_CHECK_LABELS=(
    gateway_mago_analyze
    gateway_mago_lint
    gateway_rector
    gateway_mago_format
    gateway_pest
    cli_mago_analyze
    cli_mago_lint
    cli_rector
    cli_mago_format
    cli_pest
    docs_lint
    docs_testing
    docs_references
    docs_mago_analyze
    docs_mago_lint
    docs_rector
    docs_mago_format
    docs_pest
    e2e_mago_analyze
    e2e_mago_lint
    e2e_rector
    e2e_mago_format
    reverb_mago_analyze
    reverb_mago_lint
    reverb_mago_format
    agent_cargo_test
    agent_cargo_fmt
    agent_cargo_check
    agent_cargo_clippy
    macos_cargo_test
    macos_cargo_fmt
    macos_cargo_check
    macos_cargo_clippy
    core_mago_analyze
    core_mago_lint
    core_rector
    core_mago_format
    sdk_mago_analyze
    sdk_mago_lint
    sdk_rector
    sdk_mago_format
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
    agent_cargo_test
    agent_cargo_fmt
    agent_cargo_check
    agent_cargo_clippy
    macos_cargo_test
    macos_cargo_fmt
    macos_cargo_check
    macos_cargo_clippy
    cli_pest
    docs_pest
    core_pest
    sdk_pest
)

if [ "${ORBIT_QUALITY_CHECK_PROGRESS_STATE_SELF_TEST:-}" = "1" ]; then
    quality_check_progress_state_self_test
    exit 0
fi

run_subgate() {
    local label="$1"
    shift
    local log="$LOG_DIR/$label.log"
    local code=0

    record_subgate_start "$label"
    record_subgate_running "$label"
    "$@" >"$log" 2>&1 || code="$?"
    record_subgate_duration "$label"
    echo "$code" >"$LOG_DIR/$label.exit"
    clear_subgate_running "$label"
}

run_static_subgates() {
    local pid
    local pids=()

    for command_function in "$@"; do
        "$command_function" &
        pids+=("$!")
    done

    for pid in "${pids[@]}"; do
        wait "$pid" 2>/dev/null || true
    done
}

component_cpu_in_use() {
    local total=0
    local key
    local pid_var
    local demand_var
    local pid
    local demand

    for key in ${COMPONENT_WORKERS[@]+"${COMPONENT_WORKERS[@]}"}; do
        pid_var="${key}_COMPONENT_PID"
        demand_var="${key}_COMPONENT_CPU"
        pid="${!pid_var:-}"

        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            demand="$(cat "$LOG_DIR/$key.cpu" 2>/dev/null || echo "${!demand_var}")"
            total=$((total + demand))
        fi
    done

    echo "$total"
}

if [ "${ORBIT_QUALITY_CHECK_PROGRESS_SELF_TEST:-}" = "1" ]; then
    quality_check_progress_self_test
    exit 0
fi

wait_for_cpu_capacity() {
    local demand="$1"

    while [ $(( $(component_cpu_in_use) + demand )) -gt "$CPU_BUDGET" ]; do
        sleep 0.2
    done
}

start_component() {
    local area="$1"
    local demand="$2"
    local key="$3"
    local function_name="${key}_component"

    record_area_admitted "$area"
    echo "$demand" >"$LOG_DIR/$key.cpu"
    "$function_name" &
    eval "${key}_COMPONENT_PID=$!"
    eval "${key}_COMPONENT_CPU=$demand"
    COMPONENT_WORKERS+=("$key")
}

run_component() {
    local area="$1"
    local demand="$2"
    local key="$3"

    wait_for_cpu_capacity "$demand"
    start_component "$area" "$demand" "$key"
}

admit_components() {
    local specs=("$@")
    local started=0
    local admitted
    local spec
    local area
    local demand_var
    local demand
    local key
    local started_var

    while [ "$started" -lt "${#specs[@]}" ]; do
        admitted=0

        for spec in "${specs[@]}"; do
            IFS='|' read -r area demand_var key <<<"$spec"
            started_var="${key}_COMPONENT_STARTED"

            if [ "${!started_var:-0}" -eq 1 ]; then
                continue
            fi

            demand="${!demand_var}"

            if [ $(( $(component_cpu_in_use) + demand )) -gt "$CPU_BUDGET" ]; then
                continue
            fi

            start_component "$area" "$demand" "$key"
            eval "${started_var}=1"
            started=$((started + 1))
            admitted=1
        done

        if [ "$admitted" -eq 0 ]; then
            sleep 0.2
        fi
    done
}

wait_for_component_labels() {
    local key
    local pid_var

    for key in "$@"; do
        pid_var="${key}_COMPONENT_PID"
        wait "${!pid_var}" 2>/dev/null || true
    done
}

wait_for_subgate_labels() {
    local label

    for label in "$@"; do
        while [ ! -f "$LOG_DIR/$label.exit" ]; do
            sleep 0.1
        done
    done
}

gateway_mago_analyze_subgate() {
    run_subgate gateway_mago_analyze env RAYON_NUM_THREADS="$GATEWAY_STATIC_MAGO_THREADS" bin/orbit-gateway-vendor-bin mago analyze app config database --reporting-format=medium
}

gateway_mago_lint_subgate() {
    run_subgate gateway_mago_lint env RAYON_NUM_THREADS="$GATEWAY_STATIC_MAGO_THREADS" bin/orbit-gateway-vendor-bin mago lint "${MAGO_LINT_ARGS[@]}"
}

gateway_rector_subgate() {
    run_subgate gateway_rector bin/orbit-gateway-vendor-bin rector process ${RECTOR_ARGS[@]+"${RECTOR_ARGS[@]}"}
}

gateway_mago_format_subgate() {
    run_subgate gateway_mago_format env RAYON_NUM_THREADS="$GATEWAY_STATIC_MAGO_THREADS" bin/orbit-gateway-vendor-bin mago format ${MAGO_FORMAT_ARGS[@]+"${MAGO_FORMAT_ARGS[@]}"}
}

gateway_component() {
    local gateway_pest_pid

    bin/orbit-gateway-artisan config:clear --ansi >/dev/null 2>&1 || true

    if [ "$HEAVY_PEST_PHASE_COORDINATION" -eq 1 ]; then
        run_with_quality_check_args run_subgate gateway_pest bin/orbit-gateway-pest --exclude-group=e2e --exclude-group=slow --parallel --processes="$GATEWAY_PEST_PROCESSES" --passthru-php="'-d' 'memory_limit=512M'" --profile --compact --log-junit="$LOG_DIR/gateway_pest.junit.xml" &
        gateway_pest_pid=$!

        while [ ! -f "$LOG_DIR/cli_heavy_phase_released" ]; do
            sleep 0.1
        done

        run_gateway_static_subgates_budgeted
        echo "$GATEWAY_COMPONENT_DEMAND" >"$LOG_DIR/gateway.cpu"
        wait "$gateway_pest_pid" 2>/dev/null || true
        append_gateway_pest_profile

        return
    fi

    if [ "$FIX_MODE" -eq 1 ] || [ "$GATEWAY_COMPONENT_DEMAND" -lt 4 ]; then
        gateway_mago_analyze_subgate
        gateway_mago_lint_subgate
        gateway_rector_subgate
        gateway_mago_format_subgate
    else
        run_static_subgates gateway_mago_analyze_subgate gateway_mago_lint_subgate gateway_rector_subgate gateway_mago_format_subgate
    fi
    run_with_quality_check_args run_subgate gateway_pest bin/orbit-gateway-pest --exclude-group=e2e --exclude-group=slow --parallel --processes="$GATEWAY_PEST_PROCESSES" --passthru-php="'-d' 'memory_limit=512M'" --profile --compact --log-junit="$LOG_DIR/gateway_pest.junit.xml"
    append_gateway_pest_profile
}

run_gateway_static_subgates_budgeted() {
    local analyze_pid
    local lint_pid
    local rector_pid
    local format_pid

    gateway_mago_analyze_subgate &
    analyze_pid=$!
    gateway_mago_lint_subgate &
    lint_pid=$!
    gateway_rector_subgate &
    rector_pid=$!

    wait "$lint_pid" 2>/dev/null || true
    gateway_mago_format_subgate &
    format_pid=$!

    wait "$analyze_pid" 2>/dev/null || true
    wait "$rector_pid" 2>/dev/null || true
    wait "$format_pid" 2>/dev/null || true
}

append_gateway_pest_profile() {
    local junit_path="$LOG_DIR/gateway_pest.junit.xml"

    if [ ! -s "$junit_path" ]; then
        return
    fi

    php -r '
$testCases = simplexml_load_file($argv[1])->xpath("//testcase") ?: [];
$durations = [];

foreach ($testCases as $testCase) {
    $durations[] = [
        "test" => (string) $testCase["name"],
        "file" => (string) $testCase["file"],
        "duration_ms" => (int) round(((float) $testCase["time"]) * 1000),
    ];
}

usort($durations, fn (array $left, array $right): int => $right["duration_ms"] <=> $left["duration_ms"]);

echo json_encode([
    "tool" => "pest-profile",
    "source" => "junit",
    "slowest" => array_slice($durations, 0, 10),
], JSON_UNESCAPED_SLASHES).PHP_EOL;
' "$junit_path" >>"$LOG_DIR/gateway_pest.log"
}

cli_mago_analyze_subgate() {
    run_subgate cli_mago_analyze env RAYON_NUM_THREADS="$CLI_STATIC_MAGO_THREADS" bash -lc 'cd apps/cli && vendor/bin/mago analyze app config --reporting-format=medium'
}

cli_mago_lint_subgate() {
    run_subgate cli_mago_lint env RAYON_NUM_THREADS="$CLI_STATIC_MAGO_THREADS" bash -lc 'cd apps/cli && vendor/bin/mago lint "$@"' bash "${MAGO_LINT_ARGS[@]}"
}

cli_rector_subgate() {
    run_subgate cli_rector bash -lc 'cd apps/cli && vendor/bin/rector process "$@"' bash ${RECTOR_ARGS[@]+"${RECTOR_ARGS[@]}"}
}

cli_mago_format_subgate() {
    run_subgate cli_mago_format env RAYON_NUM_THREADS="$CLI_STATIC_MAGO_THREADS" bash -lc 'cd apps/cli && vendor/bin/mago format "$@"' bash ${MAGO_FORMAT_ARGS[@]+"${MAGO_FORMAT_ARGS[@]}"}
}

cli_component() {
    if [ "$HEAVY_PEST_PHASE_COORDINATION" -eq 1 ]; then
        run_subgate cli_pest bin/orbit-cli-pest-quality --exclude-group=slow --profile --compact
        run_static_subgates cli_mago_analyze_subgate cli_mago_lint_subgate cli_rector_subgate cli_mago_format_subgate
        echo $((GATEWAY_PEST_PROCESSES + (2 * GATEWAY_STATIC_MAGO_THREADS) + 1)) >"$LOG_DIR/gateway.cpu"
        : >"$LOG_DIR/cli_heavy_phase_released"

        return
    fi

    if [ "$FIX_MODE" -eq 1 ] || [ "$CLI_COMPONENT_DEMAND" -lt 4 ]; then
        cli_mago_analyze_subgate
        cli_mago_lint_subgate
        cli_rector_subgate
        cli_mago_format_subgate
    else
        run_static_subgates cli_mago_analyze_subgate cli_mago_lint_subgate cli_rector_subgate cli_mago_format_subgate
    fi
    if [ "$CLI_COMPONENT_DEMAND" -lt 5 ]; then
        run_subgate cli_pest bin/orbit-cli-pest --exclude-group=slow --profile --compact
    else
        run_subgate cli_pest bin/orbit-cli-pest-quality --exclude-group=slow --profile --compact
    fi
}

docs_component() {
    run_subgate docs_lint bin/orbit-docs-artisan librarian:lint --format=agent --path=domains
    run_subgate docs_testing bin/orbit-docs-artisan librarian:lint --format=agent --path=testing
    run_subgate docs_references bin/orbit-docs-artisan librarian:lint --format=agent --group=references
    run_subgate docs_mago_analyze env RAYON_NUM_THREADS="$DOCS_COMPONENT_DEMAND" bash -lc 'cd apps/docs && vendor/bin/mago analyze app config database --reporting-format=medium'
    run_subgate docs_mago_lint env RAYON_NUM_THREADS="$DOCS_COMPONENT_DEMAND" bash -lc 'cd apps/docs && vendor/bin/mago lint "$@"' bash "${MAGO_LINT_ARGS[@]}"
    run_subgate docs_rector bash -lc 'cd apps/docs && vendor/bin/rector process "$@"' bash ${RECTOR_ARGS[@]+"${RECTOR_ARGS[@]}"}
    run_subgate docs_mago_format env RAYON_NUM_THREADS="$DOCS_COMPONENT_DEMAND" bash -lc 'cd apps/docs && vendor/bin/mago format "$@"' bash ${MAGO_FORMAT_ARGS[@]+"${MAGO_FORMAT_ARGS[@]}"}
    run_subgate docs_pest bin/orbit-docs-pest --profile --compact
}

e2e_component() {
    run_subgate e2e_mago_analyze env RAYON_NUM_THREADS="$E2E_COMPONENT_DEMAND" bash -lc 'cd apps/e2e && vendor/bin/mago analyze app config database --reporting-format=medium'
    run_subgate e2e_mago_lint env RAYON_NUM_THREADS="$E2E_COMPONENT_DEMAND" bash -lc 'cd apps/e2e && vendor/bin/mago lint "$@"' bash "${MAGO_LINT_ARGS[@]}"
    run_subgate e2e_rector bash -lc 'cd apps/e2e && vendor/bin/rector process "$@"' bash ${RECTOR_ARGS[@]+"${RECTOR_ARGS[@]}"}
    run_subgate e2e_mago_format env RAYON_NUM_THREADS="$E2E_COMPONENT_DEMAND" bash -lc 'cd apps/e2e && vendor/bin/mago format "$@"' bash ${MAGO_FORMAT_ARGS[@]+"${MAGO_FORMAT_ARGS[@]}"}
}

reverb_component() {
    run_subgate reverb_mago_analyze env RAYON_NUM_THREADS="$REVERB_COMPONENT_DEMAND" bin/orbit-gateway-vendor-bin mago --workspace ../reverb analyze bootstrap config routes --reporting-format=medium
    run_subgate reverb_mago_lint env RAYON_NUM_THREADS="$REVERB_COMPONENT_DEMAND" bin/orbit-gateway-vendor-bin mago --workspace ../reverb lint "${MAGO_LINT_ARGS[@]}"
    run_subgate reverb_mago_format env RAYON_NUM_THREADS="$REVERB_COMPONENT_DEMAND" bin/orbit-gateway-vendor-bin mago --workspace ../reverb format ${MAGO_FORMAT_ARGS[@]+"${MAGO_FORMAT_ARGS[@]}"}
}

agent_component() {
    if [ "$FIX_MODE" -eq 1 ]; then
        run_subgate agent_cargo_fmt env CARGO_BUILD_JOBS="$CARGO_COMPONENT_DEMAND" bash -lc 'cd apps/agent && cargo fmt'
    else
        run_subgate agent_cargo_fmt env CARGO_BUILD_JOBS="$CARGO_COMPONENT_DEMAND" bash -lc 'cd apps/agent && cargo fmt -- --check'
    fi
    run_subgate agent_cargo_test env CARGO_BUILD_JOBS="$CARGO_COMPONENT_DEMAND" bash -lc 'cd apps/agent && cargo test'
    run_subgate agent_cargo_check env CARGO_BUILD_JOBS="$CARGO_COMPONENT_DEMAND" bash -lc 'cd apps/agent && cargo check'
    run_subgate agent_cargo_clippy env CARGO_BUILD_JOBS="$CARGO_COMPONENT_DEMAND" bash -lc 'cd apps/agent && cargo clippy --all-targets -- -D warnings'
}

macos_component() {
    if [ "$FIX_MODE" -eq 1 ]; then
        run_subgate macos_cargo_fmt env CARGO_BUILD_JOBS="$CARGO_COMPONENT_DEMAND" bash -lc 'cd apps/macos && cargo fmt'
    else
        run_subgate macos_cargo_fmt env CARGO_BUILD_JOBS="$CARGO_COMPONENT_DEMAND" bash -lc 'cd apps/macos && cargo fmt -- --check'
    fi
    run_subgate macos_cargo_test env CARGO_BUILD_JOBS="$CARGO_COMPONENT_DEMAND" bash -lc 'cd apps/macos && cargo test'
    run_subgate macos_cargo_check env CARGO_BUILD_JOBS="$CARGO_COMPONENT_DEMAND" bash -lc 'cd apps/macos && cargo check'
    run_subgate macos_cargo_clippy env CARGO_BUILD_JOBS="$CARGO_COMPONENT_DEMAND" bash -lc 'cd apps/macos && cargo clippy --all-targets -- -D warnings'
}

sdk_component() {
    run_subgate sdk_mago_analyze env RAYON_NUM_THREADS="$SDK_COMPONENT_DEMAND" bash -lc 'cd packages/sdk && vendor/bin/mago analyze src --reporting-format=medium'
    run_subgate sdk_mago_lint env RAYON_NUM_THREADS="$SDK_COMPONENT_DEMAND" bash -lc 'cd packages/sdk && vendor/bin/mago lint "$@"' bash "${MAGO_LINT_ARGS[@]}"
    run_subgate sdk_rector bash -lc 'cd packages/sdk && vendor/bin/rector process "$@"' bash ${RECTOR_ARGS[@]+"${RECTOR_ARGS[@]}"}
    run_subgate sdk_mago_format env RAYON_NUM_THREADS="$SDK_COMPONENT_DEMAND" bash -lc 'cd packages/sdk && vendor/bin/mago format "$@"' bash ${MAGO_FORMAT_ARGS[@]+"${MAGO_FORMAT_ARGS[@]}"}
    run_subgate sdk_pest bash -lc 'cd packages/sdk && vendor/bin/pest --profile --compact'
}

core_component() {
    run_subgate core_mago_analyze env RAYON_NUM_THREADS="$CORE_COMPONENT_DEMAND" bash -lc 'cd packages/core && vendor/bin/mago analyze src --reporting-format=medium'
    run_subgate core_mago_lint env RAYON_NUM_THREADS="$CORE_COMPONENT_DEMAND" bash -lc 'cd packages/core && vendor/bin/mago lint "$@"' bash "${MAGO_LINT_ARGS[@]}"
    run_subgate core_rector bash -lc 'cd packages/core && vendor/bin/rector process "$@"' bash ${RECTOR_ARGS[@]+"${RECTOR_ARGS[@]}"}
    run_subgate core_mago_format env RAYON_NUM_THREADS="$CORE_COMPONENT_DEMAND" bash -lc 'cd packages/core && vendor/bin/mago format "$@"' bash ${MAGO_FORMAT_ARGS[@]+"${MAGO_FORMAT_ARGS[@]}"}
    wait_for_subgate_labels gateway_pest cli_pest docs_pest sdk_pest
    run_subgate core_pest bash -lc 'cd packages/core && vendor/bin/pest --profile --compact'
}

quality_check_progress_start_ticker

admit_components \
    'apps/gateway|GATEWAY_COMPONENT_DEMAND|gateway' \
    'apps/cli|CLI_COMPONENT_DEMAND|cli' \
    'apps/docs|DOCS_COMPONENT_DEMAND|docs' \
    'apps/e2e|E2E_COMPONENT_DEMAND|e2e' \
    'apps/reverb|REVERB_COMPONENT_DEMAND|reverb' \
    'apps/agent|CARGO_COMPONENT_DEMAND|agent' \
    'apps/macos|CARGO_COMPONENT_DEMAND|macos' \
    'packages/core|CORE_COMPONENT_DEMAND|core' \
    'packages/sdk|SDK_COMPONENT_DEMAND|sdk'

wait_for_component_labels gateway cli docs e2e reverb agent macos sdk core

quality_check_progress_render_final

print_log() {
    local label="$1"
    local exit_file="$LOG_DIR/$label.exit"
    local log_file="$LOG_DIR/$label.log"
    local code
    code="$(cat "$exit_file" 2>/dev/null || echo 1)"

    if [ "$code" -eq 0 ]; then
        return 0
    fi

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

PROFILE_RUN_ID="${STARTED_AT//:/-}-${GIT_COMMIT:0:12}"
PROFILE_DIR="${ARTIFACT_DIR}/profiles/${PROFILE_RUN_ID}"
mkdir -p "$PROFILE_DIR"

for label in gateway_pest cli_pest docs_pest core_pest sdk_pest; do
    if [ -f "$LOG_DIR/$label.log" ]; then
        cp "$LOG_DIR/$label.log" "$PROFILE_DIR/$label.log"
    fi
done


if [ -f "$LOG_DIR/gateway_pest.junit.xml" ]; then
    cp "$LOG_DIR/gateway_pest.junit.xml" "$PROFILE_DIR/gateway_pest.junit.xml"
fi

echo "Pest profiles: ${PROFILE_DIR}"

if [ "$overall" -ne 0 ]; then
    echo "Quality gate FAILED (${summary})"
fi

ENDED_AT="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
GIT_END_COMMIT="$(git -C "$ROOT" rev-parse HEAD 2>/dev/null || echo unknown)"
GIT_END_CLEAN=0
if GIT_END_STATUS="$(git -C "$ROOT" status --porcelain --untracked-files=all 2>/dev/null)" && [ -z "$GIT_END_STATUS" ]; then
    GIT_END_CLEAN=1
fi
GIT_DIRTY=true
if [ "$GIT_START_CLEAN" -eq 1 ] && [ "$GIT_END_CLEAN" -eq 1 ] && [ "$GIT_COMMIT" != unknown ] && [ "$GIT_END_COMMIT" = "$GIT_COMMIT" ]; then
    GIT_DIRTY=false
fi
php "${ROOT}/bin/quality-gate-write-artifact" \
    --gate=quality-check \
    --producer=quality-check.sh \
    --command="$COMMAND" \
    --mode="$MODE" \
    --started-at="$STARTED_AT" \
    --ended-at="$ENDED_AT" \
    --exit-code="$overall" \
    --git-branch="$GIT_BRANCH" \
    --git-commit="$GIT_COMMIT" \
    --git-dirty="$GIT_DIRTY" \
    --artifact-dir="$ARTIFACT_DIR" \
    "${SUBGATE_ARGS[@]}" \
    "${SUBGATE_DURATION_ARGS[@]}" >/dev/null 2>&1 || true

exit "$overall"
