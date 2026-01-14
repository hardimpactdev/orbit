# Declarative Service Management - Verification Criteria

Generated: 2026-01-12
Plan: docs/service-management-plan.md

## Phase 1: DTOs and Template Loader (Steps 1-2)

| Check | Command | Expected | Source |
|-------|---------|----------|--------|
| ServiceTemplate DTO exists | `ssh launchpad@ai "test -f ~/projects/launchpad-cli/app/Data/ServiceTemplate.php && echo exists"` | `exists` | plan:71 |
| ServiceTemplateLoader exists | `ssh launchpad@ai "test -f ~/projects/launchpad-cli/app/Services/ServiceTemplateLoader.php && echo exists"` | `exists` | plan:89 |
| ServiceConfigValidator exists | `ssh launchpad@ai "test -f ~/projects/launchpad-cli/app/Services/ServiceConfigValidator.php && echo exists"` | `exists` | plan:96 |
| Unit tests for templates pass | `ssh launchpad@ai "cd ~/projects/launchpad-cli && ./vendor/bin/pest --filter='ServiceTemplate'"` | `Tests passed` | test pattern |
| Full test suite passes | `ssh launchpad@ai "cd ~/projects/launchpad-cli && ./vendor/bin/pest"` | `Tests passed` | user choice |

## Phase 2: Compose Generator and Service Manager (Steps 3-4)

| Check | Command | Expected | Source |
|-------|---------|----------|--------|
| ComposeGenerator exists | `ssh launchpad@ai "test -f ~/projects/launchpad-cli/app/Services/ComposeGenerator.php && echo exists"` | `exists` | plan:108 |
| ServiceManager exists | `ssh launchpad@ai "test -f ~/projects/launchpad-cli/app/Services/ServiceManager.php && echo exists"` | `exists` | plan:120 |
| services.yaml.stub created | `ssh launchpad@ai "test -f ~/projects/launchpad-cli/stubs/services.yaml.stub && echo exists"` | `exists` | plan:38 |
| Compose generation works | `ssh launchpad@ai "cd ~/projects/launchpad-cli && php launchpad init --dry-run 2>/dev/null \| grep -c 'docker-compose'"` | `>=1` | plan:109 |
| Variable interpolation works | `ssh launchpad@ai "grep -c 'launchpad-postgres' ~/.config/launchpad/docker-compose.yaml"` | `>=1` | plan:265 |
| Full test suite passes | `ssh launchpad@ai "cd ~/projects/launchpad-cli && ./vendor/bin/pest"` | `Tests passed` | user choice |

## Phase 3: CLI Commands (Step 5)

| Check | Command | Expected | Source |
|-------|---------|----------|--------|
| ServiceListCommand exists | `ssh launchpad@ai "test -f ~/projects/launchpad-cli/app/Commands/Service/ServiceListCommand.php && echo exists"` | `exists` | plan:46 |
| ServiceEnableCommand exists | `ssh launchpad@ai "test -f ~/projects/launchpad-cli/app/Commands/Service/ServiceEnableCommand.php && echo exists"` | `exists` | plan:47 |
| ServiceDisableCommand exists | `ssh launchpad@ai "test -f ~/projects/launchpad-cli/app/Commands/Service/ServiceDisableCommand.php && echo exists"` | `exists` | plan:48 |
| ServiceConfigureCommand exists | `ssh launchpad@ai "test -f ~/projects/launchpad-cli/app/Commands/Service/ServiceConfigureCommand.php && echo exists"` | `exists` | plan:49 |
| ServiceInfoCommand exists | `ssh launchpad@ai "test -f ~/projects/launchpad-cli/app/Commands/Service/ServiceInfoCommand.php && echo exists"` | `exists` | plan:50 |
| service:list --json works | `ssh launchpad@ai "cd ~/projects/launchpad-cli && php launchpad service:list --json \| jq -e '.services'"` | exits 0 | plan:143 |
| service:enable mysql works | `ssh launchpad@ai "cd ~/projects/launchpad-cli && php launchpad service:enable mysql --json \| jq -e '.success'"` | `true` | plan:231-233 |
| service:disable mysql works | `ssh launchpad@ai "cd ~/projects/launchpad-cli && php launchpad service:disable mysql --json \| jq -e '.success'"` | `true` | plan:247-249 |
| Full test suite passes | `ssh launchpad@ai "cd ~/projects/launchpad-cli && ./vendor/bin/pest"` | `Tests passed` | user choice |

## Phase 4: Service Templates (Step 6)

| Check | Command | Expected | Source |
|-------|---------|----------|--------|
| templates/ directory created | `ssh launchpad@ai "test -d ~/projects/launchpad-cli/stubs/templates && echo exists"` | `exists` | plan:30 |
| postgres.yaml template exists | `ssh launchpad@ai "test -f ~/projects/launchpad-cli/stubs/templates/postgres.yaml && echo exists"` | `exists` | plan:31 |
| mysql.yaml template exists | `ssh launchpad@ai "test -f ~/projects/launchpad-cli/stubs/templates/mysql.yaml && echo exists"` | `exists` | plan:32 |
| redis.yaml template exists | `ssh launchpad@ai "test -f ~/projects/launchpad-cli/stubs/templates/redis.yaml && echo exists"` | `exists` | plan:33 |
| mailpit.yaml template exists | `ssh launchpad@ai "test -f ~/projects/launchpad-cli/stubs/templates/mailpit.yaml && echo exists"` | `exists` | plan:34 |
| reverb.yaml template exists | `ssh launchpad@ai "test -f ~/projects/launchpad-cli/stubs/templates/reverb.yaml && echo exists"` | `exists` | plan:35 |
| dns.yaml template exists | `ssh launchpad@ai "test -f ~/projects/launchpad-cli/stubs/templates/dns.yaml && echo exists"` | `exists` | plan:36 |
| meilisearch.yaml template exists | `ssh launchpad@ai "test -f ~/projects/launchpad-cli/stubs/templates/meilisearch.yaml && echo exists"` | `exists` | plan:37 |
| Template has required fields | `ssh launchpad@ai "grep -c '^name:' ~/projects/launchpad-cli/stubs/templates/postgres.yaml"` | `1` | plan:158 |
| Template has docker section | `ssh launchpad@ai "grep -c '^docker:' ~/projects/launchpad-cli/stubs/templates/postgres.yaml"` | `1` | plan:178 |
| Full test suite passes | `ssh launchpad@ai "cd ~/projects/launchpad-cli && ./vendor/bin/pest"` | `Tests passed` | user choice |

## Phase 5: Update Existing Commands & Remove Legacy (Steps 7-8)

| Check | Command | Expected | Source |
|-------|---------|----------|--------|
| CONTAINERS constant removed | `ssh launchpad@ai "! grep -q 'const CONTAINERS' ~/projects/launchpad-cli/app/Services/DockerManager.php && echo removed"` | `removed` | plan:217-218 |
| Legacy postgres stub deleted | `ssh launchpad@ai "! test -f ~/projects/launchpad-cli/stubs/postgres/docker-compose.yml && echo deleted"` | `deleted` | plan:323 |
| Legacy redis stub deleted | `ssh launchpad@ai "! test -f ~/projects/launchpad-cli/stubs/redis/docker-compose.yml && echo deleted"` | `deleted` | plan:324 |
| Legacy mailpit stub deleted | `ssh launchpad@ai "! test -f ~/projects/launchpad-cli/stubs/mailpit/docker-compose.yml && echo deleted"` | `deleted` | plan:325 |
| Legacy reverb stub deleted | `ssh launchpad@ai "! test -f ~/projects/launchpad-cli/stubs/reverb/docker-compose.yml && echo deleted"` | `deleted` | plan:326 |
| Legacy dns stub deleted | `ssh launchpad@ai "! test -f ~/projects/launchpad-cli/stubs/dns/docker-compose.yml && echo deleted"` | `deleted` | plan:327 |
| StartCommand uses ServiceManager | `ssh launchpad@ai "grep -c 'ServiceManager' ~/projects/launchpad-cli/app/Commands/StartCommand.php"` | `>=1` | plan:200-203 |
| StopCommand uses ServiceManager | `ssh launchpad@ai "grep -c 'ServiceManager' ~/projects/launchpad-cli/app/Commands/StopCommand.php"` | `>=1` | plan:205-206 |
| StatusCommand uses ServiceManager | `ssh launchpad@ai "grep -c 'ServiceManager' ~/projects/launchpad-cli/app/Commands/StatusCommand.php"` | `>=1` | plan:208-211 |
| launchpad start works | `ssh launchpad@ai "cd ~/projects/launchpad-cli && php launchpad start --json \| jq -e '.success'"` | `true` | plan:353 |
| launchpad status shows services | `ssh launchpad@ai "cd ~/projects/launchpad-cli && php launchpad status --json \| jq -e '.services'"` | exits 0 | plan:355 |
| Full test suite passes | `ssh launchpad@ai "cd ~/projects/launchpad-cli && ./vendor/bin/pest"` | `Tests passed` | user choice |

## End-to-End Verification

After all phases complete, run this full workflow:

```bash
# SSH to remote server
ssh launchpad@ai

cd ~/projects/launchpad-cli

# 1. Initialize with default services
php launchpad init

# 2. Check generated files exist
cat ~/.config/launchpad/services.yaml
cat ~/.config/launchpad/docker-compose.yaml

# 3. List services (human-readable)
php launchpad service:list

# 4. List services (JSON)
php launchpad service:list --json | jq .

# 5. List available services
php launchpad service:list --available

# 6. Enable a new service
php launchpad service:enable mysql

# 7. Verify docker-compose was regenerated with mysql
grep mysql ~/.config/launchpad/docker-compose.yaml

# 8. Configure a service
php launchpad service:configure mysql --set port=3307

# 9. Start all services
php launchpad start

# 10. Check status
php launchpad status

# 11. Disable service
php launchpad service:disable mysql

# 12. Verify mysql removed from compose
! grep mysql ~/.config/launchpad/docker-compose.yaml && echo "mysql removed"

# 13. Restart to apply changes
php launchpad restart
```

## Summary

- Total phases: 5
- Total checks: 32
- Estimated verification time: ~120 seconds (including test suite runs)
- Rollback strategy: git (no explicit backup)

Ready for task creation. Run:
```
/spec-workflow continue
```
