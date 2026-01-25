# Review: Split orbit-core into orbit-core + orbit-ui

Reviewed: 2026-01-23
PRD: /home/nckrtl/projects/orbit-core/docs/plans/package-split/plan.md
Reviewer: plan-reviewer

## Summary

**Needs Work** - The plan is well-structured but has several critical gaps that could cause failures during implementation.

## Strengths

- Clear namespace strategy with `HardImpact\Orbit\Core\` and `HardImpact\Orbit\Ui\`
- Proper phase sequencing with dependencies clearly mapped
- Good verification steps for each phase
- Addresses the core PHAR build issue by removing laravel/framework dependency

## Concerns

### High Priority

1. **Missing Factory Namespace Updates**
   - Problem: Database factories in `database/factories/` use `HardImpact\Orbit\Database\Factories\` namespace
   - Impact: Autoloading will break after namespace change, causing test failures and factory resolution errors
   - Suggestion: Add factory namespace updates to Phase 2 tasks and update composer.json autoload accordingly

2. **Incomplete File Inventory**
   - Problem: Plan lists removing `tailwind.config.js` and `postcss.config.js` but these files don't exist in current codebase
   - Impact: Could cause confusion during execution
   - Suggestion: Verify actual files exist before listing them for removal

3. **Missing Model Directory in orbit-ui**
   - Problem: Plan doesn't address that Controllers in orbit-ui will need to import Models from orbit-core
   - Impact: All controller imports will break without proper Core namespace references
   - Suggestion: Add explicit verification that all Controller files import Models from `HardImpact\Orbit\Core\Models\`

4. **PHPStan Baseline Will Break**
   - Problem: `phpstan-baseline.neon` contains 100+ hardcoded namespace references to `HardImpact\\Orbit\\Models\\`
   - Impact: Static analysis will fail after namespace change
   - Suggestion: Add task to update phpstan-baseline.neon or regenerate it in Phase 2

5. **Composer Lock File References**
   - Problem: `composer.lock` contains hardcoded service provider references that may cause issues
   - Impact: Could cause autoloading conflicts during transition
   - Suggestion: Add step to delete composer.lock and regenerate after namespace changes

### Medium Priority

6. **Missing Route File Updates**
   - Problem: Route files likely contain controller references that need namespace updates
   - Impact: Routes will fail to resolve after split
   - Suggestion: Add explicit verification of route file controller references in Phase 3

7. **Asset Publishing Path Conflicts**
   - Problem: Both packages will try to publish to `public/vendor/orbit/build`
   - Impact: orbit-ui assets could overwrite orbit-core assets or vice versa
   - Suggestion: Use different asset paths: `public/vendor/orbit-core/` and `public/vendor/orbit-ui/`

8. **MCP Route Registration**
   - Problem: MCP routes are currently registered in OrbitServiceProvider but plan doesn't specify which package should handle them
   - Impact: MCP functionality could break if routes end up in wrong package
   - Suggestion: Clarify that MCP routes should stay in orbit-core since they're business logic, not UI

### Low Priority

9. **Documentation References**
   - Note: Several documentation files in `docs/solutions/` reference the old namespace
   - Suggestion: Update these for consistency but not critical for functionality

## Missing Edge Cases

- [ ] **Service Provider Discovery**: What happens if both packages are installed but only one service provider is registered?
- [ ] **Migration Conflicts**: If both packages contain migrations, will there be table conflicts?
- [ ] **Event/Job Namespace**: Events and Jobs in orbit-core may be referenced by orbit-ui - verify all cross-references
- [ ] **Config Publishing**: Both packages may try to publish config files - ensure no conflicts
- [ ] **Artisan Command Conflicts**: If both packages register commands, ensure no name conflicts

## Questions for DoD Interview

These questions should be asked during the Definition of Done phase:

1. Should MCP routes and tools stay in orbit-core (business logic) or move to orbit-ui (web interface)?
2. What should happen to shared Events/Jobs that both packages might need?
3. Should we use different asset publishing paths to avoid conflicts between packages?
4. Do you want to maintain backward compatibility for any existing consumers beyond orbit-web/orbit-cli?

## Verification Suggestions

For each phase, consider verifying:

### Phase 1
- [ ] All Controller imports reference `HardImpact\Orbit\Core\Models\` not `HardImpact\Orbit\Models\`
- [ ] Route files use correct controller namespaces
- [ ] No references to removed directories (Models/, Services/, etc.)

### Phase 2
- [ ] Factory namespaces updated to `HardImpact\Orbit\Core\Database\Factories\`
- [ ] PHPStan baseline regenerated or updated
- [ ] Composer.lock deleted and regenerated
- [ ] All test files updated with new namespaces

### Phase 3
- [ ] Asset publishing uses unique paths
- [ ] MCP routes work correctly (if kept in orbit-ui)
- [ ] No circular dependencies between packages

### Phase 4
- [ ] PHAR contains no laravel/framework dependencies
- [ ] All orbit-cli imports updated including in AGENTS.md files
- [ ] Test suite passes with new namespaces

### Phase 5
- [ ] Both packages can be installed together without conflicts
- [ ] Asset publishing works for both packages
- [ ] No duplicate service provider registrations

### Phase 6
- [ ] Full integration test: create project via CLI, view in web UI
- [ ] Upgrade path works for existing installations
- [ ] Documentation reflects new package structure

## Additional Files Requiring Updates

The plan missed several files that need modification:

### orbit-core
- `database/factories/*.php` - Update namespaces to Core
- `phpstan-baseline.neon` - Update all namespace references
- `composer.lock` - Delete and regenerate

### orbit-cli  
- `app/Data/AGENTS.md` - Contains hardcoded namespace references
- `app/Actions/AGENTS.md` - Contains hardcoded namespace references
- All test files - Update namespace imports

## Recommendation

**Address concerns first** - The plan needs refinement before proceeding to DoD interview. Key issues:

1. Add factory namespace updates to Phase 2
2. Verify and update file inventory
3. Address PHPStan baseline updates
4. Clarify MCP route ownership
5. Plan for asset publishing conflicts

Once these concerns are addressed, the plan should be ready for DoD interview and implementation.

To proceed: Address high-priority concerns, then @dod-interviewer