---
date: 2026-01-30
problem_type: compound-engineering
component: monorepo
severity: documentation
symptoms:
  - "Inconsistent development workflow across packages"
  - "No standardized way to track progress"
  - "Knowledge loss between sessions"
  - "No place to document solved problems"
root_cause: Missing compound engineering methodology and infrastructure
tags: [compound-engineering, methodology, documentation, workflow]
---

# Compound Engineering: Methodology Implementation

## Problem

The monorepo lacked a systematic approach to development:

1. **Inconsistent workflows** - Each package had different conventions
2. **Knowledge loss** - Solved problems weren't documented for reuse
3. **No planning structure** - Features started without clear plans
4. **Scattered documentation** - Solutions buried in chat logs or forgotten

## Solution

Implemented **Compound Engineering** methodology from Every.to:

### 1. Standardized Planning Guidance

**Location:** Issue/task notes and `COMPOUND.md`

**Plan includes:**
- Problem statement
- Research checklist
- Implementation options
- Test strategy
- Verification criteria

### 2. Created Solution Documentation

**Directory:** `docs/solutions/`

```
docs/solutions/
├── namespace-issues/
├── service-locator/
├── phpstan-baseline/
├── model-phpdoc/
├── code-quality/
└── patterns/
```

**Solution format:**
```yaml
---
date: 2026-01-30
problem_type: specific-error
component: affected-package
severity: level
symptoms:
  - "Error message"
root_cause: "Why it happened"
tags: [tag1, tag2]
---

# Problem
Description

# Solution  
How fixed

# Prevention
How avoid
```

### 3. Defined Workflow

**The Loop:**
```
Plan → Work → Review → Compound → (repeat)
```

**Plan:**
- Capture plan in issue/task notes
- Include research, approach, verification

**Work:**
- Execute with continuous testing
- Use `TodoWrite` or Beads for tracking

**Review:**
- PHPStan zero errors
- All tests passing
- Code formatted

**Compound:**
- Document solutions in `docs/solutions/`
- Update AGENTS.md with new patterns
- Push to remote

### 4. Created COMPOUND.md

**File:** `COMPOUND.md`

Comprehensive guide including:
- Methodology overview
- Directory structure
- Workflow commands
- Package-specific patterns
- Quality gates
- Knowledge index

### 5. Updated AGENTS.md

Added Compound Engineering section at top:
```markdown
## Development Methodology

This project follows **Compound Engineering** - each unit of work 
makes subsequent work easier. See [COMPOUND.md](COMPOUND.md).

**Quick workflow:**
Plan → Work → Review → Compound → (repeat)
```

## Prevention

### When Starting New Work

1. **Create a plan in issue/task notes** (problem, approach, verification)

2. **Document solutions:**
   ```bash
   # After solving a problem:
   # Create docs/solutions/[category]/[descriptive-name].md
   ```

3. **Follow quality gates:**
   ```bash
   cd packages/core && composer analyse && composer test
   cd packages/app && composer analyse && composer test
   cd packages/cli && composer analyse && composer test
   ```

### Code Review Checklist

- [ ] Plan captured in issue/task notes (for significant work)
- [ ] Solution documented in `docs/solutions/` (if applicable)
- [ ] All PHPStan checks pass
- [ ] All tests pass
- [ ] Code formatted with Pint
- [ ] Commits pushed to remote

## Benefits

1. **Knowledge compounds** - Each solution makes next similar problem easier
2. **Consistent quality** - Same standards across all packages
3. **Traceability** - Plans show decision rationale
4. **Onboarding** - New developers learn from documented patterns
5. **Reduced rework** - Prevention section stops repeated mistakes

## Monorepo Peer Review Checklist (2026-01-31)

Added from comprehensive quality review session:

### Parallel Agent Analysis

| Agent | Focus |
|-------|-------|
| `vue-inertia-reviewer` | Composition API, TypeScript, reactivity |
| `security-sentinel` | Injection, auth, secrets, OWASP |
| `code-simplicity-reviewer` | Over-engineering, duplication |
| `Explore` (consistency) | Namespaces, configs, dependencies |
| `Explore` (Laravel) | Service providers, models, routes |
| `performance-oracle` | N+1, indexes, caching, singletons |

### Package Consistency Checklist

- [ ] PHP version constraint matches (`^8.4`)
- [ ] Pint version matches (`^1.25`)
- [ ] Pest version matches (`^4.0`)
- [ ] PHPStan includes Larastan extension
- [ ] Singleton bindings for stateless services
- [ ] No path repositories in composer.json (breaks CI)

### Common Issues to Check

| Issue | Pattern | Fix |
|-------|---------|-----|
| Command injection | `shell_exec("{$var}")` | `escapeshellarg()` |
| Missing FK index | `foreignId()` only | Add explicit `index()` |
| Accessor I/O | `getXAttribute()` with file_exists | Change to method |
| Dead facades | Empty class + facade | Delete both |
| `any` types | `Record<string, any>` | Use proper type |

## Related

- `docs/solutions/performance-issues/filesystem-io-in-eloquent-accessor-20260131.md`
- `docs/solutions/security-issues/command-injection-shell-exec-20260131.md`
- `docs/solutions/monorepo-quality-cleanup-20260130.md`
- `COMPOUND.md`
- `AGENTS.md`
