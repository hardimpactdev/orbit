# Plan: Increase PHPStan Level from 5 to 8

**Type:** Refactor  
**Scope:** all  
**Priority:** Medium  
**Estimated Effort:** 4-6 hours

---

## Problem Statement

Current PHPStan configuration is at level 5, which catches basic errors but misses:
- Null safety issues
- Missing return type declarations  
- More precise type inference
- Better generic type checking

Level 8 or 9 would provide much stronger static analysis guarantees.

---

## Research

### Current State
- Core package: 0 errors at level 5
- App package: 0 errors at level 5
- CLI package: 0 errors at level 5

### PHPStan Levels
- Level 5: Basic type checking (current)
- Level 6: Report missing typehints
- Level 7: Report partially wrong union types
- Level 8: Report missing typehints in methods
- Level 9: Strictest - report mixed types

### Related Solutions
- `docs/solutions/phpstan-baseline/baseline-maintenance-20260130.md`

---

## Implementation Approach

### Option 1: Gradual Increase (Recommended)
Increase one level at a time, fix errors, update baseline

**Pros:**
- Manageable chunks of work
- Can stop at any level if diminishing returns
- Easier to review

**Cons:**
- Takes longer
- Multiple PRs/commits

### Option 2: Jump to Level 9
Go directly to strictest level

**Pros:**
- Maximum benefit immediately

**Cons:**
- Potentially hundreds of errors
- Large change set
- Risk of breaking changes

### Decision
Use Option 1 - start with level 6, fix all errors, then 7, then 8.

---

## Implementation Steps

### Phase 1: Level 6
1. Update `phpstan.neon.dist` in core package to level 6
2. Run `composer analyse` and identify errors
3. Fix errors or add to baseline if intentional
4. Repeat for app and cli packages
5. Commit: "chore: increase PHPStan to level 6"

### Phase 2: Level 7
1. Update to level 7
2. Fix union type issues
3. Commit: "chore: increase PHPStan to level 7"

### Phase 3: Level 8
1. Update to level 8
2. Add return type declarations to all methods
3. Commit: "chore: increase PHPStan to level 8"

---

## Test Strategy

### Unit Tests
- All existing tests must pass after each level increase
- No test changes expected (refactoring only)

### Static Analysis
- Zero PHPStan errors at target level
- Baseline regenerated if needed

---

## Verification Criteria

**Before merge, verify:**
- [ ] All packages pass at new PHPStan level
- [ ] All tests pass
- [ ] Code formatted (Pint)
- [ ] Baseline updated (if applicable)
- [ ] AGENTS.md updated with new quality standards

---

## Commands

```bash
# Core package
cd packages/core
sed -i 's/level: 5/level: 6/' phpstan.neon.dist
composer analyse

# Fix errors or generate baseline
vendor/bin/phpstan analyse --generate-baseline

# Verify tests still pass
composer test
```

---

## Related Documentation

- `docs/solutions/phpstan-baseline/baseline-maintenance-20260130.md`
- `docs/solutions/code-quality/strict-types-enforcement-20260130.md`

---

## Notes

### Expected Error Types at Level 6
- Missing parameter typehints
- Missing return typehints
- Missing property typehints

### Expected Error Types at Level 8
- Mixed type returns
- Unspecified iterable types
- Missing throws annotations

**Remember:** Document any new patterns discovered during this work in `docs/solutions/`.
