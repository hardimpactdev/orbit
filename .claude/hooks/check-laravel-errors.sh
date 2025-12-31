#!/bin/bash
# Stop hook to check laravel.log for new errors
# Blocks Claude from stopping if new errors are detected

set -e

LOG_FILE="$CLAUDE_PROJECT_DIR/storage/logs/laravel.log"
STATE_FILE="$CLAUDE_PROJECT_DIR/.claude/.laravel-log-position"

# Read input from stdin
INPUT=$(cat)

# Check if we're already in a stop hook loop to prevent infinite recursion
if echo "$INPUT" | grep -q '"stop_hook_active":true'; then
  exit 0
fi

# Exit silently if log file doesn't exist
if [ ! -f "$LOG_FILE" ]; then
  exit 0
fi

# Get current file size
CURRENT_SIZE=$(wc -c < "$LOG_FILE" | tr -d ' ')

# Initialize state file if it doesn't exist
if [ ! -f "$STATE_FILE" ]; then
  echo "$CURRENT_SIZE" > "$STATE_FILE"
  exit 0
fi

# Read previous position
PREV_SIZE=$(cat "$STATE_FILE" | tr -d ' ')

# If log hasn't grown, nothing to check
if [ "$CURRENT_SIZE" -le "$PREV_SIZE" ]; then
  exit 0
fi

# Extract new content from the log
NEW_CONTENT=$(tail -c "+$((PREV_SIZE + 1))" "$LOG_FILE")

# Look for errors/exceptions in the new content
NEW_ERRORS=$(echo "$NEW_CONTENT" | grep -i -E "\[.*\] (production|local|staging)\.ERROR:|Exception|Fatal|Stack trace:" | head -50 || true)

if [ -n "$NEW_ERRORS" ]; then
  # Output JSON to block stopping and provide context
  cat << EOF
{
  "decision": "block",
  "reason": "New errors detected in storage/logs/laravel.log. Please review and fix the following errors before completing:\n\n$NEW_ERRORS"
}
EOF

  # Update position for next check
  echo "$CURRENT_SIZE" > "$STATE_FILE"
  exit 0
fi

# No errors found, update position and allow stop
echo "$CURRENT_SIZE" > "$STATE_FILE"
exit 0
