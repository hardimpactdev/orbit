# Orbit Gateway

Laravel 13 control plane for Orbit.

- Use PHP 8.5 with strict types.
- Keep controllers thin. Put behavior in final readonly actions.
- Use Form Requests and typed data objects for input.
- The Gateway admits requests, records intent and projects the terminal result. Swamp executes ([command development pipeline](../../docs/reference/command-development-pipeline.mdx)). Write no Gateway execution code.
- Use Pest 5 with `describe()` and `it()`.
- Use Pint for formatting and Larastan for static analysis.
- Do not add queues other than the `tasks`, `problems`, `builds`, `operations`, `operation-starts` and `operations-prompt` queues of the [Gateway queue](../../docs/reference/gateway-queue.md). Run a long API operation as an [operation](../../docs/reference/operations.md), not inside its request. Do not add a UI or Docker orchestration. Add no `orbit-agent` command: `apps/agent` is frozen and only observes. Existing `AgentCommandRunner` callers go away as their kind moves to Swamp ([Node agent](../../docs/reference/node-agent.md#commands)).
- Enforce binary directed node access at the HTTP boundary. One access edge permits all commands for its serving node. The active Gateway peer is implicit authority, and access to the Gateway is fleet-wide. Do not add granular permissions, presets, wildcards, or permission compatibility code.

For Tool or Tool Manager changes, read [Tools](../../docs/reference/tools.md), which owns manager policy, input, protection, adoption and removal.

## Required Guidance Bootstrap

`AGENTS.md`, `.ai/rules`, `.agents/skills`,
`.codex/config.toml`, `boost.json`, and `config/boost.php` are required repository state.
Missing, unreadable, empty, malformed, incomplete, or out-of-sync guidance is an
incomplete checkout or bootstrap. Make no product-code edits while it is.

Restore only the affected tracked guidance path from the current branch. If the
current branch does not contain it, replace the incomplete checkout. Then run
`composer install` and `composer guidance:update` to regenerate Boost-managed
guidance and skills.
Boost cannot recreate deleted project-owned rules. Never silently continue when
the required project guidance is incomplete.

## JavaScript toolchain

Use `vp` for generic project package and script work. Vite+ defaults projects without a manager signal to pnpm.
Follow Vite+'s documented selection order. Bun is used only when project state
selects it. Vite+ manages Node through `vp env`; Orbit installs pnpm by default.
Orbit installs Bun separately. It is a host runtime. Keep PHP and Composer
workflows unchanged.

===

<laravel-boost-guidelines>
=== boost rules ===

# Laravel Boost

- Before editing, open `.ai/rules/index.md`, read rules matching the paths in scope, and search `.ai/rules` for relevant terms. Required Guidance Bootstrap above owns missing-guidance recovery.
- Activate the relevant installed skill for the domain. Use MCP tools only when the active client exposes them; otherwise use repository commands and files.
- Confirm installed package versions before relying on their APIs. Use `search-docs` for version-specific Laravel APIs when available; copy-only edits need no package lookup.
- Run `composer guidance:update` after changing guidance sources or Boost configuration. Keep durable rules in their existing owner, with matching path coverage in the index.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

</laravel-boost-guidelines>
