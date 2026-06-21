# Orbit Command Human-Render Audit

**Goal:** Every `orbit` command's human (non-`--json`) output must match its
documented human-rendering contract (`6.1_*_output-render_human.md`) and the UX
primitive contracts in `apps/docs/content/ux/commands/`.

**Started:** 2026-06-17 · CLI source @ 0.1.124 (main)

## Root Cause

`EmitsCanonicalEnvelopes::renderSuccess()` (the base for ~113 commands) has a
generic human path that prints `key: value` per top-level key and
**JSON-encodes any non-scalar value**. Commands that hand structured data
(lists, nested objects, gateway envelopes) to `renderSuccess` without a bespoke
human renderer therefore dump JSON instead of the documented table / detail /
prose. `gateway:status` even dumps the raw `{"success":{"data":...}}` envelope.

Correct pattern (see `node:list`): in `--json` call `renderSuccess($response)`;
otherwise extract the payload and render the documented primitive, with an
empty-state prose line (`No X found.`).

## Rubric (per command, human mode only)

| Primitive | Contract | Reference |
| --- | --- | --- |
| `table` (`*:list`) | `Laravel\Prompts\table()`, UPPERCASE short headers, `—` empty cells, `No X found.` empty-state. Symfony `$this->table()` banned. | `node:list` |
| `show-detail` (`*:show`) | `RendersShowDetails::renderShowDetails()`, `┌ │ ├ └` tree, title-case labels, `—` for missing | `database:show` (doc example) |
| `progress-tree` (>1s) | animated `○`/`◉`/`●` step tree, dim→full footer. `HasStepOutput` banned. | `SpinnerTreeRenderer` (gateway) |
| scalar/prose | `key: value` lines or concise prose is fine when the contract says so | `version` |

Verdicts: ✅ compliant · ❌ violation (JSON dump / wrong primitive / missing
progress) · ⚠️ minor (header case, label wording) · ❓ needs live/closer check.

## Ground-truth (live against beast gateway, 0.1.124)

| Command | Verdict | Note |
| --- | --- | --- |
| `version` | ✅ | flat key/value lines |
| `node:list` | ✅ | proper Prompts table + status dots (reference impl) |
| `tool:list` | ⚠️ | per-node tables, but headers title-case not UPPERCASE |
| `proxy:list` | ❌ | dumps `routes: [{...JSON...}]` |
| `gateway:status` | ❌ | dumps `gateway: {"success":{"data":...}}` (raw envelope) |
| `process:list` | ❓ | needs node/app/workspace context to see success render |

## Audit complete — all 129 documented commands verdicted (2026-06-17)

9 parallel read-only audits covered every domain. Verdicts cross-checked against
live output + source. Status legend: AUDITED → FIXED → VERIFIED.

### Scoreboard

| Verdict | Count | Meaning |
| --- | --- | --- |
| ✅ compliant | 17 | renders documented primitive in human mode |
| ⚠️ minor | ~10 | header case / `—` vs `-` / nested-block vs inline / animation tick |
| ❌ violation | ~90 | JSON dump, wrong primitive, or flat/absent progress |

### ✅ Compliant (do not touch except noted minors)
`version`, `profile`, `node:list` (table ref), `node:show`, `app:show`,
`app:mount` (doc accepts compact dump), `database:query` (JSON-only by design),
`schedule:show`, `schedule:logs`, `workspace:show`, `workspace:log` (doc
mislabels primitive), `dns:list` (clean table exemplar), `tool:show`,
`tool:credentials`, `activity:show`, `metrics:disable` (flat-scalar prose),
`deploy:log` (minor: missing glyph+exit code).

### ⚠️ Minor (UPPERCASE headers + `—` empties, or animation tick)
`tool:list`, `metrics:status`, `metrics:credentials`, `s3:credentials` (header
case + `—`); `update:all` (no animation timer tick → rows freeze between SSE
events); `agent-ide:message` (correct prose/footer but static tree, no
idle/animation); app `analytics`/`websocket` family (doc shows nested block,
impl dumps inline JSON — reconcile).

### ❌ Violations by fix-cluster

**Cluster 1 — list → `table()` (JSON-dumps the array today):** `app:list`,
`app:instance list`, `app:env list`, `proxy:list`, `process:list`,
`schedule:list`, `firewall:list`, `workspace:list`, `workspace:history`,
`workspace-setup-step:list`, `workspace-teardown-step:list`, `database:list`,
`database:tables`, `database:schema`, `database:describe`, `cf-zone:list`,
`cf-dns:list`, `vpn-client:list`, `php:list`, `activity:list`, `gateway:list`,
`deploy:step-list`, `deploy:history`, `node role:list`. **(24)**

**Cluster 2 — show → `renderShowDetails()`:** `app:instance show`,
`database:show`. **(2)**

**Cluster 3 — concise prose line (doc says prose; impl dumps nested object):**
`node:grant`, `node:agent-ide`, `gateway:use`, `gateway:status`,
`database:add/update/remove/attach/detach`, `deploy:step-add`,
`deploy:step-remove`, `app:instance add/remove`, `app:env set/render`,
`app:worker`, `metrics:enable`, `analytics:update`, cf write success lines,
workspace step add/remove. **(~25)**

**Cluster 4 — progress-tree (flat `[tree]`/`[step]` text, or no progress at
all):**
- *Streaming via `StreamsGatewayProgress` → flat text (not animated):* `app:new`,
  `node:new`, `deploy:run`, `doctor`, `tool:install`, `tool:update`,
  `tool:reconfigure`, `s3:publish`, `s3:unpublish`, `workspace:new`,
  `workspace:setup`.
- *No progress at all (plain POST/DELETE → `renderSuccess`):* `app:register`,
  `app:root`, `app:remove`, `app:prune`, `app:agent-ide`, `node:revoke`,
  `node:update`, `node:remove`, `node:default`, `node:permissions`,
  `node role:add`, `node role:remove`, `gateway:add`, `gateway:trust`,
  `process:add/edit/remove/start/stop/restart`, `proxy:add/remove`,
  `schedule:add/remove/run`, `tool:remove`, `firewall:allow/deny/remove`,
  `cf-dns:add/remove`, `cf-cache:flush`, `cf-cache-rule:add/remove`,
  `cf-ssl:enable/disable`, `dns:resolve-tld`, `vpn-client:new/enable/disable/remove`,
  `vpn-web-ui:change-password`, `php:use`, `workspace:remove`. **(~50)**

**Cluster 5 — bespoke:** `update` (static `○`→`●`, no spinner; 1 step vs doc's
4 — doc likely stale); `doctor` (no CLI framed-panel renderer exists at all —
largest single gap).

## Keystone infrastructure gaps (fix once → many commands comply)

1. **No `WithStepTree`/`SpinnerTreeRenderer` in the CLI.** `progress-tree.md`
   names `WithStepTree::runStepTree` as canonical, but it lives only in
   `apps/gateway/app/Support/Cli/`. The CLI literally cannot render an animated
   tree today. Blocks all of Cluster 4 + 5.
2. **`StreamsGatewayProgress::renderProgressFrame()` emits flat `[tree]`/`[step]`
   text** (`StreamsGatewayProgress.php:195-226`) instead of animating an
   SSE-fed step tree. Fixing this one method lifts every *streaming* Cluster-4
   command at once.
3. **`renderSuccess` generic dump** (`EmitsCanonicalEnvelopes.php:75-97`) is the
   success path for ~99 commands. The fix is per-command human branches — keep
   `renderSuccess` for `--json` and as a debug fallback only.
4. **Tests rubber-stamp the bug.** Human-mode tests assert weak substrings
   (`toContain('apps')`, `expectsOutputToContain('result')`) that pass against
   the JSON dump. Every fix needs real structure assertions + a
   `->not->toContain('{')`/`('[')` / `('nodes: [')` guard (model:
   `NodeListCommandTest`, `NodeShowCommandTest`).

## Doc contradictions to resolve BEFORE coding (per CLAUDE.md docs-authority rule)

- **D1 — progress-over-plain-POST:** ~40 mutation docs mandate a progress tree,
  but their gateway endpoints are plain JSON POST/PATCH/DELETE (no SSE) and the
  CLI has no tree renderer. Decide the model: (a) port `WithStepTree` to CLI +
  convert endpoints to SSE; (b) render a client-side tree around the blocking
  call; (c) treat these as prose, not trees, and correct the docs.
- **D2 — `update` steps:** doc lists 4 steps (gateway deps/migrations); impl has
  1 (`pull_source`) post host-toolchain reversal. Likely doc is stale.
- **D3 — `process:logs`:** doc mandates a progress tree; rubric + impl say a pure
  log stream needs none. Likely doc over-specified.
- **D4 — `workspace:log`:** doc Primitive = "progress-tree" but contract/impl is
  a static captured-step list. Doc mislabel.
- **D5 — analytics/websocket nested blocks:** docs show indented YAML-ish blocks;
  generic renderer emits inline JSON. Render nested, or relax docs.
- **D6 — phantom Test Mappings:** nearly every doc's Test Mapping points to
  `apps/gateway/tests/.../*HumanRendererTest.php` files that do not exist; human
  rendering is the CLI's job. Also `progress-tree.md` cites nonexistent
  `TldResolveCommand`/`GatewayConnectCommand` reference impls.

## Fix plan (sequenced, docs-led + failing-test-first, in a worktree)

1. **Cluster 1 (list→table)** — 24 commands, zero ambiguity, mirrors
   `node:list`/`dns:list`. Includes the flagship `app:list`. ← start here.
2. **Cluster 2 (show→detail)** — 2 commands, mirrors `node:show`.
3. **Cluster 3 (prose lines)** — extract payload, print documented line.
4. **Minor (⚠️)** — UPPERCASE headers + `—` on the 4 table commands.
5. **Keystone infra** — port animated step tree to CLI + rewrite
   `StreamsGatewayProgress` animation (gated by D1 decision).
6. **Cluster 4/5 + doctor panel + update animation** — after infra + D1–D5.

_Per-command status tracked inline above; flip ❌→FIXED→VERIFIED as work lands._

## Work log

Worktree: `.worktrees/command-human-render` (branch `command-human-render`).

- **`app:list` — ✅ VERIFIED (2026-06-17).** Added human branch mirroring
  `node:list`: per-node `Laravel\Prompts\table()` (NAME/URL/STATUS), workspace
  child rows `├─`/`└─`, `No apps found.` empty-state, `—` for missing.
  Resolved doc contradiction: 6.2 says apps emit no status field → app STATUS
  renders the desired-state marker `expected` (const `AppDesiredStateStatus`);
  workspace STATUS = `lifecycle_status`. Test `AppListCommandTest` rewritten
  from weak `toContain('apps')` to assert table structure + `not->toContain('apps: [')`.
  8/8 green. **Live-verified against beast gateway** — renders the grouped
  table correctly. Template for the rest of Cluster 1.
- **Cluster 1 (list → table) — ✅ COMPLETE & COMMITTED `29076882c` (2026-06-18).**
  18 commands now render `Laravel\Prompts\table()` in human mode, tests
  rewritten to assert structure + `not->toContain('<key>: [')`, full apps/cli
  suite **1227 passed**, Pint + PHPStan clean, all live-verified against beast:
  `app:list`, `process:list`, `proxy:list`, `schedule:list`, `firewall:list`,
  `workspace:list`, `workspace:history`, `workspace-setup-step:list`,
  `workspace-teardown-step:list`, `cf-zone:list`, `cf-dns:list`,
  `vpn-client:list`, `php:list`, `activity:list`, `gateway:list`,
  `node role:list`, `deploy:step-list`, `deploy:history`.
  (`--live` progress tree for `php:list`/cf/vpn deferred to the progress cluster.)
  Remaining Cluster-1 read commands still open: `database:list`,
  `database:tables`, `database:schema`, `database:describe`,
  `app:instance list`, `app:env list`.

## Doc reconciliations surfaced during Cluster 1 (need a docs pass)

- **deploy:step-list doc prescribes the BANNED Symfony table** (row separators +
  `&&` line-splitting). `table.md` bans `$this->table()`. Impl now uses
  `Laravel\Prompts\table`. → correct the command doc to the UX contract.
- **Empty-state strings unspecified** in proxy/schedule/firewall/workspace-history/
  node-role/deploy docs — impl used house-style `No X found.`/scope-aware
  variants. → docs should quote the exact lines now implemented.
- **schedule:list `-` vs `—`** — doc shows ASCII hyphen; impl uses em dash per
  `table.md` + sibling precedent. → fix doc.
- **gateway:list empty-state wording** drift (`No gateways configured.` vs the
  actual `No gateways are configured. Run orbit gateway:add first.`).
- **Phantom Test Mappings** — most docs cite `apps/gateway/tests/.../*HumanRendererTest.php`
  that don't exist; real coverage is in `apps/cli/tests/...`. (D6)

- **READ/INSPECTION SURFACE COMPLETE — committed `e796b040a` (2026-06-18).**
  Slice 2 added: database:list/tables/schema/describe (table), database:show +
  app:instance show (show-detail), app:instance list + app:env list (table),
  and UPPERCASE-header/em-dash fixes for tool:list, metrics:status,
  metrics:credentials, s3:credentials. Combined with slice 1, **every
  `*:list`, `*:show`, `*:tables/schema/describe`, `*:credentials`, `*:status`,
  `*:history`, `*-step-list` now renders its documented primitive.** Suite 1242
  passed (deterministic), Pint + PHPStan clean, key renders live-verified.
  database:tables/schema/describe found a JSON-doc shape drift: 6.2 docs claim
  `data.tables[]`/`data.{columns,indexes}` but the live gateway returns the
  generic query-runner `data.{columns,rows}` (impl built to the real payload;
  docs need correcting).

## REMAINING WORK (gated — needs a decision before proceeding)

Read surface done. What's left is the **write/mutation + progress** half:

- **Cluster 3 (prose success lines)** ~25 mutation commands that should print a
  concise line but dump a nested object.
- **Cluster 4 (progress-tree)** ~50 commands. **Blocked by contradiction D1:**
  their docs mandate an animated `WithStepTree::runStepTree` tree, but that
  trait/renderer exists ONLY in the gateway (`SpinnerTreeRenderer`), not the
  CLI, and ~40 of these hit plain JSON POST/PATCH/DELETE endpoints (no SSE). The
  already-streaming ones (app:new, node:new, deploy:run, doctor, tool:install/
  update/reconfigure, s3:publish/unpublish, workspace:new/setup) render flat
  `[tree]`/`[step]` text via `StreamsGatewayProgress` instead of an animated tree.
- **Cluster 5** — `update` (static dots, no spinner; doc shows 4 steps, impl has
  1 — stale doc D2), `doctor` (no CLI framed-panel renderer at all — largest gap).
- **Minor** — update:all animation tick, agent-ide:message static tree,
  analytics/websocket nested-block vs inline JSON (D5).

### Progress decision (resolved by user)
Build the tree renderer the CLI needs; since the gateway also uses it, it lives
in the shared **core package**. Continue autonomously, commit per cluster.

### Progress infrastructure — ✅ COMMITTED `4cf01d57d`
Moved `SpinnerTreeRenderer` + `LifecycleSummaryRenderer` to
`packages/core/src/Progress/`; added `StepTree` engine (`run()` sequential +
`runOperation()` atomic) + `StepTreeResult`; CLI `WithStepTree` trait
(`runStepTree`/`runStepOperation`). Forked ticker animates active rows (the
"blinking"). Atomic ops settle all phases together on success, none on failure.
Gateway renderers rewired to core. `node:remove` is the flagship.

### Mutation wave 1 — ✅ COMMITTED `862bf1a4e` (34 commands)
node (revoke/update/default/permissions/role-add/role-remove trees; grant/
agent-ide prose), process (add/edit/remove/start/stop/restart), proxy (add/
remove), schedule (add/remove/run), gateway (add/trust trees; use/status prose —
gateway:status no longer dumps the envelope), firewall (allow/deny/remove), cf
(dns-add/remove, cache-flush, cache-rule-add/remove, ssl-enable/disable),
dns:resolve-tld. Suite 1347, PHPStan + Pint clean, live-verified.

Template note: atomic gateway mutations use `runStepOperation`; `doneFooter`
closures that read the captured response must use `function () use (&$response)`
(arrow-fns capture by value before the call runs).

### Mutation wave 2 — ✅ COMMITTED `c5f18e7a8` (~34 commands)
vpn writes, database/deploy prose, app writes (register/root/remove/prune/
agent-ide trees; worker/instance/env/analytics/websocket prose), metrics:enable,
analytics:update, workspace:remove + steps. Suite 1421, clean, live-verified.

### Streaming animation — ✅ COMMITTED `d85189720`
`StreamsGatewayProgress` now drives `Orbit\Core\Progress\StreamedStepTree`
(relocated from the gateway). app:new, node:new, deploy:run, doctor (progress),
tool:install/update/reconfigure, s3:publish/unpublish, workspace:new/setup now
animate instead of flat `[tree]`/`[step]` text.

### update animation — ✅ COMMITTED `2a51b636a`
`orbit update` animates the Download binary step (blinking ○/◉) via
StreamedStepTree; honest idle on checkout-unavailable. The reported "no blinking
indicators" is fixed.

### doctor framed panel — ✅ COMMITTED `58417f916`
`DoctorPanelRenderer` renders the documented `D O C T O R RESULT/INTERACTIVE/
RESTORE/ADOPT` framed box: mode title, centered target line, role-derived
category rows with colored status dots, inline per-family issue tables (node =
single ISSUE column), sentence-wrapped node guidance, action tables in
resolution modes, S U M M A R Y + centered prose. In-progress keeps the animated
probe tree (SSE carries no per-category status mid-run); final panel matches the
contract. 17 doctor tests; suite 1428; live-verified `doctor --node=gateway`.
Known gap: report has no "configured-but-empty" signal, so `Skipped, no X
configured` can't be emitted (renders OK) — needs a gateway payload field.

### doc reconciliation — ✅ COMMITTED `91ec4705a`
17 docs aligned (all of D2–D5 + deploy:step-list Symfony→Prompts, schedule/
workspace `—`, empty-state strings, database 6.2 shape, progress-tree.md class +
reference-impl names, in-scope Test Mappings). Docs-linter at pre-existing
baseline (1 prior error on docs/README.md, 0 new findings).
Repo-wide phantom test-mapping cleanup (345/356 cited gateway paths absent)
flagged as a separate pass — `TestMappingFormatRule` mandates a gateway path,
so the consolidation into `apps/cli/tests` needs a coordinated doc + linter-rule
update.

### Rector/Pint — ✅ COMMITTED `95e593f48`
ClosureToArrowFunctionRector (by-value-safe only) + PHP 8.4 new-without-parens.

### Commits on `command-human-render`
`29076882c` lists · `e796b040a` reads+headers · `4cf01d57d` progress infra +
node:remove · `862bf1a4e` mutation wave 1 · `c5f18e7a8` mutation wave 2 ·
`d85189720` streaming animation · `2a51b636a` update animation · `58417f916`
doctor panel · `95e593f48` rector/pint · `91ec4705a` docs.

### Remaining minor (not blocking)
- `update:all` inter-event animation tick (already animates per-event).
- doctor `Skipped, no X configured` needs a gateway payload signal.
- Repo-wide phantom Test Mapping cleanup.

→ Final `composer quality-check` running; merge `command-human-render` → `main`
on green.

### Remaining minor
- `update:all` inter-event animation tick (⚠️ already animates per-event; rows
  don't blink between SSE frames). Low impact.

### Tally
Read surface (24 list + 2 show + 4 header) + ~68 mutations + 11 streaming +
update = **~99 commands** rendering to contract across 7 commits, plus the
shared core progress engine. Compliant-from-the-start commands (version, profile,
node:list, node:show, app:show, app:mount, database:query, schedule:show/logs,
workspace:show/log, dns:list, tool:show, tool:credentials, activity:show,
metrics:disable, deploy:log) were left as-is.
