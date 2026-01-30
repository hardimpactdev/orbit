# Boris Collaboration Plan: Orbit Development

## Overview

Two Boris instances working together to continuously improve Orbit:
- **Boris VPS** — Core/web development, releases, CI monitoring
- **Boris Mini** — Desktop app testing, E2E validation, macOS-specific work

## Division of Labor

### Boris VPS (this instance)
- `packages/core` — PHP library, provisioning logic
- `packages/app` — Laravel web app bundled with CLI
- `packages/cli` — Command-line interface
- `packages/web` — Web components
- Release management (versioning, changelogs, tagging)
- Code quality (tests, static analysis, refactoring)
- Monitoring CI status
- Creating E2E tasks for Mini via Kanban

### Boris Mini
- `packages/desktop` — Tauri desktop app testing
- E2E testing of bundled web app (via CLI)
- E2E testing of desktop app
- Browser automation (dev servers, console error checking)
- macOS-specific issues
- Real-world provisioning tests

## Existing CI Infrastructure

### Workflows (`.github/workflows/`)

#### `ci.yml` — Continuous Integration
- **Trigger:** Push/PR to main
- **What it does:**
  - Detects which packages changed
  - Runs PHPStan static analysis per package
  - Runs Pest tests per package
  - Checks for changesets on PRs
- **Packages tested:** core, app, cli, web, desktop

#### `build-cli.yml` — Release Build
- **Trigger:** Tag push (`v*`) or manual dispatch
- **What it does:**
  - Builds CLI PHAR with bundled web app
  - Sets version from tag
  - Verifies PHAR integrity (size, execution, commands)
  - Creates release on `hardimpactdev/orbit-cli` with PHAR + checksums
- **Output:** `orbit.phar` available for `orbit upgrade`

#### `split.yml` — Monorepo Split
- **Trigger:** Push to main, tags
- **What it does:**
  - Splits packages to individual repos via splitsh-lite
  - Pushes tags to split repos
- **Repos:** orbit-core, orbit-app, orbit-cli

### Release Flow
```
Tag vX.Y.Z pushed
    ↓
ci.yml runs (tests all packages)
    ↓
build-cli.yml runs (builds PHAR)
    ↓
split.yml runs (syncs to split repos)
    ↓
Release created on orbit-cli repo
    ↓
VPS creates E2E task for Mini
    ↓
Mini validates release
```

## Mini Fresh Start Instructions

**IMPORTANT:** Mini must purge all existing Orbit installations and start fresh.

### Cleanup Steps (Mini)
```bash
# Remove any existing Orbit installations
rm -rf ~/orbit
rm -rf ~/.local/bin/orbit
rm -rf ~/projects/orbit*
rm -rf ~/.config/orbit

# Clear any cached builds
rm -rf ~/Library/Caches/com.orbit.*
rm -rf ~/Library/Application\ Support/orbit
```

### Fresh Install Steps (Mini)

#### Option A: From Release (Production Testing)
```bash
# Download latest PHAR from GitHub releases
curl -sL https://github.com/hardimpactdev/orbit-cli/releases/latest/download/orbit.phar -o orbit.phar
chmod +x orbit.phar
sudo mv orbit.phar /usr/local/bin/orbit

# Verify
orbit --version
```

#### Option B: From Source (Development Testing)
```bash
# Clone monorepo
git clone https://github.com/hardimpactdev/orbit-dev.git ~/projects/orbit-dev
cd ~/projects/orbit-dev

# Install dependencies
composer install

# Build CLI locally
cd packages/cli
composer install
php box.phar compile  # if testing PHAR
# OR use directly: php bin/orbit
```

#### Desktop App
```bash
# Clone and build desktop app
cd ~/projects/orbit-dev/packages/desktop
bun install
bun run tauri build

# Install the built .dmg
open src-tauri/target/release/bundle/dmg/*.dmg
```

## Kanban Workflow

### Task Labels
- `[Core]` — packages/core work
- `[App]` — packages/app work  
- `[CLI]` — packages/cli work
- `[Desktop]` — packages/desktop work
- `[E2E]` — End-to-end testing task
- `[Release]` — Release-related task
- `[Bug]` — Bug fix

### Assignees
- `boris-vps` — VPS instance
- `boris-mini` — Mac Mini instance

## E2E Test Protocol

### When to Trigger
After a release build succeeds (build-cli.yml completes), VPS creates:
```
Title: [E2E] Validate release vX.Y.Z
Assignee: boris-mini
Status: todo
Description: [checklist below]
```

### E2E Test Checklist (Boris Mini)

#### Prerequisites
- [ ] Fresh Orbit install (no leftover state)
- [ ] Version matches release tag

#### CLI Tests
- [ ] `orbit --version` — shows correct version
- [ ] `orbit status` — returns valid response
- [ ] `orbit list` — works (empty or with projects)

#### Bundled Web App Tests (`orbit serve`)
- [ ] `orbit serve` — starts web server
- [ ] Dashboard loads in browser at configured URL
- [ ] No console errors on any page
- [ ] Can navigate between all sections

#### Environment Management
- [ ] **Create environment** — Add new local or remote environment
- [ ] **Edit environment** — Update name, host, user, port
- [ ] **Configure TLD** — Change .test to custom TLD
- [ ] **External access** — Toggle and configure external_host
- [ ] **Delete environment** — Remove environment

#### Project Management
- [ ] **View projects** — List shows all linked projects
- [ ] **Project visible in browser** — Project accessible at `project.test`
- [ ] **Change PHP version** — Switch project between PHP 8.3/8.4/8.5
- [ ] **Provision with template** — Apply Craft CMS starter kit
- [ ] **Dev server runs** — No console errors in browser
- [ ] **Delete project** — Remove project cleanly

#### Workspace Management
- [ ] **Create workspace** — New workspace with name
- [ ] **Add project to workspace** — Associate existing project
- [ ] **Remove project from workspace** — Disassociate project
- [ ] **Delete workspace** — Remove workspace

#### Desktop App Tests
- [ ] App launches without errors
- [ ] Version matches release
- [ ] Dashboard loads
- [ ] Environment list displays
- [ ] Project list displays
- [ ] Can perform all above actions via UI
- [ ] No crashes or freezes
- [ ] Touch ID works for DNS setup (if applicable)

### Reporting Results

**Pass:**
```
Move task to Done
Comment: "E2E passed for vX.Y.Z - all checks green"
```

**Fail:**
```
Move task to Blocked
Comment with:
- Which checks failed
- Error messages/screenshots
- Steps to reproduce
```
VPS will then create bug tickets for failures.

## Communication Protocol

### Async (default)
- Kanban task comments for updates
- Task status changes for progress

### Escalation (Telegram)
- Critical release blockers
- Questions needing Nick's input
- Coordination issues between instances

## Next Steps

### Immediate (VPS)
1. [ ] Run full test suite locally, fix any failures
2. [ ] Ensure CI is green on main
3. [ ] Create first E2E task for Mini after next release

### Immediate (Mini)
1. [ ] Purge all existing Orbit installations
2. [ ] Wait for E2E task on Kanban
3. [ ] Follow fresh install instructions
4. [ ] Run through E2E checklist
5. [ ] Report results

### Future Improvements
- Automated E2E task creation via GitHub webhook
- E2E test script that Mini can run automatically
- Results reporting back to GitHub release notes
