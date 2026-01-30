# Orbit Feature Ideas

*Collected during overnight refactoring session 2026-01-30*

## Ideas to discuss with Nick at 08:00 CET

### CLI Improvements

1. **`orbit doctor`** — Diagnose common issues automatically
   - Check PHP versions installed vs configured
   - Verify Caddy is serving correctly
   - Test database connections
   - Validate project configs

2. **`orbit shell <project>`** — Quick shell into a project environment
   - Sets up the right PHP version, env vars
   - Like `tinker` but for any project type

3. **`orbit template list`** — Browse available project templates
   - Show starter kits that can be applied
   - Filter by framework (Laravel, Craft, WordPress, etc.)

4. **`orbit backup <project>`** — Backup project database and files
   - Quick snapshots before risky changes
   - Integrate with local storage or S3

### Web App / UI

5. **Real-time log streaming** — Tail logs in browser
   - PHP-FPM logs, Caddy access/error logs
   - Project-specific artisan logs
   - WebSocket-based live updates

6. **Quick actions** — One-click common tasks
   - "Clear caches"
   - "Run migrations"
   - "Restart PHP"
   - "Open in editor"

7. **Environment comparison** — Side-by-side view
   - Compare local vs remote environment configs
   - Highlight differences in PHP extensions, versions

8. **Project health dashboard** — At-a-glance status
   - Last git activity
   - Dependency update status (outdated packages)
   - Security vulnerabilities

### Developer Experience

9. **Automatic .env generation** — Smart defaults
   - Detect database type and suggest connection
   - Auto-generate APP_KEY
   - Set sensible local defaults

10. **IDE integration** — Editor commands
    - `orbit code <project>` — Open in VS Code
    - `orbit cursor <project>` — Open in Cursor
    - Already partial support, could be expanded

11. **Project cloning** — Duplicate existing projects
    - Clone with new name/domain
    - Optionally copy database

### Integration Ideas

12. **GitHub integration** — Link repos to projects
    - Show recent commits
    - Trigger deploy on push
    - PR preview environments

13. **Mailpit web access** — View emails in dashboard
    - Embed Mailpit UI in Orbit dashboard
    - Quick link per project

14. **Database UI** — Built-in database browser
    - Simple table viewer
    - Run queries
    - Export data

### Workspaces

15. **Workspace templates** — Pre-configured workspace setups
    - "Laravel + Vue SPA"
    - "Craft CMS headless"
    - Sets up multiple related projects at once

16. **Workspace-level commands** — Batch operations
    - `orbit workspace:start <name>` — Start all projects
    - `orbit workspace:stop <name>` — Stop all projects

### Performance

17. **Resource monitoring** — Per-project metrics
    - Memory usage
    - CPU usage
    - Request count

18. **Optimization suggestions** — Based on metrics
    - "Project X is memory-heavy, consider increasing PHP memory_limit"

---

## Priority Assessment

**Quick wins (low effort, high value):**
- `orbit doctor` diagnostic command
- Quick actions in web UI
- IDE integration expansion

**Medium effort, high value:**
- Real-time log streaming
- Project health dashboard
- Automatic .env generation

**Larger features (high effort):**
- GitHub integration
- Database UI
- Workspace templates

---

## Technical Debt (Discovered)

### 1. Final Class Mocking Issue

**Problem:** Several service classes are marked `final readonly` which prevents Mockery from mocking them in tests. This causes 17 test failures.

**Affected classes:**
- `CaddyfileGenerator` (main culprit - used in many tests)
- `DeletionLogger`
- `GitHubService`
- `McpClient`
- `ProvisionLogger`
- `ReverbBroadcaster`
- `WorktreeService`

**Potential fixes:**

**Option A: Extract interfaces (recommended)**
```php
// app/Contracts/CaddyfileGeneratorInterface.php
interface CaddyfileGeneratorInterface {
    public function generate(): void;
    // ...
}

// app/Services/CaddyfileGenerator.php
final readonly class CaddyfileGenerator implements CaddyfileGeneratorInterface {
    // ...
}
```
Then mock the interface instead of the class.

**Option B: Remove `final` keyword**
- Simplest but loses immutability guarantee
- Could add `@final` docblock for IDE hints instead

**Option C: Use partial mocks**
```php
$mock = Mockery::mock(CaddyfileGenerator::class)->makePartial();
```
But this requires instantiating the real object.

**Recommendation:** Go with Option A for core services used in many tests. This is cleaner and maintains the benefits of `final`.

### 2. Test Setup Permission Issues

29 tests show warnings due to `mkdir(): Permission denied`. Tests are trying to create directories in protected locations. Consider:
- Using a dedicated temp directory for test fixtures
- Mocking the filesystem operations

---

*Last updated: 2026-01-30 00:37 UTC*
