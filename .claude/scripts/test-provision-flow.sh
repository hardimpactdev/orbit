#!/bin/bash
#
# Test the full project provisioning flow via API
# Usage: ./test-provision-flow.sh --tld <tld> [project-name] [--cleanup]
#
# This script tests provisioning through the orbit web app API (the production flow),
# NOT by calling the CLI directly.
#
# Environment details (local/remote, host, user) are automatically looked up from
# the orbit-desktop database based on the TLD.
#
# Examples:
#   ./test-provision-flow.sh --tld test                    # Local test (orbit.test)
#   ./test-provision-flow.sh --tld ccc                     # Remote test (orbit.ccc via SSH)
#   ./test-provision-flow.sh --tld test my-project         # Local with specific name
#   ./test-provision-flow.sh --tld test --cleanup          # Local test with cleanup
#

set -e

# Defaults
TEMPLATE="hardimpactdev/liftoff-starterkit"
PROJECT_NAME=""
PROVISION_TIMEOUT=90  # 90 seconds max for full provisioning
CLEANUP=false
TLD=""

# Database path for orbit-desktop
DB_PATH="$(dirname "$0")/../../database/nativephp.sqlite"

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --cleanup) CLEANUP=true; shift ;;
        --tld) TLD="$2"; shift 2 ;;
        --*) shift ;; # ignore other flags
        *) PROJECT_NAME="$1"; shift ;;
    esac
done

# Validate TLD is provided
if [ -z "$TLD" ]; then
    echo "Error: --tld is required"
    echo "Usage: $0 --tld <tld> [project-name] [--cleanup]"
    echo ""
    echo "Available environments:"
    sqlite3 "$DB_PATH" "SELECT '  ' || tld || ' (' || name || ', ' || CASE WHEN is_local THEN 'local' ELSE 'remote' END || ')' FROM environments" 2>/dev/null || echo "  (could not read database)"
    exit 1
fi

# Look up environment by TLD
ENV_ROW=$(sqlite3 "$DB_PATH" "SELECT is_local, host, user, port FROM environments WHERE tld = '$TLD'" 2>/dev/null)

if [ -z "$ENV_ROW" ]; then
    echo "Error: No environment found with TLD '$TLD'"
    echo ""
    echo "Available environments:"
    sqlite3 "$DB_PATH" "SELECT '  ' || tld || ' (' || name || ', ' || CASE WHEN is_local THEN 'local' ELSE 'remote' END || ')' FROM environments" 2>/dev/null || echo "  (could not read database)"
    exit 1
fi

# Parse environment details
IS_LOCAL=$(echo "$ENV_ROW" | cut -d'|' -f1)
HOST=$(echo "$ENV_ROW" | cut -d'|' -f2)
USER=$(echo "$ENV_ROW" | cut -d'|' -f3)
PORT=$(echo "$ENV_ROW" | cut -d'|' -f4)

# Set project name if not provided
if [ -z "$PROJECT_NAME" ]; then
    PROJECT_NAME="test-$(date +%s)"
fi

# Configuration based on local vs remote
API_URL="https://orbit.${TLD}/api"

if [ "$IS_LOCAL" = "1" ]; then
    # Local execution
    PROJECTS_DIR="$HOME/Projects"
    LOG_FILE="$HOME/.config/orbit/web/storage/logs/laravel.log"
    
    # Helper functions for local
    run_cmd() { eval "$1"; }
    check_file() { test -f "$1"; }
    check_log() { grep -q "$1" "$LOG_FILE" 2>/dev/null; }
    get_log() { grep "$1" "$LOG_FILE" 2>/dev/null | tail -"${2:-5}"; }
else
    # Remote execution via SSH
    SERVER="${USER}@${HOST}"
    [ "$PORT" != "22" ] && SSH_OPTS="-p $PORT" || SSH_OPTS=""
    PROJECTS_DIR="/home/${USER}/projects"
    LOG_FILE="/home/${USER}/.config/orbit/web/storage/logs/laravel.log"
    
    # Helper functions for remote (via SSH)
    run_cmd() { ssh $SSH_OPTS $SERVER "$1"; }
    check_file() { ssh $SSH_OPTS $SERVER "test -f '$1'" 2>/dev/null; }
    check_log() { ssh $SSH_OPTS $SERVER "grep -q '$1' '$LOG_FILE'" 2>/dev/null; }
    get_log() { ssh $SSH_OPTS $SERVER "grep '$1' '$LOG_FILE' | tail -${2:-5}"; }
fi

echo "=== Orbit Provision Flow Test ==="
echo "TLD: $TLD"
[ "$IS_LOCAL" = "1" ] && echo "Type: local" || echo "Type: remote ($USER@$HOST)"
echo "Project: $PROJECT_NAME"
echo "Template: $TEMPLATE"
echo "API: $API_URL"
echo "Projects dir: $PROJECTS_DIR"
echo "Timeout: ${PROVISION_TIMEOUT}s"
echo ""

# Step 0: Clean up any existing project with same name
echo "[0/5] Cleaning up existing project..."
run_cmd "rm -rf ${PROJECTS_DIR}/$PROJECT_NAME" 2>/dev/null || true
gh repo delete nckrtl/$PROJECT_NAME --yes 2>/dev/null || true
echo "  done"

# Step 1: Create project via API
echo ""
echo "[1/5] Creating project via API..."
START_TIME=$(date +%s)

API_RESPONSE=$(curl -s -X POST "${API_URL}/projects" \
  -H "Content-Type: application/json" \
  -d "{\"name\": \"$PROJECT_NAME\", \"template\": \"$TEMPLATE\", \"db_driver\": \"pgsql\", \"visibility\": \"private\"}")

# Check API response
if echo "$API_RESPONSE" | grep -q '"success":true'; then
    echo "  API accepted request"
    echo "  Response: $API_RESPONSE"
else
    echo "  API request failed!"
    echo "  Response: $API_RESPONSE"
    exit 1
fi

# Step 2: Wait for provisioning to complete
echo ""
echo "[2/5] Waiting for provisioning (max ${PROVISION_TIMEOUT}s)..."

ELAPSED=0
while [ $ELAPSED -lt $PROVISION_TIMEOUT ]; do
    sleep 3
    ELAPSED=$((ELAPSED + 3))

    # Check if .env exists (sign of completion)
    if check_file "${PROJECTS_DIR}/$PROJECT_NAME/.env"; then
        echo "  .env file exists after ${ELAPSED}s"
        break
    fi

    # Check for job failure in logs
    if check_log "CreateProjectJob: Exception.*$PROJECT_NAME"; then
        echo "  Job failed! Check logs:"
        get_log "$PROJECT_NAME" 5
        exit 1
    fi

    echo "  ... waiting (${ELAPSED}s)"
done

if [ $ELAPSED -ge $PROVISION_TIMEOUT ]; then
    echo "  Provisioning timed out after ${PROVISION_TIMEOUT}s!"
    echo ""
    echo "Debug: Check job logs:"
    get_log "$PROJECT_NAME" 10
    exit 1
fi

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

# Step 3: Check if build artifacts exist (optional - some templates don't have frontend)
echo ""
echo "[3/5] Checking build artifacts..."
if check_file "${PROJECTS_DIR}/$PROJECT_NAME/public/build/manifest.json"; then
    echo "  Build artifacts exist"
elif check_file "${PROJECTS_DIR}/$PROJECT_NAME/public/build/.vite/manifest.json"; then
    echo "  Build artifacts exist (Vite 5 format)"
else
    echo "  WARN: Build artifacts missing (may be expected for some templates)"
fi

# Step 4: Test HTTPS response
echo ""
echo "[4/5] Testing HTTPS response..."
sleep 2  # Brief pause for Caddy to pick up config
HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' "https://$PROJECT_NAME.$TLD/" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
    echo "  HTTPS returns 200 OK"
elif [ "$HTTP_CODE" = "500" ]; then
    echo "  WARN: HTTPS returns 500 (app error, but routing works)"
else
    echo "  WARN: HTTPS returned $HTTP_CODE"
fi

# Step 5: Check project appears in API
echo ""
echo "[5/5] Checking project appears in API..."
API_CHECK=$(curl -s "${API_URL}/projects" | grep -o "\"name\":\"$PROJECT_NAME\"" || echo "MISSING")
if [ "$API_CHECK" = "MISSING" ]; then
    echo "  WARN: Project not yet in API response (may need cache refresh)"
else
    echo "  Project appears in API"
fi

echo ""
echo "=== Provisioning completed in ${DURATION}s ==="
echo "Site: https://$PROJECT_NAME.$TLD/"

# Cleanup if requested
if [ "$CLEANUP" = true ]; then
    echo ""
    echo "Cleaning up..."
    run_cmd "rm -rf ${PROJECTS_DIR}/$PROJECT_NAME"
    gh repo delete nckrtl/$PROJECT_NAME --yes 2>/dev/null || true
    if [ "$IS_LOCAL" != "1" ]; then
        run_cmd "docker exec launchpad-postgres psql -U launchpad -c 'DROP DATABASE IF EXISTS \"$PROJECT_NAME\"'" 2>/dev/null || true
    fi
    echo "  Project deleted"
else
    echo ""
    if [ "$IS_LOCAL" = "1" ]; then
        echo "To clean up: rm -rf ${PROJECTS_DIR}/$PROJECT_NAME && gh repo delete nckrtl/$PROJECT_NAME --yes"
    else
        echo "To clean up: ssh $SERVER \"rm -rf ${PROJECTS_DIR}/$PROJECT_NAME\" && gh repo delete nckrtl/$PROJECT_NAME --yes"
    fi
fi
