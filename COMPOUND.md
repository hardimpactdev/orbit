# Compound Engineering - Orbit Monorepo

**Methodology:** [Every.to's Compound Engineering](https://every.to/chain-of-thought/compound-engineering-how-every-codes-with-agents)  
**Principle:** Each unit of work makes subsequent work easier, not harder.

---

## The Loop

```
Plan → Work → Review → Compound → (repeat)
```

**80% planning and review. 20% is execution.**

---

## Directory Structure

```
orbit-dev/
├── docs/
│   └── solutions/           # Compounded knowledge (auto-generated)
│       ├── namespace-issues/
│       ├── service-locator/
│       ├── phpstan-baseline/
│       ├── model-phpdoc/
│       ├── code-quality/
│       └── patterns/
├── packages/
│   ├── core/                # Business logic
│   ├── app/                 # UI components
│   ├── cli/                 # CLI tool
│   ├── web/                 # Web shell
│   └── desktop/             # Desktop shell
└── AGENTS.md                # Project conventions (main)
```

---

## Workflow

### 1. Plan (`/workflows-plan`)

Before starting work, capture a short plan in the issue/task notes:

**Plan must include:**
- Problem statement
- Research findings
- Implementation approach
- Test strategy
- Verification criteria

### 2. Work (`/workflows-work`)

Execute with continuous verification:

```bash
# After each meaningful change:
cd packages/core && composer analyse && composer test
cd packages/app && composer analyse && composer test  
cd packages/cli && composer analyse && composer test
```

**Tracking options:**
- **Simple:** Use `TodoWrite` for single-session work
- **Complex:** Use Beads (`bd`) for multi-session work

### 3. Review (`/workflows-review`)

Before committing:

```bash
# All packages must pass:
composer analyse      # PHPStan - zero errors
composer test         # All tests passing
composer format       # Code style
```

### 4. Compound (`/workflows-compound`)

**Auto-trigger on:** "that worked", "it's fixed", "problem solved"

Document in `docs/solutions/[category]/`:

```yaml
---
date: 2026-01-30
problem_type: [namespace-error|service-locator|phpstan]
component: [core|app|cli|web|desktop]
severity: [low|moderate|high|critical]
symptoms:
  - "Error message or symptom"
root_cause: "Why it happened"
tags: [tag1, tag2]
---

# Problem
Description of the issue

# Solution
How it was fixed

# Prevention
How to avoid in future
```

---

## Package-Specific Patterns

### Core Package (Business Logic)

```php
<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Data\StepResult;

/**
 * @property int $id
 * @property string $name
 */
final readonly class ProvisionAction
{
    public function __construct(
        private Dependency $dependency,
    ) {}
    
    public function handle(ProvisionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        // Implementation
    }
}
```

**Rules:**
- Always `declare(strict_types=1);`
- Use `HardImpact\Orbit\Core\*` namespace
- Constructor injection only (no `app()`)
- Models must have @property annotations
- Classes should be `final readonly` where possible

### App Package (UI)

```php
<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Ui\Http\Controllers;

use HardImpact\Orbit\Core\Models\Project;
use HardImpact\Orbit\Core\Services\ProjectService;
use Illuminate\Http\Request;

final class ProjectController extends Controller
{
    public function __construct(
        private ProjectService $projectService,
    ) {}
    
    public function index(): Response
    {
        $projects = $this->projectService->all();
        return Inertia::render('Projects/Index', [
            'projects' => $projects,
        ]);
    }
}
```

**Rules:**
- Controllers are thin (orchestrate services)
- Use core models/services via DI
- No business logic in controllers

### CLI Package (Laravel Zero)

```php
<?php
declare(strict_types=1);

namespace App\Commands;

use App\Services\PlatformService;
use LaravelZero\Framework\Commands\Command;

final class StatusCommand extends Command
{
    public function __construct(
        private PlatformService $platformService,
    ) {
        parent::__construct();
    }
    
    public function handle(): int
    {
        $status = $this->platformService->check();
        
        if ($this->option('json')) {
            $this->line(json_encode($status));
            return self::SUCCESS;
        }
        
        // Table output
        return self::SUCCESS;
    }
}
```

**Rules:**
- Commands handle I/O only
- Delegate to services for logic
- Support `--json` flag for automation

---

## Quality Gates

### Pre-Commit Checklist

```bash
# Run in each package:
cd packages/core && composer analyse && composer test && composer format
cd packages/app && composer analyse && composer test && composer format
cd packages/cli && composer analyse && composer test && composer format
```

### Requirements

| Check | Core | App | CLI |
|-------|------|-----|-----|
| PHPStan | Level 5, zero errors | Level 5, zero errors | Level 5, zero errors |
| Tests | All passing | All passing | All passing |
| Format | Pint clean | Pint clean | Pint clean |
| Strict Types | 100% | 100% | 100% |

---

## Knowledge Index

### Solutions by Category

**Namespace Issues:**
- `docs/solutions/namespace-issues/missing-core-segment-20260130.md`

**Service Locator (DI):**
- `docs/solutions/service-locator/app-helper-anti-pattern-20260130.md`

**Static Analysis:**
- `docs/solutions/phpstan-baseline/baseline-maintenance-20260130.md`

**Model Documentation:**
- `docs/solutions/model-phpdoc/eloquent-property-annotations-20260130.md`

**Code Quality:**
- `docs/solutions/code-quality/strict-types-enforcement-20260130.md`

**Patterns:**
- `docs/solutions/patterns/service-provider-naming-convention-20260130.md`

### Session Summaries

- `docs/solutions/session-summary-20260130.md`
- `docs/solutions/monorepo-quality-cleanup-20260130.md`

---

## Future Compound Opportunities

When encountering these, document them:

1. **Return Type Declarations** - Add PHP 8.4 return types to all methods
2. **Increase PHPStan Level** - Move from level 5 to 8 or 9
3. **Test Coverage** - Add more Action tests for Provision/Deletion
4. **CI/CD Pipeline** - GitHub Actions for automated testing
5. **Code Coverage Tracking** - Add coverage reports

---

**Remember:** Every solved problem becomes a pattern that makes the next problem easier.
