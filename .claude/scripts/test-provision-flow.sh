#!/bin/bash
#
# Test the full project provisioning flow via API
# Usage: ./test-provision-flow.sh [project-name] [--cleanup]
#
# This script tests provisioning through the web app API (the production flow),
# NOT by calling the CLI directly.
#

set -e

SERVER="launchpad@ai"
TEMPLATE="hardimpactdev/liftoff-starterkit"
PROJECT_NAME="${1:-test-$(date +%s)}"
TLD="ccc"
API_URL="https://launchpad.${TLD}/api"
PROVISION_TIMEOUT=60  # 60 seconds max for full provisioning
CLEANUP=false

# Parse arguments
for arg in "$@"; do
    case $arg in
        --cleanup) CLEANUP=true ;;
        --*) ;; # ignore other flags
        *) PROJECT_NAME="$arg" ;;
    esac
done

echo "=== Launchpad API Provision Flow Test ==="
echo "Project: $PROJECT_NAME"
echo "Template: $TEMPLATE"
echo "API: $API_URL"
echo "Timeout: ${PROVISION_TIMEOUT}s"
echo ""

# Step 0: Clean up any existing project with same name
echo "[0/5] Cleaning up existing project..."
ssh $SERVER "rm -rf ~/projects/$PROJECT_NAME" 2>/dev/null || true
ssh $SERVER "gh repo delete nckrtl/$PROJECT_NAME --yes" 2>/dev/null || true
echo "  ✓ Cleanup done"

# Step 1: Create project via API
echo ""
echo "[1/5] Creating project via API..."
START_TIME=$(date +%s)

API_RESPONSE=$(curl -s -X POST "${API_URL}/projects" \
  -H "Content-Type: application/json" \
  -d "{\"name\": \"$PROJECT_NAME\", \"template\": \"$TEMPLATE\", \"db_driver\": \"pgsql\", \"visibility\": \"private\"}")

# Check API response
if echo "$API_RESPONSE" | grep -q '"success":true'; then
    echo "  ✓ API accepted request"
    echo "  Response: $API_RESPONSE"
else
    echo "  ✗ API request failed!"
    echo "  Response: $API_RESPONSE"
    exit 1
fi

# Step 2: Wait for provisioning to complete
echo ""
echo "[2/5] Waiting for provisioning (max ${PROVISION_TIMEOUT}s)..."

ELAPSED=0
while [ $ELAPSED -lt $PROVISION_TIMEOUT ]; do
    sleep 2
    ELAPSED=$((ELAPSED + 2))

    # Check if .env exists (sign of completion)
    if ssh $SERVER "test -f ~/projects/$PROJECT_NAME/.env" 2>/dev/null; then
        echo "  ✓ .env file exists after ${ELAPSED}s"
        break
    fi

    # Check for job failure in logs
    if ssh $SERVER "grep -q 'CreateProjectJob: Exception.*$PROJECT_NAME' ~/.config/launchpad/web/storage/logs/laravel.log 2>/dev/null"; then
        echo "  ✗ Job failed! Check logs:"
        ssh $SERVER "grep '$PROJECT_NAME' ~/.config/launchpad/web/storage/logs/laravel.log | tail -5"
        exit 1
    fi

    echo "  ... waiting (${ELAPSED}s)"
done

if [ $ELAPSED -ge $PROVISION_TIMEOUT ]; then
    echo "  ✗ Provisioning timed out after ${PROVISION_TIMEOUT}s!"
    echo ""
    echo "Debug: Check job logs:"
    ssh $SERVER "grep '$PROJECT_NAME' ~/.config/launchpad/web/storage/logs/laravel.log | tail -10"
    exit 1
fi

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

# Step 3: Check if build artifacts exist
echo ""
echo "[3/5] Checking build artifacts..."
BUILD_CHECK=$(ssh $SERVER "ls ~/projects/$PROJECT_NAME/public/build/manifest.json 2>/dev/null && echo 'EXISTS' || echo 'MISSING'")
if [ "$BUILD_CHECK" = "MISSING" ]; then
    echo "  ✗ FAILED: Build artifacts missing (public/build/manifest.json)"
    exit 1
fi
echo "  ✓ Build artifacts exist"

# Step 4: Test HTTPS response
echo ""
echo "[4/5] Testing HTTPS response..."
sleep 1  # Brief pause for Caddy
HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' "https://$PROJECT_NAME.$TLD/" 2>/dev/null)
if [ "$HTTP_CODE" = "200" ]; then
    echo "  ✓ HTTPS returns 200 OK"
else
    echo "  ✗ FAILED: HTTPS returned $HTTP_CODE"
    exit 1
fi

# Step 5: Check project appears in API
echo ""
echo "[5/5] Checking project appears in API..."
API_CHECK=$(curl -s "${API_URL}/projects" | grep -o "\"name\":\"$PROJECT_NAME\"" || echo "MISSING")
if [ "$API_CHECK" = "MISSING" ]; then
    echo "  ✗ FAILED: Project not in API response"
    exit 1
fi
echo "  ✓ Project appears in API"

echo ""
echo "=== All tests passed in ${DURATION}s! ==="
echo "Site: https://$PROJECT_NAME.$TLD/"

# Cleanup if requested
if [ "$CLEANUP" = true ]; then
    echo ""
    echo "Cleaning up..."
    ssh $SERVER "rm -rf ~/projects/$PROJECT_NAME"
    ssh $SERVER "gh repo delete nckrtl/$PROJECT_NAME --yes" 2>/dev/null || true
    ssh $SERVER "docker exec launchpad-postgres psql -U launchpad -c 'DROP DATABASE IF EXISTS \"$PROJECT_NAME\"'" 2>/dev/null || true
    echo "  ✓ Project deleted"
else
    echo ""
    echo "To clean up: ssh $SERVER \"rm -rf ~/projects/$PROJECT_NAME && gh repo delete nckrtl/$PROJECT_NAME --yes\""
fi
