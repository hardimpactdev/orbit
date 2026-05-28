# Docs Audit Rollup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land all 11 docs-drift audit findings (A1–A8 + B1–B3) as one atomic delta on the `audit/docs-drift` branch.

**Architecture:** Finish the role-assignment-model migration that landed yesterday (commit `025b58e58`). Drop the residual legacy primary-role columns (`nodes.role`, `nodes.environment`), retire the legacy role values (`app-development`/`app-production`/`control`/`app`), introduce a template-based `node:new` UX, and align every doc + JSON renderer + companion-file slot with the post-migration model. The audit branch already contains the audit findings (Solo scratchpads 338/339/340); this plan executes the agreed fixes.

**Tech Stack:** Laravel 13 / PHP 8.5, Pest 4, SQLite (gateway database), `bin/orbit-gateway-pest`, `composer docs-lint`, `composer quality-check`.

**Inputs:**
- `docs-audit-findings-claude` (Solo scratchpad 338) — original 7 findings.
- `docs-audit-review-codex` (Solo scratchpad 339) — Codex's independent review, 5 new findings.
- `docs-audit-final` (Solo scratchpad 340) — synthesized 11 findings with recommended fixes.
- User walkthrough decisions (this conversation): A1–A8, B1–B3 all approved with directions captured below.

**Approved Decisions (lock these before executing):**
- **A1:** Drop `nodes.role` + `nodes.environment` columns. Remove `--role=operator`; introduce `--operator` flag for operator-identity client setup. Rip out `NodeNewCommand.php:288` translation.
- **A2:** Canonical role names are `app-dev` and `app-prod`. Retire `app-development` and `app-production` entirely — they are not "long form" or "canonical"; they no longer exist as accepted values. `NodeRoleName` enum changes accordingly.
- **A3:** Introduce template-based `node:new`. Templates: `operator`, `app-development`, `app-production`, `gateway`, `ingress`, `database`, `s3`, `websocket`, `agent`, `custom`. Companion code expands templates into role sets. S3 + WebSocket templates documented but marked `implementation pending` per Solo ORBIT-S3-* and ORBIT-WEBSOCKET-* todos. Note: template *names* keep the long `app-development`/`app-production` form because they describe operator intent, even though the underlying role values are `app-dev`/`app-prod`.
- **A4:** `node:list` public and technical `--role=` enums aligned to the 10 canonical roles. Drop `--environment` filter from technical signature. No `--template` filter.
- **A5:** Remove `--environment` from `node:update` entirely. Switching app environment goes through `node role:remove app-dev` + `node role:add app-prod` (or vice versa).
- **A6:** Walk every JSON renderer in `apps/docs/content/domains/1_node/`. Replace legacy `"role": "app"` examples with `"role": "app-dev"` or `"role": "app-prod"`. Drop `operator`, `app-development`, `app-production` from accepted enums and error message lists. Final accepted set: `gateway, vpn, router, app-dev, app-prod, database, agent, ingress, websocket, s3, null`.
- **A7:** Canonical `--host` rule: every workload-role-bearing path requires `--host`. Update canonical input table at `1_node-new.md:35` to enumerate all host-requiring paths.
- **A8:** Rewrite caller-role wording at `node-new.md:24-27` and `workspace-teardown-step-remove/.../5.2_*_non-interactive.md:36-44` to grants-only language.
- **B1:** Rename 6 on-disk test files `*OnControlNode*ContractTest.php` → `*OnOperatorNodeContractTest.php`. Rename all `_on-client.md` → `_on-operator-node.md`. Rename all `_on-app-role.md` → `_on-workload-node.md` (none currently exist; slot map only). Update slot map in `apps/docs/content/domains/README.md:240-256`. Update all 21+ cross-references.
- **B2:** Sweep remaining `--environment` references in `node:new` signature/prompts/JSON examples.
- **B3:** Replace "Control-peer" wording at `apps/docs/content/domains/11_operation/3_doctor/technical/2_doctor_on-client.md:103` with "Operator-node forwarding."

**Worktree:** `/Users/nckrtl/orbit/.worktrees/audit-docs-drift` on branch `audit/docs-drift`. All steps run from this directory unless otherwise noted.

**Commit Style:** Conventional commits. One commit per phase or smaller logical unit. Phase boundary commit messages tagged with `audit-rollup:` prefix.

---

## File Structure

This plan touches files in these areas. Each phase is responsible for keeping its area consistent with the post-migration model.

| Area | Phase responsibility |
|---|---|
| `apps/gateway/database/migrations/` | Phase 1 (schema migration drops + role value rename) |
| `apps/gateway/app/Console/Commands/NodeNewCommand.php` | Phases 5 (CLI flag), 6 (template model) |
| `apps/gateway/app/Console/Commands/NodeUpdateCommand.php` | Phase 7 (drop --environment) |
| `apps/gateway/app/Console/Commands/NodeListCommand.php` | Phase 8 (signature cleanup) |
| `apps/gateway/app/Enums/NodeRoleName.php` (or equivalent) | Phase 2 (enum rename) |
| `apps/gateway/app/Models/Node.php`, `NodeRole.php` | Phase 2 |
| `apps/gateway/database/factories/` | Phase 2 |
| `apps/gateway/tests/Feature/Commands/Nodes/*OnControlNode*ContractTest.php` (6 files) | Phase 12 (rename) |
| `apps/gateway/tests/**` | Phases 2, 5, 6, 7, 8 (update fixtures + assertions) |
| `apps/docs/content/architecture.md`, `concepts.md`, `tech-stack.md` | Phase 3 (authority docs) |
| `apps/docs/content/domains/1_node/node-concepts.md` | Phase 3 |
| `apps/docs/content/domains/README.md` (slot map at lines 240-256) | Phase 12 |
| `apps/docs/content/domains/1_node/1_node-new/**` | Phases 5, 6, 9 (CLI flag, templates, JSON renderer) |
| `apps/docs/content/domains/1_node/7_node-update/**` | Phase 7 |
| `apps/docs/content/domains/1_node/3_node-list/**` | Phase 8 |
| `apps/docs/content/domains/1_node/4_node-show/**`, `9_node-default/**` | Phase 9 (JSON renderer) |
| `apps/docs/content/domains/**/2_*_on-client.md` (12 files) | Phase 12 (rename to `_on-operator-node.md`) |
| `apps/docs/content/domains/**` (sweep for `app-development`/`app-production`) | Phase 4 |
| `apps/docs/content/domains/6_workspace/13_workspace-teardown-step-remove/technical/5.2_*_non-interactive.md` | Phase 11 |
| `apps/docs/content/domains/11_operation/3_doctor/technical/2_doctor_on-client.md` | Phase 14 |

---

## Phase 0: Pre-flight + Branch Hygiene

**Files:**
- Verify: `git status` in `/Users/nckrtl/orbit/.worktrees/audit-docs-drift`

- [ ] **Step 1: Confirm worktree state**

Run:
```bash
cd /Users/nckrtl/orbit/.worktrees/audit-docs-drift
git status
git branch --show-current
```

Expected: branch is `audit/docs-drift`, working tree may contain this plan file but otherwise clean (or only this plan staged).

- [ ] **Step 2: Run baseline tests + docs-lint to capture pre-state**

Run:
```bash
composer docs-lint 2>&1 | tail -20
bin/orbit-gateway-pest --compact 2>&1 | tail -10
```

Expected: capture the baseline pass/fail counts. Some pre-existing warnings are acceptable; document them in the commit message of Phase 15 for comparison.

- [ ] **Step 3: Stage and commit this plan file**

Run:
```bash
git add docs/superpowers/plans/2026-05-28-docs-audit-rollup.md
git commit -m "audit-rollup: capture implementation plan for docs-drift findings"
```

---

## Phase 1: Schema Migration — Drop Legacy Columns, Rename Role Values

**Goal:** Finish the migration started by `2026_05_17_000000_create_node_roles_table.php`. Drop `nodes.role` and `nodes.environment` (legacy primary-role columns no longer authoritative). Rename role values in `node_roles.role` from `app-development`/`app-production` to `app-dev`/`app-prod`.

**Files:**
- Create: `apps/gateway/database/migrations/2026_05_28_000001_drop_legacy_role_columns_from_nodes.php`
- Create: `apps/gateway/database/migrations/2026_05_28_000002_rename_app_role_values_in_node_roles.php`

**Order:** Run rename first so the `node_roles` data is canonical, then drop the legacy columns.

- [ ] **Step 1: Write the rename migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('node_roles')->where('role', 'app-development')->update(['role' => 'app-dev']);
        DB::table('node_roles')->where('role', 'app-production')->update(['role' => 'app-prod']);
    }

    public function down(): void
    {
        DB::table('node_roles')->where('role', 'app-dev')->update(['role' => 'app-development']);
        DB::table('node_roles')->where('role', 'app-prod')->update(['role' => 'app-production']);
    }
};
```

File path: `apps/gateway/database/migrations/2026_05_28_000002_rename_app_role_values_in_node_roles.php`

- [ ] **Step 2: Write the drop-columns migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn(['role', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->string('role')->nullable();
            $table->string('environment')->nullable();
        });
    }
};
```

File path: `apps/gateway/database/migrations/2026_05_28_000001_drop_legacy_role_columns_from_nodes.php`

**Note on filename order:** The drop must run BEFORE the rename so that running `migrate:fresh` doesn't try to drop a column that the rename migration's `down()` would still try to populate. Confirm filename ordering puts `000001_drop` before `000002_rename`.

Wait — actually, on a fresh migration run, the `up()` of the rename simply updates rows that don't exist yet (no-op). On a real database with data, the rename should run first to canonicalize values, then the drop happens. So both orderings work for `up()`. But `down()` of the rename requires `nodes.role` and `nodes.environment` not be present (they're already gone) — and it doesn't reference them, only `node_roles.role`. So rename's `down()` is independent of drop's `down()`. Both orderings work.

Use the filename ordering above: `000001_drop` then `000002_rename`. Run them in that order on `up()`; on `down()` the inverse order rolls back cleanly.

- [ ] **Step 3: Run the migration**

```bash
bin/orbit-gateway-artisan migrate --no-interaction
```

Expected: both migrations report as `Done`.

- [ ] **Step 4: Verify schema**

```bash
bin/orbit-gateway-artisan tinker --execute 'echo json_encode(Schema::getColumnListing("nodes"));'
```

Expected output: array with `id, name, host, orbit_path, status, created_at, updated_at, tld, platform, wireguard_address, gateway_endpoint, user, public_ipv4, public_ipv6, agent_ide_config, host_key_*` — NO `role`, NO `environment`.

```bash
bin/orbit-gateway-artisan tinker --execute 'echo json_encode(DB::table("node_roles")->select("role")->distinct()->pluck("role"));'
```

Expected: array containing only `gateway, vpn, router, app-dev, app-prod, database, agent, ingress, websocket, s3` (subset present based on actual data). No `app-development` or `app-production`.

- [ ] **Step 5: Commit**

```bash
git add apps/gateway/database/migrations/2026_05_28_000001_drop_legacy_role_columns_from_nodes.php apps/gateway/database/migrations/2026_05_28_000002_rename_app_role_values_in_node_roles.php
git commit -m "audit-rollup: drop nodes.role/environment + rename app role values to app-dev/app-prod"
```

---

## Phase 2: Code — `NodeRoleName` Enum Rename + Model/Factory Cleanup

**Goal:** Update enum + all PHP code references to the new role values. Strip every read/write of `nodes.role` and `nodes.environment` since the columns no longer exist.

**Scope (sweep these directories):**
- `apps/gateway/app/Enums/` — find `NodeRoleName` (or similar) enum class
- `apps/gateway/app/Models/Node.php`, `NodeRole.php` — drop `role` and `environment` from fillable/casts/accessors
- `apps/gateway/app/Console/Commands/` — every command referencing `app-development` / `app-production` / `nodes.role` / `nodes.environment`
- `apps/gateway/app/Services/` — services touching role values
- `apps/gateway/app/Http/Controllers/` — controllers exposing role
- `apps/gateway/database/factories/` — factories setting `role` / `environment`
- `apps/gateway/database/seeders/` — same
- `apps/gateway/tests/` — fixtures, assertions, role names in test data
- Re-grep across `apps/gateway/` for `'app-development'`, `"app-development"`, `'app-production'`, `"app-production"` to confirm full sweep.

- [ ] **Step 1: Locate the role enum**

Run:
```bash
grep -rln "enum NodeRoleName\|case AppDevelopment\|case AppProduction\|'app-development'" apps/gateway/app/ --include="*.php" | head -5
```

Expected: identifies the enum file. Note its path.

- [ ] **Step 2: Update enum case values**

In the enum file, rename:
- `AppDevelopment` case value `'app-development'` → `'app-dev'`
- `AppProduction` case value `'app-production'` → `'app-prod'`

Keep the case identifiers (`AppDevelopment`, `AppProduction`) the same to avoid a wider rename — they describe the intent (`App + environment`). Only the string value changes.

If the enum exposes a `label()` or similar method, update labels to use the canonical names.

- [ ] **Step 3: Update Node model + NodeRole model**

Drop any:
- `protected $fillable` entries for `role`, `environment`
- `protected $casts` for `role`, `environment`
- accessor/mutator methods for these columns
- relationships that join on `nodes.role` / `nodes.environment`

Add or update: if a `getRoleAttribute()` previously derived a single primary role from `nodes.role`, replace with logic that derives from the first `NodeRole` row (or returns `null` for client identities). If callers need the legacy "primary role" concept, expose it through a method on `NodeRole` rather than fabricating a column.

- [ ] **Step 4: Sweep `apps/gateway/` for `'app-development'` / `'app-production'` literals**

Run:
```bash
grep -rn "'app-development'\|\"app-development\"\|'app-production'\|\"app-production\"" apps/gateway/ --include="*.php" | grep -v "/vendor/" | wc -l
```

Replace every hit with `'app-dev'` / `'app-prod'` respectively. After the sweep, re-run the grep — expected: 0 results outside `/vendor/` and outside migrations referencing legacy data.

(Note: migrations like `2026_05_17_000001_backfill_node_roles_from_legacy_nodes.php` and `2026_05_28_000002_rename_app_role_values_in_node_roles.php` keep the legacy strings on purpose — those are historical data references. The sweep should skip those two files.)

- [ ] **Step 5: Update factories**

```bash
grep -rln "role.*=>.*'app-development'\|environment.*=>.*'development'" apps/gateway/database/factories/
```

For each factory hit:
- Drop any `role` / `environment` field setter (the columns no longer exist).
- If a factory previously composed an app-role node by setting `nodes.role`, change it to create a `NodeRole` row via the `NodeRole` model/factory.

- [ ] **Step 6: Run the gateway test suite**

```bash
bin/orbit-gateway-pest --compact 2>&1 | tail -30
```

Expected: many tests will fail at this point because they reference legacy role values or schema fields. That's the TDD signal. The next step fixes them.

- [ ] **Step 7: Fix failing tests one suite at a time**

For each failing test:
- Replace `app-development` → `app-dev`, `app-production` → `app-prod` in assertions/fixtures.
- Replace any direct access to `$node->role` / `$node->environment` with `$node->roles` queries against the `NodeRole` model.
- Update factory invocations to set role assignments through the proper model.

Re-run `bin/orbit-gateway-pest --compact` until it passes (excluding tests that exercise behavior being changed in later phases — note their names in the commit message).

- [ ] **Step 8: Run Pint**

```bash
bin/orbit-gateway-vendor-bin pint --dirty --format agent
```

- [ ] **Step 9: Commit**

```bash
git add apps/gateway/
git commit -m "audit-rollup: rename app-development/app-production to app-dev/app-prod across gateway code"
```

---

## Phase 3: Authority Docs Rename

**Goal:** Update mission/architecture/concepts/tech-stack + node-concepts to use the canonical names. This is the upstream source for every downstream doc fix.

**Files:**
- `apps/docs/content/architecture.md:78,121,251,253,360` (role list, role assignment examples)
- `apps/docs/content/concepts.md:34,77-86` (role mentions, family table)
- `apps/docs/content/tech-stack.md:55,72,358-360` (role mentions, platform support)
- `apps/docs/content/domains/1_node/node-concepts.md:20-24,107-122,127-130,139-150,183-193,215-231` (role vocabulary, compatibility matrix, settings table, baselines, platform support)
- `apps/docs/content/domains/1_node/README.md` (role lists at lines 41, 78-89)

- [ ] **Step 1: Sweep authority docs**

For each file above:
- Replace `app-development` → `app-dev`
- Replace `app-production` → `app-prod`
- Preserve `app-development`/`app-production` ONLY when describing the *template name* (per A3, templates carry the long form even though role values are short). The template-name use is only relevant in `node-new.md` (Phase 6) — authority docs should use short forms throughout.

Run:
```bash
grep -rln "app-development\|app-production" apps/docs/content/architecture.md apps/docs/content/concepts.md apps/docs/content/tech-stack.md apps/docs/content/domains/1_node/node-concepts.md apps/docs/content/domains/1_node/README.md
```

After edits, re-run the grep — expected: 0 results.

- [ ] **Step 2: Verify role compatibility matrix consistency**

In `node-concepts.md:107-122` (role compatibility matrix), confirm rows use `app-dev` / `app-prod`. The matrix's combines-with / conflicts-with lists must also be updated.

- [ ] **Step 3: Run docs-lint**

```bash
composer docs-lint -- --path=content/architecture.md --path=content/concepts.md --path=content/tech-stack.md --path=content/domains/1_node 2>&1 | tail -20
```

Expected: pass, no new errors.

- [ ] **Step 4: Commit**

```bash
git add apps/docs/content/architecture.md apps/docs/content/concepts.md apps/docs/content/tech-stack.md apps/docs/content/domains/1_node/node-concepts.md apps/docs/content/domains/1_node/README.md
git commit -m "audit-rollup: rename app-development/app-production to app-dev/app-prod in authority docs"
```

---

## Phase 4: Command Docs Sweep (`app-development`/`app-production` → `app-dev`/`app-prod`)

**Goal:** Sweep every remaining doc file under `apps/docs/content/` for legacy role names. Exclude template names where applicable.

**Files (per pre-flight count: 64 docs touching the legacy names after Phase 3 trimmed some):**

- [ ] **Step 1: Enumerate remaining files**

```bash
grep -rln "app-development\|app-production" apps/docs/content/ --include="*.md" | sort
```

Expected: ~50 files spread across `domains/1_node/`, `domains/5_app/`, `domains/6_workspace/`, `domains/10_deploy/`, etc.

- [ ] **Step 2: For each file, replace literals**

Use `sed` or per-file replace:
```bash
# example for one file; repeat or batch
sed -i '' 's/app-development/app-dev/g; s/app-production/app-prod/g' <file>
```

**EXCLUDE the following preserved long-form usages:**
- Template names in `apps/docs/content/domains/1_node/1_node-new/node-new.md` after Phase 6 introduces templates. Phase 4 should not edit `node-new.md` (Phase 6 owns it).
- Any historical/changelog references explicitly framed as "legacy" or "before commit X" — preserve in such sections; Phase 4 owns the live prose.

- [ ] **Step 3: Verify no remaining hits outside reserved sections**

```bash
grep -rln "app-development\|app-production" apps/docs/content/ --include="*.md" | grep -v "1_node-new/node-new.md"
```

Expected: 0 results.

- [ ] **Step 4: Run docs-lint per-domain**

```bash
composer docs-lint 2>&1 | tail -20
```

Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add apps/docs/content/
git commit -m "audit-rollup: rename app-development/app-production across command docs"
```

---

## Phase 5: A1 — Drop `--role=operator`; Introduce `--operator` Flag

**Goal:** Replace the `--role=operator` CLI surface with a non-role `--operator` flag for operator-identity setup. Strip the `NodeNewCommand.php:288` translation layer. Update `node-new.md` (the `--role=operator` documentation block).

**Files:**
- Modify: `apps/gateway/app/Console/Commands/NodeNewCommand.php`
- Modify: `apps/docs/content/domains/1_node/1_node-new/node-new.md`
- Modify: `apps/docs/content/domains/1_node/1_node-new/technical/1_node-new.md`
- Modify: `apps/docs/content/domains/1_node/1_node-new/technical/5.1_node-new_input-mode_interactive.md`
- Modify: `apps/docs/content/domains/1_node/1_node-new/technical/5.2_node-new_input-mode_non-interactive.md`
- Modify: `apps/docs/content/domains/5_app/1_app-new/technical/2_app-new_on-client.md` (drop "the operator role" wording)
- Test: `apps/gateway/tests/Feature/Commands/Nodes/NodeNewCommandTest.php` (or equivalent)

- [ ] **Step 1: Write a failing test for `--operator` flag**

In the appropriate test file (likely `apps/gateway/tests/Feature/Commands/Nodes/NodeNewCommandTest.php`):

```php
it('creates a client identity with the operator preset when --operator flag is set', function (): void {
    $this->artisan('node:new', ['name' => 'operator-1', '--operator' => true, '--no-interaction' => true])
        ->assertSuccessful();

    $node = Node::query()->where('name', 'operator-1')->first();
    expect($node)->not->toBeNull();
    expect($node->roles()->count())->toBe(0); // no role assignments
    // Confirm an operator-preset grant exists from operator-1 to the gateway
    $grant = NodeAccess::query()
        ->where('consuming_node_id', $node->id)
        ->whereHas('servingNode', fn ($q) => $q->whereHas('roles', fn ($qq) => $qq->where('role', 'gateway')))
        ->first();
    expect($grant)->not->toBeNull();
    expect($grant->preset)->toBe('operator'); // or however preset is stored
});

it('rejects --role=operator as an unsupported role value', function (): void {
    $this->artisan('node:new', ['name' => 'x', '--role' => 'operator', '--no-interaction' => true])
        ->expectsOutputToContain('Node role must be one of')
        ->assertFailed();
});
```

- [ ] **Step 2: Run the test to verify failure**

```bash
bin/orbit-gateway-pest --compact --filter=NodeNewCommandTest 2>&1 | tail -10
```

Expected: both new tests FAIL.

- [ ] **Step 3: Add `--operator` flag and remove `--role=operator` handling in NodeNewCommand.php**

Specific edits:
- `NodeNewCommand.php:71` signature: replace `Use operator, gateway, ...` text with `Use gateway, app-dev, app-prod, ingress, database, agent, websocket, or s3. Repeatable for workload roles. For operator (no roles), use --operator instead.`
- Add a `--operator` (boolean) flag declaration in the same signature block.
- Remove every `if ($executionContext === 'control')` branch path that gets there via `--role=operator`. Re-route through the new `--operator` flag.
- Lines 107, 163, 204, 226, 246, 254, 261, 270, 277, 1348, 1349: rename `'control'` → `'operator'` only where it referred to the user-facing context; if it was specifically the legacy DB value, REMOVE the code that wrote it (the column no longer exists).
- Line 288: delete the `'requested_role' => $role === 'control' ? 'operator' : $role` translation; the meta should report the actual flag (`requested_template: operator` if templates land in Phase 6, otherwise `requested_operator: true`).
- Line 1348-1349: remove `role: 'control'` and `roles: ['control']` — these write to the dropped column.

- [ ] **Step 4: Re-run tests**

```bash
bin/orbit-gateway-pest --compact --filter=NodeNewCommandTest 2>&1 | tail -20
```

Expected: both new tests PASS. Other NodeNew tests may need fixture updates if they used `--role=operator`.

- [ ] **Step 5: Update `node-new.md` and technical signatures**

In `node-new.md`:
- Line 20 signature: add `[--operator]` next to existing flags.
- Lines 99-105 (`## Workload Roles` → `**Client identity**` → `--role=operator` block): remove the `--role=operator` mention. Note this block gets restructured in Phase 6 (templates); for now, replace with `**Client identity**` text describing the empty-`--role` path and pointing forward to the `--operator` flag for operator-preset setup.
- Line 42 example: change `--grant-to=all --grant-to-preset=operator` (preset, fine) — keep that line. But also add an example: `orbit node:new operator-1 --operator`.

In `technical/1_node-new.md`:
- Line 23 signature: add `[--operator]`.
- Input contract table (lines 31-43): add `operator` field row sourced from `--operator`, defaulting to false, required for operator-identity creation, mutually exclusive with `--role`.

In `5.1_node-new_input-mode_interactive.md` and `5.2_node-new_input-mode_non-interactive.md`:
- Add prompt mapping / non-interactive behavior for `--operator`.

In `2_app-new_on-client.md:5`:
- Rewrite "CLI on a peer with the operator role" → "CLI on an operator-identity peer (a node with the `operator` preset and no role assignments)".

- [ ] **Step 6: Run docs-lint**

```bash
composer docs-lint 2>&1 | tail -10
```

Expected: pass.

- [ ] **Step 7: Run Pint + Pest**

```bash
bin/orbit-gateway-vendor-bin pint --dirty --format agent
bin/orbit-gateway-pest --compact 2>&1 | tail -10
```

Expected: pass.

- [ ] **Step 8: Commit**

```bash
git add apps/gateway/ apps/docs/content/
git commit -m "audit-rollup: replace --role=operator with --operator flag; drop control translation"
```

---

## Phase 6: A3 — Template Model in `node:new`

**Goal:** Replace the role-listing UX with a template-driven UX. Each template names a role composition. `custom` template falls back to current `--role=` repeatable behavior. S3 + WebSocket templates documented but marked `implementation pending`.

**Files:**
- Modify: `apps/gateway/app/Console/Commands/NodeNewCommand.php`
- Create: `apps/gateway/app/Services/Nodes/NodeTemplate.php` (template registry + expansion logic)
- Create: `apps/gateway/app/Services/Nodes/NodeTemplateExpander.php`
- Modify: `apps/docs/content/domains/1_node/1_node-new/node-new.md` (full restructure)
- Modify: `apps/docs/content/domains/1_node/1_node-new/technical/1_node-new.md` (input contract)
- Modify: `apps/docs/content/domains/1_node/1_node-new/technical/5.1_*` and `5.2_*` (prompt + non-interactive)
- Modify: `apps/docs/content/domains/1_node/1_node-new/technical/6.2_node-new_output-render_json.md` (action+payload)
- Test: `apps/gateway/tests/Feature/Commands/Nodes/NodeNewTemplateTest.php` (new)
- Test: `apps/gateway/tests/Unit/Services/Nodes/NodeTemplateExpanderTest.php` (new)

- [ ] **Step 1: Define template registry**

Create `apps/gateway/app/Services/Nodes/NodeTemplate.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Nodes;

enum NodeTemplate: string
{
    case Operator = 'operator';
    case AppDevelopment = 'app-development';
    case AppProduction = 'app-production';
    case Gateway = 'gateway';
    case Ingress = 'ingress';
    case Database = 'database';
    case S3 = 's3';
    case Websocket = 'websocket';
    case Agent = 'agent';
    case Custom = 'custom';

    /**
     * Whether the template is fully implemented today.
     */
    public function isImplemented(): bool
    {
        return match ($this) {
            self::S3, self::Websocket => false,
            default => true,
        };
    }

    /**
     * Whether the template requires --host.
     */
    public function requiresHost(): bool
    {
        return $this !== self::Operator;
    }
}
```

- [ ] **Step 2: Define template expander**

Create `apps/gateway/app/Services/Nodes/NodeTemplateExpander.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Nodes;

class NodeTemplateExpander
{
    /**
     * @return array{roles: array<string>, optional_roles: array<string>}
     */
    public function expand(NodeTemplate $template, array $options = []): array
    {
        return match ($template) {
            NodeTemplate::Operator => ['roles' => [], 'optional_roles' => []],
            NodeTemplate::AppDevelopment => [
                'roles' => ['app-dev', 'database'],
                'optional_roles' => ['s3', 'websocket'],
            ],
            NodeTemplate::AppProduction => [
                'roles' => array_filter(['app-prod', $options['colocated_ingress'] ?? true ? 'ingress' : null]),
                'optional_roles' => [],
            ],
            NodeTemplate::Gateway => ['roles' => ['gateway', 'vpn', 'router'], 'optional_roles' => []],
            NodeTemplate::Ingress => ['roles' => ['ingress'], 'optional_roles' => []],
            NodeTemplate::Database => ['roles' => ['database'], 'optional_roles' => ['s3', 'websocket']],
            NodeTemplate::S3 => ['roles' => ['s3'], 'optional_roles' => []],
            NodeTemplate::Websocket => ['roles' => ['websocket'], 'optional_roles' => []],
            NodeTemplate::Agent => ['roles' => ['agent'], 'optional_roles' => []],
            NodeTemplate::Custom => ['roles' => $options['custom_roles'] ?? [], 'optional_roles' => []],
        };
    }
}
```

- [ ] **Step 3: Write the expander test**

`apps/gateway/tests/Unit/Services/Nodes/NodeTemplateExpanderTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\Nodes\NodeTemplate;
use App\Services\Nodes\NodeTemplateExpander;

it('expands operator template to empty role set', function (): void {
    $expander = new NodeTemplateExpander();
    $result = $expander->expand(NodeTemplate::Operator);
    expect($result['roles'])->toBe([]);
});

it('expands app-development template to app-dev + database with optional s3/websocket', function (): void {
    $expander = new NodeTemplateExpander();
    $result = $expander->expand(NodeTemplate::AppDevelopment);
    expect($result['roles'])->toBe(['app-dev', 'database']);
    expect($result['optional_roles'])->toBe(['s3', 'websocket']);
});

it('expands app-production template to app-prod + ingress when colocated', function (): void {
    $expander = new NodeTemplateExpander();
    $result = $expander->expand(NodeTemplate::AppProduction, ['colocated_ingress' => true]);
    expect($result['roles'])->toBe(['app-prod', 'ingress']);
});

it('expands app-production template to app-prod only when ingress separated', function (): void {
    $expander = new NodeTemplateExpander();
    $result = $expander->expand(NodeTemplate::AppProduction, ['colocated_ingress' => false]);
    expect($result['roles'])->toBe(['app-prod']);
});

it('expands gateway template to gateway + vpn + router', function (): void {
    $expander = new NodeTemplateExpander();
    $result = $expander->expand(NodeTemplate::Gateway);
    expect($result['roles'])->toBe(['gateway', 'vpn', 'router']);
});

it('marks s3 + websocket templates as not yet implemented', function (): void {
    expect(NodeTemplate::S3->isImplemented())->toBeFalse();
    expect(NodeTemplate::Websocket->isImplemented())->toBeFalse();
});

it('marks all other templates as implemented', function (): void {
    foreach ([NodeTemplate::Operator, NodeTemplate::AppDevelopment, NodeTemplate::AppProduction, NodeTemplate::Gateway, NodeTemplate::Ingress, NodeTemplate::Database, NodeTemplate::Agent, NodeTemplate::Custom] as $template) {
        expect($template->isImplemented())->toBeTrue();
    }
});
```

- [ ] **Step 4: Run the expander test, verify fail then pass**

```bash
bin/orbit-gateway-pest --compact --filter=NodeTemplateExpanderTest 2>&1 | tail -15
```

Expected: PASS once the expander is in place. If tests fail, fix the expander code until they pass.

- [ ] **Step 5: Wire template into NodeNewCommand**

In `NodeNewCommand.php`:
- Add `--template=<name>` option to the signature.
- When `--template` is supplied, call `NodeTemplateExpander::expand()` and treat the resulting role set as if it had been passed via `--role` multiple times.
- Reject `--template` and `--role` together unless `--template=custom`.
- When `--template=s3` or `--template=websocket` is requested, fail with `template_not_implemented` and a meta pointing at the relevant Solo todos for the user.
- Interactive prompt: replace the existing role-selection prompt with a template selector listing the 10 templates plus "custom".

- [ ] **Step 6: Write integration test for `--template`**

`apps/gateway/tests/Feature/Commands/Nodes/NodeNewTemplateTest.php`:

```php
<?php

declare(strict_types=1);

it('creates a gateway node with all three coupled roles when --template=gateway', function (): void {
    $this->artisan('node:new', [
        'name' => 'gw-1',
        '--template' => 'gateway',
        '--host' => '203.0.113.2',
        '--operator-name' => 'op-1',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $node = Node::query()->where('name', 'gw-1')->first();
    expect($node->roles()->pluck('role')->sort()->values()->all())
        ->toBe(['gateway', 'router', 'vpn']);
});

it('fails with template_not_implemented when --template=s3 is requested', function (): void {
    $this->artisan('node:new', [
        'name' => 's3-1',
        '--template' => 's3',
        '--host' => '203.0.113.3',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('template_not_implemented')
        ->assertFailed();
});

it('rejects --template and --role together unless --template=custom', function (): void {
    $this->artisan('node:new', [
        'name' => 'x',
        '--template' => 'gateway',
        '--role' => 'agent',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('validation_failed')
        ->assertFailed();
});

it('accepts --template=custom with --role=...', function (): void {
    $this->artisan('node:new', [
        'name' => 'custom-1',
        '--template' => 'custom',
        '--role' => 'agent',
        '--host' => '203.0.113.4',
        '--no-interaction' => true,
    ])->assertSuccessful();
});
```

- [ ] **Step 7: Run integration tests, fix until passing**

```bash
bin/orbit-gateway-pest --compact --filter=NodeNewTemplateTest 2>&1 | tail -20
```

Iterate.

- [ ] **Step 8: Restructure `node-new.md`**

Rewrite `apps/docs/content/domains/1_node/1_node-new/node-new.md`:
- Signature line: add `[--template=<template>]`, keep `[--role=<role>]...` as the custom escape hatch.
- Replace the `## Workload Roles` section (lines 99-247) with `## Templates`. Document each template name, the role composition, dependencies (`--host`, `--ingress`, `--redis-node`, etc.), and example. Mark `s3` and `websocket` with an explicit `implementation pending` note linking to Solo todos:
  ```
  > **Status:** Implementation pending. Tracked under Solo todos ORBIT-S3-* (template `s3`) and ORBIT-WEBSOCKET-* (template `websocket`). Documented here so the CLI surface is stable; current behavior fails before side effects.
  ```
- Add an explicit section `## Templates At A Glance` with a summary table:

  | Template | Roles | Optional add-ons | Requires `--host` | Status |
  |---|---|---|---|---|
  | `operator` | (none; client identity with `operator` preset) | — | no | live |
  | `app-development` | `app-dev` + `database` | `s3`, `websocket` | yes | live |
  | `app-production` | `app-prod` + `ingress` (colocated) or `app-prod` alone (requires `--ingress=<node>`) | — | yes | live |
  | `gateway` | `gateway` + `vpn` + `router` | — | yes | live |
  | `ingress` | `ingress` | — | yes | live |
  | `database` | `database` | `s3`, `websocket` | yes | live |
  | `s3` | `s3` | — | yes | implementation pending |
  | `websocket` | `websocket` | — | yes | implementation pending |
  | `agent` | `agent` | (agent-tools via `--agent-tool=`) | yes | live |
  | `custom` | caller-supplied via `--role=...` | — | depends on selection | live |

- Keep the `## Grant Setup` section and adjust to reference templates where helpful.

- [ ] **Step 9: Update technical contract**

In `technical/1_node-new.md`:
- Add `template` input contract row.
- Make `--role` forbidden when `--template` is set unless `--template=custom`.
- Add a "Template expansion" subsection describing how templates resolve to roles before validation.

In `5.1_*_input-mode_interactive.md`:
- Replace the role-list prompt with a template-selector prompt.

In `5.2_*_input-mode_non-interactive.md`:
- Replace the role-list validation with template-name validation; document `--template=custom` as the path that re-enables `--role=...`.

In `6.2_*_output-render_json.md`:
- Add `success.data.template` field to the response payload (the template the caller invoked, even if `custom`).

- [ ] **Step 10: Run docs-lint + Pest + Pint**

```bash
composer docs-lint 2>&1 | tail -10
bin/orbit-gateway-pest --compact 2>&1 | tail -10
bin/orbit-gateway-vendor-bin pint --dirty --format agent
```

Expected: all pass.

- [ ] **Step 11: Commit**

```bash
git add apps/gateway/ apps/docs/content/
git commit -m "audit-rollup: introduce template model in node:new with companion code"
```

---

## Phase 7: A5 — Drop `--environment` From `node:update`

**Goal:** Remove `--environment` handler from `node:update`. Role environment changes go through `node role:remove` + `node role:add`.

**Files:**
- Modify: `apps/gateway/app/Console/Commands/NodeUpdateCommand.php`
- Modify: `apps/docs/content/domains/1_node/7_node-update/node-update.md`
- Modify: `apps/docs/content/domains/1_node/7_node-update/technical/1_node-update.md`
- Modify: `apps/docs/content/domains/1_node/7_node-update/technical/3_node-update_on-gateway-node.md`
- Modify: `apps/docs/content/domains/1_node/7_node-update/technical/5.2_node-update_input-mode_non-interactive.md`
- Modify: `apps/docs/content/domains/1_node/7_node-update/technical/6.2_node-update_output-render_json.md`
- Test: `apps/gateway/tests/Feature/Commands/Nodes/NodeUpdateCommandTest.php` and `NodeUpdateNonInteractiveInputModeTest.php`

- [ ] **Step 1: Write failing test**

```php
it('rejects --environment with unknown_option', function (): void {
    $this->artisan('node:update', [
        'name' => 'app-1',
        '--environment' => 'production',
        '--no-interaction' => true,
    ])->assertFailed();
});
```

- [ ] **Step 2: Run test, verify fail**

```bash
bin/orbit-gateway-pest --compact --filter=NodeUpdateCommandTest 2>&1 | tail -10
```

- [ ] **Step 3: Strip `--environment` from NodeUpdateCommand**

Remove the option declaration and every handler branch that read `node_update.environment`. Update the signature comment to mention `node role:add` / `node role:remove` for environment changes.

- [ ] **Step 4: Re-run tests + fix any cascade failures**

```bash
bin/orbit-gateway-pest --compact --filter=NodeUpdate 2>&1 | tail -30
```

Existing tests that asserted `--environment` success behavior must be deleted (their behavior is being removed).

- [ ] **Step 5: Update doc files**

In `node-update.md`:
- Drop `--environment=<development|production>` from signature.
- Drop examples using `--environment`.
- Add a "Switching app environment" subsection pointing at `node role:remove app-dev` + `node role:add app-prod`.

In `technical/1_node-update.md`:
- Drop the `environment` input contract row.
- Drop the field role-incompatible rules that referenced `--environment`.
- Remove the "Effective environment" subsection.

Repeat for `3_*_on-gateway-node.md`, `5.2_*_non-interactive.md`, `6.2_*_output-render_json.md`. The `node.environment` JSON field also disappears (the node entity no longer has an environment).

- [ ] **Step 6: Run docs-lint + Pest + Pint**

```bash
composer docs-lint 2>&1 | tail -10
bin/orbit-gateway-pest --compact 2>&1 | tail -10
bin/orbit-gateway-vendor-bin pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add apps/gateway/ apps/docs/content/
git commit -m "audit-rollup: drop --environment from node:update (environment now derived from role)"
```

---

## Phase 8: A4 — `node:list` Signature Alignment

**Goal:** Align public and technical `--role=` enum. Drop legacy `app`, `operator`, and `--environment` from the technical signature.

**Files:**
- Modify: `apps/docs/content/domains/1_node/3_node-list/node-list.md`
- Modify: `apps/docs/content/domains/1_node/3_node-list/technical/1_node-list.md`
- Modify: `apps/docs/content/domains/1_node/3_node-list/technical/6.2_node-list_output-render_json.md`
- Modify: `apps/gateway/app/Console/Commands/NodeListCommand.php` (if it exposed the legacy filter)
- Test: `apps/gateway/tests/Feature/Commands/Nodes/NodeListCommandTest.php` (or equivalent)

- [ ] **Step 1: Update public signature**

`node-list.md:14` signature already lists the 10 architecture roles; update only if Phase 2's enum rename changed the order or names.

- [ ] **Step 2: Update technical signature**

`technical/1_node-list.md:16` signature:
- Drop `|app|operator` from `--role=` enum.
- Drop `--environment=<development|production>` filter entirely.

Lines 30, 38, 49, 79, 113: drop every supporting row that references `--environment` or legacy role values.

- [ ] **Step 3: Update JSON renderer doc**

`technical/6.2_node-list_output-render_json.md:181`: drop `--environment` from the filter contract paragraph.

- [ ] **Step 4: Update NodeListCommand to reject legacy filter values**

If `NodeListCommand.php` still parses `--environment` or accepts `--role=app`/`--role=operator`, remove those code paths.

- [ ] **Step 5: Write test**

```php
it('rejects --environment filter with unknown_option', function (): void {
    $this->artisan('node:list', ['--environment' => 'production', '--json' => true])->assertFailed();
});

it('rejects --role=operator filter as unsupported', function (): void {
    $this->artisan('node:list', ['--role' => 'operator', '--json' => true])
        ->expectsOutputToContain('validation_failed')
        ->assertFailed();
});
```

- [ ] **Step 6: Run docs-lint + Pest**

```bash
composer docs-lint 2>&1 | tail -10
bin/orbit-gateway-pest --compact --filter=NodeList 2>&1 | tail -10
```

- [ ] **Step 7: Commit**

```bash
git add apps/gateway/ apps/docs/content/
git commit -m "audit-rollup: align node:list public/technical signatures; drop legacy role+environment filters"
```

---

## Phase 9: A6 — JSON Renderer Cleanup

**Goal:** Walk every JSON renderer file in `apps/docs/content/domains/1_node/` and align examples + enums + error messages with the canonical 10-role set.

**Files:**
- `apps/docs/content/domains/1_node/1_node-new/technical/6.2_node-new_output-render_json.md:90-93,141-154,319-326,445-456`
- `apps/docs/content/domains/1_node/3_node-list/technical/6.2_node-list_output-render_json.md:73-82,181-194,225-230`
- `apps/docs/content/domains/1_node/4_node-show/technical/6.2_node-show_output-render_json.md:31-35,72-80,152-160`
- `apps/docs/content/domains/1_node/9_node-default/technical/6.2_node-default_output-render_json.md:42-45,72-75,114-120,153-164,212-218`

- [ ] **Step 1: For each file, fix examples**

Replace every `"role": "app"` example with `"role": "app-dev"` or `"role": "app-prod"` based on the example context (development tld → app-dev, production → app-prod).

- [ ] **Step 2: Fix enum tables**

Update every `node.role` enum table to the canonical 10 + null: `gateway, vpn, router, app-dev, app-prod, database, agent, ingress, websocket, s3, null`.

- [ ] **Step 3: Fix error message lists**

In error-message tables (e.g., `6.2_node-new_output-render_json.md:325`), update messages like:
```
Node role must be one of gateway, operator, app-dev, app-prod, app-development, app-production, ingress, database, agent, websocket, or s3.
```
to:
```
Node role must be one of gateway, vpn, router, app-dev, app-prod, ingress, database, agent, websocket, or s3.
```
(Note: drop `operator` and the redundant long forms; add `vpn`, `router` since they are valid stored values in `node_roles.role`.)

- [ ] **Step 4: Drop `environment` from JSON entity field tables**

Any field row describing `node.environment` should be removed (column no longer exists per Phase 1).

- [ ] **Step 5: Run docs-lint**

```bash
composer docs-lint -- --path=content/domains/1_node 2>&1 | tail -10
```

- [ ] **Step 6: Commit**

```bash
git add apps/docs/content/domains/1_node/
git commit -m "audit-rollup: align node JSON renderer contracts with canonical 10-role enum"
```

---

## Phase 10: A7 — `node:new` Host Requirement Consistency

**Goal:** Canonicalize the `--host` rule. Every workload-role-bearing path requires `--host`. Only `--template=operator` (and the in-progress `s3`/`websocket` templates) can vary.

**Files:**
- `apps/docs/content/domains/1_node/1_node-new/technical/1_node-new.md:35,58-69`
- `apps/docs/content/domains/1_node/1_node-new/technical/3_node-new_on-gateway-node.md:49-61,135-144`
- `apps/docs/content/domains/1_node/1_node-new/technical/5.2_node-new_input-mode_non-interactive.md:39-46`
- `apps/docs/content/domains/1_node/1_node-new/node-new.md:173-182`

- [ ] **Step 1: Restate the canonical rule**

In `technical/1_node-new.md:35`, update the `host` row's "Required when" cell to: "Any template other than `operator`, OR first-gateway bootstrap, OR gateway convergence."

- [ ] **Step 2: Add `agent` and `database` to the host-requiring list explicitly**

The current list omits both even though they are workload-role-bearing paths.

- [ ] **Step 3: Cross-check `5.2_*_non-interactive.md:42`**

```
| `host` | template=app-development, template=app-production, template=ingress, template=agent, template=database, template=websocket, template=s3, OR template=gateway and --host is absent. | Fail before side effects. |
```

- [ ] **Step 4: Cross-check public page `node-new.md:173-182`**

Ensure the public page lists `agent` and `database` as host-provisioned.

- [ ] **Step 5: Run docs-lint**

```bash
composer docs-lint -- --path=content/domains/1_node/1_node-new 2>&1 | tail -10
```

- [ ] **Step 6: Commit**

```bash
git add apps/docs/content/domains/1_node/1_node-new/
git commit -m "audit-rollup: canonicalize --host rule across node:new contracts"
```

---

## Phase 11: A8 — Caller-Role Wording Cleanup

**Goal:** Rewrite the two remaining caller-role authorization bullets to grants-only language.

**Files:**
- `apps/docs/content/domains/1_node/1_node-new/node-new.md:24-27`
- `apps/docs/content/domains/6_workspace/13_workspace-teardown-step-remove/technical/5.2_workspace-teardown-step-remove_input-mode_non-interactive.md:36-44`

- [ ] **Step 1: Rewrite `node-new.md:24-27`**

Replace:
```
The CLI calls the gateway; the gateway authenticates the presented WireGuard
peer identity and authorizes the request based on the caller's gateway-known
role. First-gateway bootstrap is the exception, because no gateway exists yet
to authenticate against.
```
with:
```
The CLI calls the gateway; the gateway authenticates the presented WireGuard
peer identity and authorizes the request against the scoped permission set on
the caller's grant to the gateway (`node:new` permission, or the `gateway-admin`
preset). First-gateway bootstrap is the one no-gateway path; the bootstrap flow
materializes the initial gateway-admin grant from the initiating
operator-identity client to the new gateway.
```

- [ ] **Step 2: Rewrite workspace-teardown-step-remove caller-role bullet**

Read `apps/docs/content/domains/6_workspace/13_workspace-teardown-step-remove/technical/5.2_workspace-teardown-step-remove_input-mode_non-interactive.md` lines 36-44. Replace caller-role validation language with permission-based language (the permission for workspace teardown step removal — cross-check against `apps/docs/content/domains/6_workspace/README.md` for the canonical permission name).

- [ ] **Step 3: Run docs-lint**

```bash
composer docs-lint 2>&1 | tail -10
```

- [ ] **Step 4: Commit**

```bash
git add apps/docs/content/
git commit -m "audit-rollup: rewrite caller-role wording to grants-only language"
```

---

## Phase 12: B1 — Companion Doc + Test File Renames

**Goal:** Rename all `_on-client.md` → `_on-operator-node.md`, `_on-app-role.md` → `_on-workload-node.md` (none currently exist; slot map only). Rename 6 on-disk test files. Update all references.

**Files (renames):**

Docs:
- `apps/docs/content/domains/11_operation/3_doctor/technical/2_doctor_on-client.md` → `2_doctor_on-operator-node.md`
- `apps/docs/content/domains/1_node/1_node-new/technical/2_node-new_on-client.md` → `2_node-new_on-operator-node.md`
- `apps/docs/content/domains/1_node/5_node-grant/technical/2_node-grant_on-client.md` → `2_node-grant_on-operator-node.md`
- `apps/docs/content/domains/1_node/6_node-revoke/technical/2_node-revoke_on-client.md` → `2_node-revoke_on-operator-node.md`
- `apps/docs/content/domains/1_node/7_node-update/technical/2_node-update_on-client.md` → `2_node-update_on-operator-node.md`
- `apps/docs/content/domains/1_node/8_node-remove/technical/2_node-remove_on-client.md` → `2_node-remove_on-operator-node.md`
- `apps/docs/content/domains/1_node/9_node-default/technical/2_node-default_on-client.md` → `2_node-default_on-operator-node.md`
- `apps/docs/content/domains/2_gateway/1_gateway-add/technical/2_gateway-add_on-client.md` → `2_gateway-add_on-operator-node.md`
- `apps/docs/content/domains/5_app/1_app-new/technical/2_app-new_on-client.md` → `2_app-new_on-operator-node.md`
- `apps/docs/content/domains/5_app/2_app-register/technical/2_app-register_on-client.md` → `2_app-register_on-operator-node.md`
- `apps/docs/content/domains/6_workspace/1_workspace-new/technical/2_workspace-new_on-client.md` → `2_workspace-new_on-operator-node.md`
- `apps/docs/content/domains/6_workspace/2_workspace-setup/technical/2_workspace-setup_on-client.md` → `2_workspace-setup_on-operator-node.md`

Tests:
- `apps/gateway/tests/Feature/Commands/Nodes/NodeDefaultOnControlNodeContractTest.php` → `NodeDefaultOnOperatorNodeContractTest.php`
- `apps/gateway/tests/Feature/Commands/Nodes/NodeGrantOnControlNodeContractTest.php` → `NodeGrantOnOperatorNodeContractTest.php`
- `apps/gateway/tests/Feature/Commands/Nodes/NodeNewOnControlNodeContractTest.php` → `NodeNewOnOperatorNodeContractTest.php`
- `apps/gateway/tests/Feature/Commands/Nodes/NodeRemoveOnControlNodeContractTest.php` → `NodeRemoveOnOperatorNodeContractTest.php`
- `apps/gateway/tests/Feature/Commands/Nodes/NodeRevokeOnControlNodeContractTest.php` → `NodeRevokeOnOperatorNodeContractTest.php`
- `apps/gateway/tests/Feature/Commands/Nodes/NodeUpdateOnControlNodeContractTest.php` → `NodeUpdateOnOperatorNodeContractTest.php`

- [ ] **Step 1: Rename doc files via `git mv`**

```bash
git mv apps/docs/content/domains/11_operation/3_doctor/technical/2_doctor_on-client.md apps/docs/content/domains/11_operation/3_doctor/technical/2_doctor_on-operator-node.md
git mv apps/docs/content/domains/1_node/1_node-new/technical/2_node-new_on-client.md apps/docs/content/domains/1_node/1_node-new/technical/2_node-new_on-operator-node.md
# ... repeat for all 12 files
```

- [ ] **Step 2: Rename test files via `git mv`**

```bash
git mv apps/gateway/tests/Feature/Commands/Nodes/NodeDefaultOnControlNodeContractTest.php apps/gateway/tests/Feature/Commands/Nodes/NodeDefaultOnOperatorNodeContractTest.php
# ... repeat for all 6 files
```

- [ ] **Step 3: Update class names inside test files**

For each renamed test file, update the class declaration (if any) and any internal `class` references:
```bash
sed -i '' 's/OnControlNodeContractTest/OnOperatorNodeContractTest/g; s/OnControlNode/OnOperatorNode/g' apps/gateway/tests/Feature/Commands/Nodes/*OnOperatorNodeContractTest.php
```

- [ ] **Step 4: Update slot map in `domains/README.md:240-256`**

Replace lines describing the technical slot map:
- `2_command-name_on-client.md | Behavior or rendering specific to a CLI running on a client.` → `2_command-name_on-operator-node.md | Behavior or rendering specific to a CLI running on an operator-identity node (no role assignments).`
- `4_command-name_on-app-role.md | Behavior or rendering specific to a CLI running on a node carrying an app role.` → `4_command-name_on-workload-node.md | Behavior or rendering specific to a CLI running on a node carrying any workload role.`

- [ ] **Step 5: Update all doc references**

```bash
grep -rln "_on-client\|on-client.md\|OnControlNode\|ControlForwarding\|ControlIdentity" apps/docs/content/ --include="*.md"
```

For each hit, replace:
- `_on-client.md` references → `_on-operator-node.md`
- `OnControlNode*Test` references → `OnOperatorNode*Test`
- "client caller" or similar prose that referred specifically to operator-identity callers → "operator-node caller" or "operator-identity caller"

Be careful with the word "client" in prose — `node-concepts.md` defines client as any CLI caller, so generic prose should keep using "client" when it really means any caller. Replace only the path/file/class references and the operator-specific framing.

- [ ] **Step 6: Run docs-lint + Pest**

```bash
composer docs-lint 2>&1 | tail -10
bin/orbit-gateway-pest --compact 2>&1 | tail -10
```

Expected: pass.

- [ ] **Step 7: Verify no orphaned references**

```bash
grep -rln "OnControlNode\|ControlForwarding\|ControlIdentity\|_on-client.md\|on-client.md" apps/docs/content/ apps/gateway/ --include="*.md" --include="*.php"
```

Expected: 0 results.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "audit-rollup: rename _on-client.md to _on-operator-node.md; rename OnControlNode tests"
```

---

## Phase 13: B2 — `--environment` Cleanup Tail

**Goal:** Sweep any remaining `--environment` references that Phases 1, 5, 6, 7 didn't catch.

**Files (sweep):**
- `apps/docs/content/domains/1_node/1_node-new/technical/1_node-new.md:23` (signature)
- `apps/docs/content/domains/1_node/1_node-new/technical/5.1_node-new_input-mode_interactive.md:90-101`
- `apps/docs/content/domains/1_node/1_node-new/technical/6.2_node-new_output-render_json.md:43-47,90-93,141-154`
- Anywhere else `--environment` appears under `apps/docs/content/`.

- [ ] **Step 1: Sweep**

```bash
grep -rn "\-\-environment" apps/docs/content/ --include="*.md"
```

For each hit, decide:
- If it's documentation of a current flag (post-cleanup, none should exist) — DELETE.
- If it's in a historical or migration section — preserve only when explicitly framed as legacy.

- [ ] **Step 2: Sweep JSON entity fields**

```bash
grep -rn '"environment":\|node\.environment\b' apps/docs/content/ --include="*.md"
```

Drop every `node.environment` field row from JSON entity tables. Update examples that included an `environment` line.

- [ ] **Step 3: Verify zero hits**

```bash
grep -rn "\-\-environment\|\"environment\":\|node\.environment\b" apps/docs/content/ --include="*.md"
```

Expected: 0 results.

- [ ] **Step 4: Run docs-lint**

```bash
composer docs-lint 2>&1 | tail -10
```

- [ ] **Step 5: Commit**

```bash
git add apps/docs/content/
git commit -m "audit-rollup: sweep remaining --environment / node.environment references"
```

---

## Phase 14: B3 — "Control-peer" Wording Fix

**Goal:** Single-line fix.

**Files:**
- `apps/docs/content/domains/11_operation/3_doctor/technical/2_doctor_on-operator-node.md:103` (post-Phase-12 rename)

- [ ] **Step 1: Edit the line**

Replace:
```
| `apps/gateway/tests/Feature/Commands/Operations/DoctorCommandContractTest.php` | Control-peer forwarding through the typed gateway request, ...
```
with:
```
| `apps/gateway/tests/Feature/Commands/Operations/DoctorCommandContractTest.php` | Operator-node forwarding through the typed gateway request, ...
```

- [ ] **Step 2: Run docs-lint**

```bash
composer docs-lint -- --path=content/domains/11_operation 2>&1 | tail -5
```

- [ ] **Step 3: Commit**

```bash
git add apps/docs/content/domains/11_operation/3_doctor/technical/2_doctor_on-operator-node.md
git commit -m "audit-rollup: replace Control-peer wording with operator-node forwarding"
```

---

## Phase 15: Final Verification

**Goal:** Run the full quality check across docs + code. Capture results in the final commit.

- [ ] **Step 1: Full docs-lint**

```bash
composer docs-lint 2>&1 | tee /tmp/docs-lint-final.txt | tail -30
```

Expected: 0 errors. Pre-existing warnings from before the audit are acceptable; new ones are not.

- [ ] **Step 2: Full quality-check**

```bash
composer quality-check 2>&1 | tee /tmp/quality-check-final.txt | tail -50
```

Expected: pass on Pest, Pint, PHPStan, Rector across every app/package.

- [ ] **Step 3: Capture orphan-reference check**

```bash
grep -rln "OnControlNode\|ControlForwarding\|ControlIdentity\|_on-client.md\|on-client.md\|app-development\|app-production\|--role=operator\|--environment" apps/docs/content/ apps/gateway/ --include="*.md" --include="*.php" 2>&1 | tee /tmp/orphan-refs-final.txt
```

Expected: 0 results (or only historical migration files that intentionally preserve legacy strings).

- [ ] **Step 4: Run a smoke flow against an existing topology**

If a prepared topology exists locally:
```bash
composer e2e:prepare-docker-topology -- --force operator_gateway_app-dev_app-prod
composer test:e2e:docker -- tests/E2E/NodeNewTest.php
```

Expected: prepared topology naming matches Phase 1+2 (notice the topology handle is `app-dev` not `app-development`).

- [ ] **Step 5: Update changelog (if maintained)**

Check `CHANGELOG.md` or equivalent and add an entry summarizing the rollup.

- [ ] **Step 6: Final commit**

```bash
git add CHANGELOG.md /tmp/docs-lint-final.txt /tmp/quality-check-final.txt 2>/dev/null || true
git commit --allow-empty -m "audit-rollup: final verification — docs-lint + quality-check pass"
```

(Empty commit is acceptable if no changelog exists.)

- [ ] **Step 7: Open PR (or signal ready)**

When all phases land cleanly:
```bash
git log --oneline origin/main..HEAD
gh pr create --title "Docs drift audit rollup: A1-A8 + B1-B3" --body-file - <<'EOF'
## Summary

Rollup of 11 docs drift audit findings approved on 2026-05-28.

Audit artifacts:
- Solo scratchpad 338 — `docs-audit-findings-claude`
- Solo scratchpad 339 — `docs-audit-review-codex`
- Solo scratchpad 340 — `docs-audit-final`

Plan: `docs/superpowers/plans/2026-05-28-docs-audit-rollup.md`

## A-tier (decision-affecting)

- **A1:** drop legacy `nodes.role`/`nodes.environment` columns; replace `--role=operator` with `--operator` flag
- **A2:** retire `app-development`/`app-production`; canonical names are `app-dev`/`app-prod`
- **A3:** template model for `node:new` (operator/app-development/app-production/gateway/ingress/database/s3/websocket/agent/custom)
- **A4:** align `node:list` public + technical signatures; drop legacy role enum values and `--environment` filter
- **A5:** remove `--environment` from `node:update`; environment changes go through `node role:*` commands
- **A6:** walk every JSON renderer in `domains/1_node/`; align examples + error enums to canonical 10-role set
- **A7:** canonicalize `--host` rule across `node:new` contracts
- **A8:** rewrite caller-role wording at `node-new.md:24-27` + `workspace-teardown-step-remove` to grants-only

## B-tier (vocabulary)

- **B1:** rename `_on-client.md` → `_on-operator-node.md`, `_on-app-role.md` → `_on-workload-node.md` (slot only). Rename 6 `*OnControlNode*Test.php` → `*OnOperatorNode*Test.php` on disk. Update slot map and ~21 cross-references.
- **B2:** sweep remaining `--environment` references in `node:new` signature, prompts, JSON
- **B3:** replace "Control-peer" wording in operation doctor coverage row

## Test plan

- [ ] composer docs-lint passes
- [ ] composer quality-check passes
- [ ] bin/orbit-gateway-pest --compact passes
- [ ] composer test:e2e (focused) passes against `operator_gateway_app-dev_app-prod` topology
EOF
```

---

## Self-Review

**Spec coverage check:**

- A1 → Phase 1 (schema), Phase 5 (CLI flag). ✅
- A2 → Phase 1 (rename role values), Phase 2 (enum + code), Phase 3 (authority docs), Phase 4 (command docs). ✅
- A3 → Phase 6 (template model). ✅
- A4 → Phase 8. ✅
- A5 → Phase 7. ✅
- A6 → Phase 9. ✅
- A7 → Phase 10. ✅
- A8 → Phase 11. ✅
- B1 → Phase 12. ✅
- B2 → Phase 13 (with most cleanup distributed in Phases 5–8). ✅
- B3 → Phase 14. ✅

**Placeholder scan:** Plan has concrete file paths, commands, and code snippets at every step. No TBD/TODO placeholders.

**Type consistency:**
- `NodeTemplate` enum cases use kebab-case string values (`app-development`, `app-production`) matching template names; underlying role values use the short `app-dev`/`app-prod` form per A2. The enum case identifiers (`AppDevelopment`, `AppProduction`) describe the template intent, not the role value.
- `NodeTemplateExpander::expand()` signature consistent across Phase 6 Steps 2–6.
- Method names match between definition (Step 2) and tests (Step 3).

**Known risks:**
- Phase 2 sweep may miss some test fixtures; Phase 12 test rename pass will catch them.
- Phase 6 template implementation requires interaction with grant setup logic that the plan does not fully spec; implementing-features may need to dispatch a sub-plan for `--template=operator` + grant generation if existing operator-preset logic is not already extractable.
- The "Solo todos for s3/websocket" reference in Phase 6 Step 8 requires fetching the todo IDs at plan execution time; pick the current open S3/WebSocket todos when writing the doc note.

**Plan complete and saved to `docs/superpowers/plans/2026-05-28-docs-audit-rollup.md`.**
