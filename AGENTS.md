# Orbit

Orbit is an LLM-first, command-first PHP/Laravel monorepo for local
development, provisioning, hosting workflows, and node orchestration. Harness
guidance lives at the root; product behavior contracts live in
`apps/docs/content/`.

Route new work with [`AGENT_FAST_PATH.md`](AGENT_FAST_PATH.md); load
`HARNESS.md` sections when the chosen lane reaches them. The generated
monorepo unit map at
[`apps/docs/content/generated/monorepo-unit-map.json`](apps/docs/content/generated/monorepo-unit-map.json)
is a routing aid, not product authority. For repository searches, follow the
fast path's Search Route: default `rg` from the root or scoped to an owned
path — never `find .`, `rg -uu`, or broad hidden-file scans.

## Repository Shape

- The repository root is orchestration only: root Composer scripts, `bin/`
  helper launchers, Docker/E2E assets, AI/project configuration, and
  cross-project documentation artifacts. There is no root Laravel app,
  `artisan`, `phpunit.xml`, or Rector/Mago config.
- `apps/gateway/` is the Laravel 13 gateway/control-plane application (gateway
  HTTP/API surface, SQLite database at
  `apps/gateway/database/database.sqlite`, provisioning, E2E harness support,
  frontend build, quality tooling).
- `apps/docs/` is the Laravel 13 docs and Librarian application; it owns
  `apps/docs/content/` and docs-linting.
- `apps/cli/` is the Laravel Zero CLI and executor application.
- `apps/e2e/` is the external E2E harness; its `composer test:e2e*` lanes are
  manual-only.
- `apps/reverb/` is the Laravel Reverb runtime packaged as
  `hardimpact/orbit-reverb` for websocket role nodes.
- `packages/core/` holds shared contracts, helpers, and cross-application
  primitives; `packages/sdk/` is the Laravel SDK for the gateway API.
- Each app/package owns its own composer/test/Mago/Rector config; root
  Composer commands only orchestrate them.
- The gateway entry point is `php apps/gateway/artisan` from the root (or
  `bin/orbit-gateway-artisan` for maintenance); the host `orbit` launcher
  always executes `apps/cli/orbit`.

## Product Authority

Orbit's product contract lives under `apps/docs/content/`: `architecture.md`,
`mission.md`, `concepts.md`, `tech-stack.md`, and `domains/**`.
`PRODUCT_DECISIONS.md` is the chronological intent ledger above that chain: it
records dated direction-change decisions, and when docs conflict the latest
dated decision states current intent. Session artifacts (plans, specs) live in
the operator's shared-knowledge project folder; legacy copies remain under
`docs/superpowers/`. They are not product authority and are not linted as
product docs.

## Development Rules

- Feature-request handling is intake only: clarify outcome, surface,
  acceptance, constraints, authority, and unresolved product ambiguity without
  updating repository files.
- Actual implementation happens through `.agents/skills/implementing-features`
  in an isolated worktree — including docs updates, ledger entries, tests, and
  code.
- Use `bin/orbit-prepare-worktree` to create, bootstrap, and verify
  implementation worktrees. It takes priority over generic worktree skills and
  ad hoc `git worktree add`; do not recreate its setup flow manually. If it
  cannot be used, stop and report the blocker instead of silently falling
  back.
- When a feature is implemented and verified, follow `HARNESS.md` for review,
  diff-derived proof, acceptance identity, merge, archive, and cleanup. Agents
  run all deterministic checks; never hand the user a mechanical command
  checklist. Leave `~/orbit` on updated `main`, preserve unrelated dirty
  files, and never discard user changes to make a merge easier.
- Always make sure `apps/docs/content/` describes the correct behavior; flag
  gaps or contradictions before proceeding. `HARNESS.md` BUILD owns
  docs-tests-code alignment.
- When an issue is reported against live nodes, verify the fix on those nodes.
- Prefer small, working vertical slices; keep the command surface
  contract-first via `.agents/skills/command-designer`.

## PHP And Laravel

- Use `declare(strict_types=1)` in PHP files.
- Tests use Pest (Pest 5 / PHPUnit 13 everywhere; see
  `apps/docs/content/testing/README.md#pest-versions`).
- Style, linting, and static analysis use Mago; refactoring uses Rector.
- Follow the app-local Boost and Spatie skills in `.agents/skills/`.

## Verification

Read `apps/docs/content/testing/README.md` before adding, changing, or
debugging tests; it is the authoritative lane map. Run the narrowest useful
check while developing (for example `bin/orbit-gateway-pest --compact`), and
`composer quality-check` (docs linting, Pest, Mago, Rector, and Cargo checks
across every app/package) before handing off a broadly safe change. Behavior
touching the integrated topology requires retained topology proof recorded in
`.orbit/loop.md` or `.orbit/evidence/`. The
`composer test:e2e*` lanes are human-only; agents never trigger them — the
canonical rule, including the explicit user-invocation boundary, is in
`HARNESS.md`.

## AI Guideline Precedence

Orbit-specific instructions in this file override the generated Laravel Boost
and Spatie guidelines below when they conflict. Use Boost and Spatie as the
PHP/Laravel baseline, not as permission to override Orbit's command contracts
or local conventions.

## Laravel Boost In This Monorepo

- Laravel Boost is installed only in `apps/gateway/`; do not add a root
  Laravel app or root Boost install. Root agent MCP configs are authoritative
  and start Boost through `php apps/gateway/artisan boost:mcp` from the root.
- Keep Boost maintenance on `boost:update` via `bin/orbit-boost-update`;
  never automate `boost:install --silent`.
- Gateway Boost tools are gateway-scoped; use package/root skills elsewhere.

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
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- pestphp/pest (PEST) - v5
- phpunit/phpunit (PHPUNIT) - v13
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

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== hardimpactdev/librarian rules ===

# Librarian

Librarian gives Laravel apps a strict documentation structure for keeping
product intent, code, and tests aligned.

## Documentation Spine

Apps using Librarian should maintain this docs structure:

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
- Remove scaffold prompt text once the app has real content.

=== spatie/guidelines-skills rules ===

# Project Coding Guidelines

- This codebase follows Spatie's coding guidelines.
- Always activate the `spatie-laravel-php` skill when writing, editing, reviewing, or formatting Laravel or PHP code.
- Always activate the `spatie-javascript` skill when writing, editing, reviewing, or formatting JavaScript or TypeScript code.
- Always activate the `spatie-version-control` skill when creating commits, branches, or managing Git operations.
- Always activate the `spatie-security` skill when configuring security, reviewing authentication, or setting up servers and databases.

</laravel-boost-guidelines>
