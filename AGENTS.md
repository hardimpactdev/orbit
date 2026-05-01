# Orbit

Orbit is being rebuilt as a clean Laravel 13 codebase. The old implementation is
preserved at `../orbit-old-may` and is reference material only.

## Current Shape

- Laravel 13 CLI application.
- Entry point: `php artisan`; installed as `orbit` through the local symlink on
  managed machines.
- Database: SQLite at the normal Laravel path.
- Current implemented commands:
  - `node:register`
  - `node:list`
  - `update`
  - `update:all`
- `update:all` is the first registry-backed cross-node workflow. It updates the
  local checkout first, then updates active non-local nodes from the `nodes`
  registry over SSH.

## Reference Material

Use `../orbit-old-may` for historical implementation and documentation context.
Do not copy old behavior blindly. When current code and old docs disagree, treat
the old repo as evidence, then make an explicit product decision before bringing
the behavior forward.

Before implementing Orbit-specific behavior, always consult `../orbit-old-may`
for the previous solution. Prefer porting the old approach with the smallest
clean-repo and Laravel 13 adjustments unless a new implementation is clearly
simpler, safer, or better aligned with the rebuilt architecture. If you choose a
new implementation for behavior the old repo already solved, document why before
committing to that direction.

Useful old-reference locations:

- `../orbit-old-may/docs/BLUEPRINT.md`
- `../orbit-old-may/docs/MISSION.md`
- `../orbit-old-may/docs/CONCEPTS.md`
- `../orbit-old-may/docs/BUILDING-BLOCKS.md`
- `../orbit-old-may/docs/commands/**`
- `../orbit-old-may/TESTING.md`

## Development Rules

- Prefer small, working vertical slices over porting large legacy areas.
- Do not re-solve Orbit-specific problems from scratch before checking how
  `../orbit-old-may` solved them.
- Keep the command surface contract-first. Use `.agents/skills/command-designer`
  when designing or changing command behavior.
- Current code is implementation evidence, not permanent product authority.
- Do not reintroduce broad legacy abstractions until the clean codebase has a
  concrete need for them.
- Do not use destructive git commands unless explicitly asked.

## PHP And Laravel

- Use `declare(strict_types=1)` in PHP files.
- Tests use Pest.
- Style uses Laravel Pint.
- Static analysis uses Larastan/PHPStan.
- Refactoring uses Rector.
- Follow the project-local Boost and Spatie skills in `.agents/skills/`.

## Verification

Run the narrowest useful check while developing:

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

Before handing off a code change that should be broadly safe, run:

```bash
composer analyse
composer rector
composer format
composer test
```

For standing live-node smoke verification, run:

```bash
composer test:live
```

Standing live-node smoke must stay read-only or idempotent. Destructive,
provisioning, host-mutation, or repair/adoption flows require ephemeral E2E.

See `TESTING.md` for the current node topology notes.
