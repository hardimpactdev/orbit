#!/bin/bash
# Stop hook to check:
# 1. laravel.log for new errors
# 2. Test coverage for changed files
# Blocks Claude from stopping if issues are detected

set -e

LOG_FILE="$CLAUDE_PROJECT_DIR/storage/logs/laravel.log"
STATE_FILE="$CLAUDE_PROJECT_DIR/.claude/.laravel-log-position"

# Read input from stdin
INPUT=$(cat)

# Check if we're already in a stop hook loop to prevent infinite recursion
if echo "$INPUT" | grep -q '"stop_hook_active":true'; then
  exit 0
fi

ISSUES=""

# ============================================
# Check 1: Laravel log errors
# ============================================
if [ -f "$LOG_FILE" ]; then
  CURRENT_SIZE=$(wc -c < "$LOG_FILE" | tr -d ' ')

  if [ -f "$STATE_FILE" ]; then
    PREV_SIZE=$(cat "$STATE_FILE" | tr -d ' ')

    if [ "$CURRENT_SIZE" -gt "$PREV_SIZE" ]; then
      NEW_CONTENT=$(tail -c "+$((PREV_SIZE + 1))" "$LOG_FILE")
      NEW_ERRORS=$(echo "$NEW_CONTENT" | grep -i -E "\[.*\] (production|local|staging)\.ERROR:|Exception|Fatal|Stack trace:" | head -50 || true)

      if [ -n "$NEW_ERRORS" ]; then
        ISSUES="${ISSUES}## Laravel Log Errors\n\nNew errors detected in storage/logs/laravel.log:\n\n${NEW_ERRORS}\n\n"
      fi
    fi
  fi

  # Update position for next check
  echo "$CURRENT_SIZE" > "$STATE_FILE"
fi

# ============================================
# Check 2: Test coverage for changed files
# ============================================
cd "$CLAUDE_PROJECT_DIR"

# Get changed PHP files (staged and unstaged)
CHANGED_FILES=$(git diff --name-only HEAD 2>/dev/null || true)
STAGED_FILES=$(git diff --cached --name-only 2>/dev/null || true)
ALL_CHANGED=$(echo -e "${CHANGED_FILES}\n${STAGED_FILES}" | sort -u | grep '\.php$' || true)

MISSING_TESTS=""
ORPHANED_TESTS=""

for file in $ALL_CHANGED; do
  # Skip if file doesn't exist (was deleted)
  if [ ! -f "$file" ]; then
    # Check if it's a deleted app file that might have orphaned tests
    if [[ "$file" == app/* ]]; then
      # Determine potential test file paths
      relative_path="${file#app/}"
      class_name=$(basename "$file" .php)

      # Check for Unit test
      unit_test="tests/Unit/${relative_path%%.php}Test.php"
      if [ -f "$unit_test" ]; then
        ORPHANED_TESTS="${ORPHANED_TESTS}- $unit_test (source file $file was deleted)\n"
      fi

      # Check for Feature test
      feature_test="tests/Feature/${relative_path%%.php}Test.php"
      if [ -f "$feature_test" ]; then
        ORPHANED_TESTS="${ORPHANED_TESTS}- $feature_test (source file $file was deleted)\n"
      fi
    fi
    continue
  fi

  # Only check app files for test coverage
  if [[ "$file" != app/* ]]; then
    continue
  fi

  # Skip certain directories that typically don't need direct tests
  if [[ "$file" == app/Providers/* ]] || [[ "$file" == app/Console/Kernel.php ]] || [[ "$file" == app/Http/Kernel.php ]]; then
    continue
  fi

  # Determine test file paths
  relative_path="${file#app/}"
  class_name=$(basename "$file" .php)

  # Check for corresponding test files
  unit_test="tests/Unit/${relative_path%%.php}Test.php"
  feature_test="tests/Feature/${relative_path%%.php}Test.php"

  # Also check for alternate naming conventions
  unit_alt="tests/Unit/${class_name}Test.php"
  feature_alt="tests/Feature/${class_name}Test.php"

  # Check if any test exists for this file
  if [ ! -f "$unit_test" ] && [ ! -f "$feature_test" ] && [ ! -f "$unit_alt" ] && [ ! -f "$feature_alt" ]; then
    # Check tests directory for any file containing tests for this class
    test_exists=$(grep -rl "class ${class_name}" tests/ 2>/dev/null | head -1 || true)

    if [ -z "$test_exists" ]; then
      MISSING_TESTS="${MISSING_TESTS}- $file (expected: $unit_test or $feature_test)\n"
    fi
  fi
done

# Build test coverage issues message
if [ -n "$MISSING_TESTS" ]; then
  ISSUES="${ISSUES}## Missing Tests\n\nThe following changed files don't have corresponding tests:\n\n${MISSING_TESTS}\nConsider creating tests for these files.\n\n"
fi

if [ -n "$ORPHANED_TESTS" ]; then
  ISSUES="${ISSUES}## Potentially Orphaned Tests\n\nThe following test files may need to be removed or updated:\n\n${ORPHANED_TESTS}\n"
fi

# ============================================
# Output result
# ============================================
if [ -n "$ISSUES" ]; then
  # Escape for JSON
  ESCAPED_ISSUES=$(echo -e "$ISSUES" | sed 's/"/\\"/g' | sed ':a;N;$!ba;s/\n/\\n/g')

  cat << EOF
{
  "decision": "block",
  "reason": "Please review the following issues before completing:\n\n${ESCAPED_ISSUES}"
}
EOF
  exit 0
fi

exit 0
