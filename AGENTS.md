# Orbit

Orbit is a command-first PHP/Laravel monorepo for local development,
provisioning, hosting workflows, and node orchestration.

Orbit is an LLM-first monorepo. Repo development harness guidance lives at the
root; see [`HARNESS.md`](HARNESS.md) for scope, agent discovery path, and how
the harness differs from the feedback loop. Product behavior contracts remain in
`apps/docs/content/`.

## Repository Shape

- The repository root is orchestration only: root Composer scripts, `bin/`
  helper launchers, Docker/E2E assets, AI/project configuration, and
  cross-project documentation artifacts. There is no root Laravel app, root
  `artisan`, root `phpunit.xml`, root Pint config, root PHPStan config, or root
  Rector config.
- `apps/gateway/` is the Laravel 13 gateway/control-plane application. It owns
  the gateway HTTP/API surface, gateway database, provisioning logic, E2E
  harness, deployed public/storage assets, frontend build, and gateway-local
  quality tooling.
- `apps/docs/` is the Laravel 13 documentation and Librarian application. It
  owns the product documentation under `apps/docs/content/` and docs-linting.
- `apps/cli/` is the Laravel Zero local CLI and executor application.
- `apps/reverb/` is the dedicated Laravel Reverb runtime application packaged
  into the `hardimpact/orbit-reverb` image for websocket role nodes.
- `packages/core/` is the shared Orbit package for contracts, helpers, and
  cross-application primitives.
- Each app/package owns its own `composer.json`, test config, Pint config,
  PHPStan config, and Rector config. Root Composer commands only orchestrate
  those app/package-local commands.
- The gateway entry point is `php apps/gateway/artisan` from the repository
  root, or `php artisan` from `apps/gateway/`. The host `orbit` launcher
  always executes `apps/cli/orbit`. Gateway maintenance uses
  `bin/orbit-gateway-artisan` or direct `php apps/gateway/artisan` in
  controlled gateway contexts only.
- The gateway database is SQLite at
  `apps/gateway/database/database.sqlite`.

## Product Authority

Orbit's current product contract lives in this repo under `apps/docs/content/`:

- `apps/docs/content/architecture.md`
- `apps/docs/content/mission.md`
- `apps/docs/content/concepts.md`
- `apps/docs/content/tech-stack.md`
- `apps/docs/content/domains/**`

`PRODUCT_DECISIONS.md` is the chronological intent ledger. It does not restate
contracts; it records each direction-change decision with a date. When docs
conflict, the latest dated decision on a topic states current intent and
indicates which side is stale. Treat it as the intent anchor above the product
docs authority chain.

Session artifacts (plans, specs) stay at `docs/superpowers/`. They are not
product authority and are not linted as product docs.

## Development and debugging Rules

- Feature-request handling is intake only: use it to clarify intent. When a
  request is too large for one implementation slice, capture a lightweight Solo
  scratchpad roadmap with rough slice order and update it at slice boundaries.
  Create Solo todos only when a slice needs asynchronous assignment, queueing,
  or explicit tracking outside the active orchestrator thread. Do not update
  repository files while handling the request.
- Actual implementation happens through `.agents/skills/implementing-features`
  in an isolated worktree. That includes documentation updates, product-decision
  ledger entries, tests, and code changes. Read that skill before starting
  implementation work.
- Use `bin/orbit-prepare-worktree` to create, bootstrap, and verify
  implementation worktrees. This is Orbit's worktree setup path and takes
  priority over generic worktree skills or ad hoc `git worktree add`. Agents
  must not recreate that setup flow manually. If the script cannot be used,
  stop and report the blocker instead of silently falling back.
- When a feature is implemented and verified, commit the worktree branch, merge
  it back into `main` from the primary `~/orbit` checkout, remove the completed
  worktree/branch, and leave `~/orbit` on updated `main`. Preserve unrelated
  dirty files in `~/orbit`; never discard user changes to make a merge easier.
- Always make sure that `apps/docs/content/` describes the correct behavior. If
  the docs are lacking or contradict what is requested, flag that first before
  proceeding.
- When documentation is aligned, check whether a corresponding test exists. If
  not, create or adjust a failing test that mirrors the correct behavior before
  changing implementation.
- From the failing test, work on the implementation/fix. Always keep docs,
  tests, and code aligned.
- When an issue is reported about orbit running against live nodes. Make sure to verify the fix against those running nodes.
- Prefer small, working vertical slices over porting large legacy areas.
- Keep the command surface contract-first. Use `.agents/skills/command-designer`
  when designing or changing command behavior.

## PHP And Laravel

- Use `declare(strict_types=1)` in PHP files.
- Tests use Pest.
- Style uses Laravel Pint.
- Static analysis uses Larastan/PHPStan.
- Refactoring uses Rector.
- Follow the project-local Boost and Spatie skills in `.agents/skills/`.

## Verification

Before adding, changing, debugging, or running E2E tests, read
`apps/docs/content/testing/README.md`.
It is the authoritative lane map for prepared-topology feature tests,
provisioning tests, host pools, cache strategy, and performance baselines.

Run the narrowest useful check while developing:

```bash
bin/orbit-gateway-pest --compact
bin/orbit-gateway-vendor-bin pint --dirty --format agent
```

Before handing off a code change that should be broadly safe, run:

```bash
composer quality-check
```

`composer quality-check` fans out docs linting, Pest, Pint, PHPStan, and Rector
across every app/package.

When behavior touches the integrated topology, run the ephemeral E2E lane:

```bash
composer test:e2e
```

There is no standing live-node test lane. Provisioning, host-mutation, and
repair/adoption flows belong in `composer test:e2e:provision`. See
`apps/docs/content/testing/README.md` for the full verification model and lane map.

## AI Guideline Precedence

Orbit-specific instructions in this file override the generated Laravel Boost
and Spatie guidelines below when they conflict. Use Boost and Spatie as the
PHP/Laravel baseline, not as permission to override Orbit's command contracts,
clean-rebuild constraints, or local conventions.

## Laravel Boost In This Monorepo

- Laravel Boost is installed only in `apps/gateway/`. Do not add a root Laravel
  app or root Boost install.
- Root agent MCP configs are authoritative. They start Boost through
  `php apps/gateway/artisan boost:mcp` from the repository root.
- Keep Boost maintenance on `boost:update`, via `bin/orbit-boost-update` or the
  gateway `post-update-cmd`. Do not automate `boost:install --silent`; explicit
  `boost:install` is setup/reconfiguration and can rewrite agent artifacts.
- Gateway Boost tools are gateway-scoped. Use package/root skills for
  `apps/cli/`, `packages/core/`, `packages/sdk/`, and docs/Librarian work.
- From the repo root, run gateway Artisan through `bin/orbit-gateway-artisan`
  or direct `php apps/gateway/artisan`. Do not assume a root `artisan` exists.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- rector/rector (RECTOR) - v2

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== hardimpactdev/librarian rules ===

# Librarian

Librarian gives Laravel projects a strict documentation structure for keeping
product intent, code, and tests aligned.

## Documentation Spine

Projects using Librarian should maintain this docs structure:

```text
docs/
  README.md
  mission.md
  architecture.md
  tech-stack.md
  concepts.md
  domains/
```

`docs/README.md` and `docs/concepts.md` are generated by Librarian and should
not be hand-edited. Put project-specific prose in `mission.md`,
`architecture.md`, `tech-stack.md`, and domain files below `docs/domains`.

## Commands

Use Librarian's Artisan commands instead of manually creating or reshuffling the
docs spine:

```bash
php artisan librarian:init
php artisan librarian:domain billing
php artisan librarian:domains:normalize
php artisan librarian:build
php artisan librarian:lint
```

Use `librarian:build` after changing docs that affect generated indexes. Use
`librarian:lint` as the read-only consistency check in CI.

## Writing Librarian Docs

- Write concrete product intent, not generic template filler.
- Keep mission, architecture, tech stack, and domain docs aligned with code and
  tests.
- Use lowercase kebab-case domain slugs.
- Prefer local markdown links that resolve inside the docs tree.
- Remove scaffold prompt text once the project has real content.

=== spatie/guidelines-skills rules ===

# Project Coding Guidelines

- This codebase follows Spatie's coding guidelines.
- Always activate the `spatie-laravel-php` skill when writing, editing, reviewing, or formatting Laravel or PHP code.
- Always activate the `spatie-javascript` skill when writing, editing, reviewing, or formatting JavaScript or TypeScript code.
- Always activate the `spatie-version-control` skill when creating commits, branches, or managing Git operations.
- Always activate the `spatie-security` skill when configuring security, reviewing authentication, or setting up servers and databases.

</laravel-boost-guidelines>
