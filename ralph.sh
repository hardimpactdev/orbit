#!/bin/bash
# Ralph - Autonomous AI agent loop for Amp
# Usage: ./ralph.sh [max_iterations]

set -e

MAX_ITERATIONS=${1:-10}
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PRD_FILE="$SCRIPT_DIR/prd.json"
PROGRESS_FILE="$SCRIPT_DIR/progress.txt"
PROMPT_FILE="$SCRIPT_DIR/prompt.md"

if [ ! -f "$PRD_FILE" ]; then
    echo "Error: prd.json not found"
    exit 1
fi

if [ ! -f "$PROGRESS_FILE" ]; then
    echo "# Ralph Progress Log" > "$PROGRESS_FILE"
    echo "Started: $(date)" >> "$PROGRESS_FILE"
    echo "" >> "$PROGRESS_FILE"
    echo "## Codebase Patterns" >> "$PROGRESS_FILE"
    echo "" >> "$PROGRESS_FILE"
    echo "---" >> "$PROGRESS_FILE"
fi

echo "Starting Ralph - Max iterations: $MAX_ITERATIONS"

for i in $(seq 1 $MAX_ITERATIONS); do
    echo ""
    echo "═══════════════════════════════════════════════════════"
    echo " Ralph Iteration $i of $MAX_ITERATIONS"
    echo "═══════════════════════════════════════════════════════"

    OUTPUT=$(cat "$PROMPT_FILE" | amp -x 2>&1 | tee /dev/stderr) || true

    if echo "$OUTPUT" | grep -q "<promise>COMPLETE</promise>"; then
        echo ""
        echo "✓ Ralph completed all tasks!"
        exit 0
    fi

    echo "Iteration $i complete."
    sleep 2
done

echo "Ralph reached max iterations."
exit 1
