# Service Management Feature - Verification Criteria

Generated: 2026-01-13
Plan: docs/plans/service-management/specification.md

## Phase 1: CLI Service Management System

| Check | Command | Expected | Source |
|-------|---------|----------|--------|
| Service templates exist | `ssh launchpad@ai "test -f ~/projects/orbit-cli/stubs/templates/postgres.yaml && test -f ~/projects/orbit-cli/stubs/templates/mysql.yaml && test -f ~/projects/orbit-cli/stubs/templates/meilisearch.yaml"` | exit code 0 | plan step 1.4 |
| Service commands registered | `ssh launchpad@ai "cd ~/projects/orbit-cli && php launchpad list" \| grep -c 'service:'` | returns 5 (list, enable, disable, configure, info) | plan step 1.5 |
| Templates load correctly | `ssh launchpad@ai "cd ~/projects/orbit-cli && php launchpad service:list --available --json" \| jq -e '.available \| contains(["mysql", "meilisearch"])'` | exit code 0 (both services in array) | plan verification section |
| Required service protection | `ssh launchpad@ai "cd ~/projects/orbit-cli && ! php launchpad service:disable dns --json 2>&1" \| grep -qi 'required'` | exit code 0 (error contains "required") | plan verification section |
| Full cycle test | `ssh launchpad@ai "cd ~/projects/orbit-cli && php launchpad service:enable mysql --json && php launchpad service:configure mysql --set port=3307 --json && grep -q 'port: 3307' ~/.config/orbit/services.yaml && php launchpad service:disable mysql --json && ! grep -q 'mysql:' ~/.config/orbit/services.yaml"` | exit code 0 (enable → configure → verify → disable → verify removed) | user requirement: full cycle |

## Phase 2: Desktop App UI Layer

| Check | Command | Expected | Source |
|-------|---------|----------|--------|
| Backend methods exist | `grep -c "function listServices\|function enableService\|function disableService\|function configureService\|function getServiceInfo" app/Services/LaunchpadService.php` | returns 5 | plan step 2.1 |
| Routes registered | `grep -c "services/{service}/enable\|services/{service}/config\|services/available" routes/web.php` | returns 3 | plan step 2.4 |
| Vue components exist | `test -f resources/js/components/AddServiceModal.vue && test -f resources/js/components/ConfigureServiceModal.vue` | exit code 0 | plan step 2.6 |
| Services page updated | `grep -c "showAddServiceModal\|showConfigureModal" resources/js/pages/environments/Services.vue` | returns 2 | plan step 2.5 |
| Remote API controller exists | `ssh launchpad@ai "test -f ~/projects/orbit-cli/web/app/Http/Controllers/Api/ServiceController.php"` | exit code 0 | plan step 2.7 |

### E2E Test Scenario

**Manual verification (desktop app running):**

1. Open desktop app and navigate to Services page for environment
2. Click "Add Service" button → Modal opens with available services
3. Select "MySQL" from modal → Service appears in list with "Stopped" status
4. Click "Configure" on MySQL → Modal opens with port, version, password fields
5. Change port to 3307, click "Save" → Success message appears
6. Click "Start" on MySQL → Status changes to "Running"
7. SSH to server: `grep -q 'port: 3307' ~/.config/orbit/ai/services.yaml` → exit code 0
8. Click "Remove" on MySQL → Confirmation, service removed from list
9. Try to click "Remove" on Redis → Error message "Cannot remove required service"

**Expected timing:** < 60 seconds for full E2E flow

## Summary

- Total phases: 2
- Total automated checks: 10
- Total E2E scenarios: 1
- Estimated verification time: ~45 seconds (automated) + ~60 seconds (E2E)

## Notes

- Phase 1 must complete successfully before Phase 2 can be verified
- SSH access to `launchpad@ai` required for all Phase 1 checks
- E2E test requires desktop app running in development mode (`php artisan native:serve`)
- Full cycle test cleans up after itself (disables mysql at end)
