# Orbit Repo Harness

Orbit is an LLM-first monorepo. The repository harness lives at the monorepo
root. It is the durable surface agents use to discover how to work on this
codebase.

## Scope

This harness governs **repo development only**: how agents plan, implement,
verify, and hand off changes to the Orbit repository. It does not define
customer/product runtime behavior, fleet operations, or how Orbit helps agents
operate customer workspaces. Product contracts live under
`apps/docs/content/`. Product direction changes live in the root
`PRODUCT_DECISIONS.md` intent ledger.

## Harness vs Loop

**Harness** is the durable context map: what good looks like, which files to
read, which skills apply, and which verification lanes exist. It steers agents
without micromanaging every step.

**Loop** is the operational feedback cycle: run work, observe signals
(failures, reviews, drift), triage what went wrong, distill durable guardrails
back into the harness. The loop improves the harness over time.

Feature loops have three top-level outcomes:

- `complete`: the feature is verified and no unresolved blocker remains.
- `blocked`: required evidence or acceptance work cannot be completed and the
  blocker cannot be resolved inside the current slice.
- `complete + loop improvement`: the feature is verified, and a real recurring
  or costly process lesson was promoted into a durable guardrail.

Candidate classifications such as `promote`, `already-covered`, `reject`, and
`defer` are supporting detail for the final distillation. They do not replace
the feature-loop outcome.

## Non-Goals

The root harness is intentionally incremental. Not in scope yet:

- Autonomous merge or reviewer-agent automation
- Customer/product harness (fleet/workspace agent docs)
- Unattended or scheduled continuous session mining
- Reviewer-persona framework beyond the focused personas justified by real
  feature-loop signals

`LOOP.md.example`, `.orbit/loop.md`, `.orbit/sessions/`,
`.orbit/quality-gates/`, `.orbit/evidence/`, and `HARNESS_SIGNALS.md` define
the manual feedback-loop layer. Human-invoked batch loop review is sanctioned
through `.agents/skills/loop-review/SKILL.md`: it reads the session index and
selected archives, then writes findings for human adjudication through the
existing `HARNESS_SIGNALS.md` promotion gate. It does not edit guardrails,
skills, signal records, product docs, tests, or tooling.

The harness deliberately does not build these aggregate-analysis variants:

- A/B infrastructure: Orbit does not have enough comparable loop traffic, and
  observe-mode before/after comparison is enough for current harness changes.
- Embedding or clustering pipelines: the deterministic session index plus a
  focused LLM read is a better fit at the current archive scale.
- Metrics dashboards: `.orbit/sessions/index.json` plus the loop-review metrics
  section is enough until the manual review proves a stronger need.
- Autonomous guardrail promotion or unattended mining: human launch approval is
  retained, and the poisoned-distillation incident remains the local proof that
  promotion needs human adjudication.

Later slices may add or refine reviewer personas. Any automated loop needs a
new explicit decision after human-invoked batch review proves stable.

## Worktree-Local State

Use root `.orbit/` as the home for repository-development state. Active
`.orbit/` entries are current worktree-local session state and stay ignored;
`.orbit/sessions/` is the committed archive home for completed sessions,
normally in the primary checkout. This is checkout state, not product runtime
state inside app workspaces or nodes.

- `.orbit/loop.md`: current-slice state copied from `LOOP.md.example`.
- `.orbit/quality-gates/`: local timing, analyzer, and triage reports for
  Pest, quality-check, Docker E2E, and Incus E2E gates when those commands are
  explicitly run.
- `.orbit/evidence/`: retained local evidence such as command transcripts,
  PTY summaries, screenshots, or pointers to Solo terminals and topology ids.
- `.orbit/sessions/`: persisted, committed session archives for completed
  active slices or features. The archive home must survive feature worktree
  cleanup and sync across machines; by default it is the primary checkout's
  `.orbit/sessions/` directory. Archives are created before worktree cleanup
  and before rewriting `.orbit/loop.md` for a new slice; the naming rule lives
  in Session Archives below.

Do not commit active `.orbit/` state outside `.orbit/sessions/`: `loop.md`,
`evidence/`, `quality-gates/`, `release-candidates/`, and other worktree-local
entries remain ignored. Commit session archives under `.orbit/sessions/` and
only the durable guardrail that absorbs a recurring signal: harness docs,
skills, review personas, product/testing docs, tests, or a curated
`harness-signals/` record.

### Session Archives

When an active slice or feature loop is complete and its final distillation is
filled, archive the completed active `.orbit/` state before worktree cleanup
and before rewriting `.orbit/loop.md` for the next slice. Copy every active
`.orbit/` entry except `.orbit/sessions/` into the persistent project archive
home, normally the primary checkout's `.orbit/sessions/`. Do not leave the
soon-to-be-removed feature worktree's `.orbit/sessions/` as the only archive
copy.

Archive directories are named `YYYY-MM-DD-HHMMSS-<feature-slug>` in the
checkout's local time, for example `2026-07-01-100305-session-archive`.
Do not use compact timestamps, `T` separators, `Z`, or UTC offsets in archive
directory names.
`bin/orbit-session-archive` generates and enforces the archive name; do not
hand-build archive directories.

A session archive should preserve at minimum:

- `loop.md` (the completed slice's final packet)
- `.orbit/evidence/`
- `.orbit/quality-gates/`
- `agent-sessions/` with the provider session files that backed the loop

Use `bin/orbit-session-archive` to create the session archive. It copies every
active `.orbit/` entry except `.orbit/sessions/`, then writes the archive
pointer back into the active `.orbit/loop.md` under `## Evidence Links` so the
archived and active `loop.md` stay byte-identical. Reruns are idempotent: when
the newest archive for the same slug already matches the active `loop.md`, the
tool refreshes that archive in place (`mode=refreshed`) instead of minting a
duplicate. It exits non-zero on missing source state, invalid archive names,
mismatched existing archives, or copy failures.

Agent-session capture happens at lane close, while the Solo process row is
still alive. Run `bin/orbit-agent-session-capture <solo-process-id>` for each
worker/reviewer/analyzer lane that should be preserved. The capture tool
resolves provider, cwd, and started time from Solo's database, joins provider
session files by the exact `Solo process ID: <id>` marker from the spawned
runtime prompt, and stages artifacts under the ignored active-state directory
`.orbit/agent-sessions/<provider>/<lane-slug>/`. Provider archives include
`manifest.json`, `usage.json`, `messages.jsonl`, and `raw/` copies of the
session files used to derive them. Missing, ambiguous, or unsupported providers
must be represented by an explicit manifest or by the final packet's
`- Agent session capture waivers:` row; do not silently drop them.

When staged captures exist, `bin/orbit-session-archive` copies the staged
`agent-sessions/` tree byte-for-byte and skips archive-time live extraction so
the archive cannot overwrite or duplicate lane-close evidence. Loops without
staged captures still fall back to `bin/orbit-agent-session-archive`, which
writes an aggregate `agent-sessions/manifest.json` and stderr WARNINGs for
missing provider context. See the tools' `--help` output for slug, timestamp,
and destination options.

Archive creation must exclude the existing `.orbit/sessions/` tree so archives
do not recurse into prior session copies.

`harness-signals/` remains curated distilled learning and guardrail history, not
raw session storage. Post-feature analysis and eval construction may inspect
session archives as trace evidence.

## Agent Discovery Path

Start at the monorepo root and read in this order:

1. **`AGENTS.md`**: repo shape, authority chain, verification commands,
   worktree workflow
2. **`AGENT_FAST_PATH.md`**: first five-minute route for request type,
   required skill, worktree/eval route, and verification lane
3. **`HARNESS.md`**: this file; repo harness anchor
4. **`apps/docs/content/generated/monorepo-unit-map.json`**: compact
   machine-readable app/package routing facts for LLM agents; not product
   authority
5. **`LOOP.md.example`**: local loop-state template used by
   `bin/orbit-prepare-worktree` when it seeds `.orbit/loop.md`
6. **`.orbit/loop.md`**: current slice state after worktree preparation; never
   treat absence in a fresh checkout as a product gap
7. **`.orbit/sessions/index.json`**: compact first stop for cross-session
   questions; use it to choose which persisted archives need direct inspection
8. **`HARNESS_SIGNALS.md`**: signal-to-guardrail-target map for the feedback loop
9. **`harness-signals/`**: curated signal records to search for prior
   occurrences, guardrail changes, and recurrence checks; start with
   `harness-signals/index.json` when present, then open matching records; not
   raw session archives under `.orbit/sessions/`
10. **`.agents/skills/`**: domain procedures activated just-in-time per change
   type
11. **`.agents/review-personas/`**: focused review checklists activated by the
   routing table after implementation evidence exists
12. **`PRODUCT_DECISIONS.md`**: dated product intent ledger for direction
   changes and reversals
13. **`apps/docs/content/`**: product authority (behavior contracts, not
   repo-dev procedures)
14. **`bin/orbit-prepare-worktree`**: create and bootstrap isolated
   implementation worktrees
15. **Root Composer scripts**: orchestrate docs-lint, tests, Mago, Rector, and
   E2E lanes across apps/packages

## Search Hygiene

Searches should follow the same routing discipline as implementation work:
start broad only across the current checkout's tracked source surface, then
narrow to the owning app/package or generated index that answers the question.

Use default `rg` from the repository root for normal discovery. It respects the
repo ignore rules that keep stale worktrees, active `.orbit/` state, vendor
trees, build outputs, app storage, caches, and retained artifacts out of the
ordinary agent search path. For cross-session questions, start with
`.orbit/sessions/index.json`; tracked `.orbit/sessions/` archives remain trace
evidence and should be opened only when named by the packet, prompt, review
route, or index facets. Prefer scoped searches once the owner is known:

```bash
rg -n "<pattern>" AGENTS.md HARNESS.md apps/docs/content .agents/skills
rg -n "<pattern>" apps/cli packages/sdk
```

Avoid `find .`, `rg -uu`, `rg --hidden --no-ignore`, broad `**/*` globbing,
and unrestricted hidden-file scans from the repository root unless the task
explicitly needs ignored or generated files. Those commands can traverse stale
feature worktrees and historical artifacts, which wastes tokens and can point
agents at code that is not part of the active checkout.

When ignored files are genuinely part of the question, name both the owned path
and the exclusions explicitly:

```bash
rg --hidden --glob '!/.worktrees/**' --glob '!/.orbit/**' \
  --glob '!vendor/**' --glob '!node_modules/**' "<pattern>" <owned-path>
```

Generated LLM-facing artifacts are allowed when they are the intended route:
`apps/docs/content/generated/command-catalog.json`,
`apps/docs/content/generated/monorepo-unit-map.json`, and
`harness-signals/index.json`, and `.orbit/sessions/index.json`. Open them
deliberately instead of letting a catch-all search mix generated contracts with
source code.

Session plans and specs stay at `docs/superpowers/`. They are not product
authority and are not the durable harness.

## Post-Feature Session Review

Before final reporting and merge-back, the orchestrator reviews the feature
thread, Solo worker sessions, reviewer output, retained terminal or PTY
evidence when applicable, verification output, and human corrections.

For non-trivial feature loops, `.orbit/loop.md` is the canonical local final
packet. It should point to evidence artifacts rather than duplicate everything:
objective, final diff or commit, Solo process ids or summaries, reviewer
findings, verification output, human corrections, and the orchestrator's
factual steering notes. Scratchpads, reviewer output, and final reports point
back to `.orbit/loop.md` instead of replacing it. Do not commit the packet.

Add candidate signals to `.orbit/loop.md` as they appear. The final review
should classify an already-collected packet, not reconstruct the session from
scattered artifacts after the fact.

Solo process cleanup is serialized evidence work: capture required output or
summary evidence, verify the artifact exists and is non-empty or record why no
output is expected, then stop or delete the process in a separate command. Do
not run output capture and process deletion in parallel.

Run a fresh-context post-feature analyzer from that packet when the feature had
implementation workers, reviewer corrections, retained terminal/PTY evidence,
quality-gate artifacts, human steering, or guardrail decisions. Use
`.agents/review-personas/post-feature-analyzer.md` as a Solo-managed Codex
analyzer. The analyzer reviews the orchestrator/Solo
session messages and worktree artifacts, then reports whether the loop was
performed properly and whether guardrails were missed, redundant, correctly
omitted, or aimed at the wrong target. It does not edit code, update the
harness, or decide completion.

The orchestrator adjudicates the reviewer recommendations using session
context. Start by eliminating non-signals: one-off handoffs, lessons already
covered by current project guidance or enforcement, reviewer findings fixed
before merge, stale historical artifacts, and ordinary feature work. Distill
only durable repeated or costly mistakes into the smallest appropriate
guardrail target: `HARNESS.md`, `AGENTS.md`, `.agents/skills/*`,
`.agents/review-personas/*`, `harness-signals/`, deterministic tests or static
checks, command failure messages, or explicit rejection.

Before adding a new guardrail, check whether the lesson has already landed in
the current project as code, tests, docs, skills, signal records, or clearer
failure messages. If a later slice already absorbed the lesson, classify the
candidate as `already-covered` and name the existing coverage instead of
creating duplicate guidance.

Feature completion and loop improvement are separate decisions. No durable
signal is a valid `complete` result when the feature is verified and the final
distillation records why no new signal remains. Every final review reports the
loop outcome, evidence reviewed, accepted durable updates, rejected or
already-covered candidates, deferred follow-ups, and the no-new-signal
rationale when nothing changes.

## Merge Boundary Gate

This section is the authority for feature merge and cleanup boundaries. Other
instructions should point here instead of restating this policy.

`bin/orbit-feature-finalization-check` is the executable gate. The Codex and
Claude Code `PreToolUse` hooks call the same gate when those hook surfaces
intercept a boundary command, but hook status is diagnostic only. Use the helper
directly before real merge or cleanup boundaries; run it with no arguments for
current command usage. Explicit invocations that cannot be classified as a
merge/cleanup boundary exit 64 with a message instead of silently passing.

Validate the packet early with the dry-run:
`bin/orbit-feature-finalization-check --lint [path-to-loop.md]` (default
`./.orbit/loop.md`) checks only the Final Distillation packet shape — no git
action, artifacts, or diff needed — prints every PASS/WARNING/BLOCKED finding,
and exits 0 or 2. Use it as a first checkpoint while filling `.orbit/loop.md`,
not only at the boundary. It also flags a `complete` outcome combined with a
blocked verification row.

On every classified merge/cleanup boundary the gate prints
`FINALIZATION: PASS <action>` on stdout, or `FINALIZATION: BLOCKED <first
failing check>` as the first stderr line with the offending line echoed.
Unrelated commands stay silent and exit 0.

Cleanup commands are valid only after the post-feature signal audit is complete
or the user explicitly approves cleanup. Until then, leave the completed
feature worktree and branch intact.

The gate is intentionally narrow: it only inspects git merge and
feature-cleanup boundaries. It blocks when:

- the targeted feature worktree has no completed `.orbit/loop.md`
  `Final Distillation` section;
- the loop outcome is not exactly `complete`, `blocked`, or
  `complete + loop improvement` (trimmed; surrounding backticks allowed); only
  `complete` and `complete + loop improvement` can pass a merge/cleanup
  boundary, `blocked` cannot;
- the loop outcome, either `Required verification` row, or a signal-outcome
  row contains placeholder text such as `pending`, `tbd`, `todo`, `not yet`,
  or an unexpanded `<angle-bracket-template>`;
- required verification rows are missing, or still recorded as blocked,
  pending, skipped, missing, deferred, unresolved, or not run;
- on merge, the feature branch tip equals the merge base — there are no
  commits to merge;
- on merge, the feature worktree (found via `git worktree list`) has
  uncommitted tracked changes;
- on cleanup, `git worktree remove` or `git branch -d`/`-D` runs without a
  primary-checkout `.orbit/sessions/` archive named
  `YYYY-MM-DD-HHMMSS-<slug>` that contains `loop.md` and agent-session
  manifests from staged lane-close capture or fallback extraction; create it
  with `bin/orbit-session-archive`;
- the packet names worker/reviewer/analyzer lanes with Solo process ids, but
  active or archived agent-session manifests contain zero `status: ok`
  captures and the packet does not include an explicit
  `- Agent session capture waivers:` row naming the missing or unsupported
  providers.

It derives the required proof from the branch diff and reads existing
`.orbit/quality-gates/` artifacts instead of rerunning gates.

The mechanical contract is label-based. Keep the exact Markdown bullet-label
lines from `LOOP.md.example`: `- Loop outcome:`, `- Required verification:`,
`- Fresh analyzer:`, `- Accepted durable updates:`,
`- Rejected or already-covered signals:`, `- Deferred follow-ups:`, and
`- No-new-signal rationale:`. At least one of the
signal-outcome labels must contain a meaningful outcome before merge or cleanup.
Custom headings, bare label lines without `- ` and `:`, or equivalent prose can
support the explanation, but they do not satisfy the gate by themselves. Both
the compact default packet and `Appendix: Full Multi-Slice Variant` in
`LOOP.md.example` keep every mechanical label and pass the same `--lint` check.

Merge packets must include a `- Fresh analyzer:` row; `deferred - <reason>` is
accepted as a non-blocking WARNING so analyzer infrastructure failures are
recorded honestly. The gate also warns without blocking when
`Candidate Signals While Working` is empty or `none` while accepted durable
updates are non-none — that shape suggests signals were reconstructed post-hoc
instead of collected during the loop.

Required verification rows use the status-first shape and must include the
topology-proof row and `composer quality-check`:
`- Retained topology proof: passed | blocked | not applicable - <evidence or reason>`.
If the feature required a lane and it is blocked, the feature outcome is
`blocked`; do not write `complete` with a deferred verification follow-up.
Docs-class diffs — `*.md` anywhere including root level, plus non-PHP files
under `.agents/**`, `docs/**`, `harness-signals/**`, and `LOOP.md.example` —
can satisfy the gate with a successful `composer docs-lint` or broader
`composer quality-check` artifact. Any other diff requires successful
`composer quality-check` artifact evidence. Topology-relevant PHP diffs —
non-test PHP outside `apps/docs/` —
additionally require retained topology proof to be `passed`; docs-app tooling
PHP under `apps/docs/` is excluded unless the slice also changes topology
behavior. Native Orbit Agent macOS UI diffs — non-Markdown files under `apps/macos/` —
resolve live topology to the implementing macOS host, not to retained Incus. On
Darwin, the row must be `passed` with `host-macos` evidence naming `host=`,
`os=`, `command=`, and `evidence=`. On non-Darwin, the gate blocks and the work
must move to a Mac implementation host instead of substituting a retained
topology. The only allowed non-passed topology row for topology-relevant PHP is
a user-approved release lane where the proof cannot run until a main-based RC
artifact is built and deployed. That row must be `not applicable`, must name the
release acceptance lane, and must name the post-merge proof commands such as
`orbit update:all` and the live Solo `--node=` checks; keep the release goal
active until that live proof passes. A passed retained topology row names the
topology id/kind, checkout roles or inspected nodes, exact command, and captured
terminal/session or artifact evidence; a passed host-Mac row names
`host topology kind=host-macos`, the host identity, OS version, exact command,
and Computer Use or terminal evidence. Stale-commit and
timing-threshold warnings remain the job of
`composer quality-gate:final-check` and the quality-gate triage skill.

The gate exists because feature agents repeatedly completed work, merged to
`main`, and cleaned up the worktree while leaving `.orbit/` evidence and
feature-session learnings undistilled. It does not run tests, inspect ordinary
commands, mine old sessions, or promote signals automatically.

If the gate blocks, do not delete `.orbit/` or bypass the merge. Review the
feature evidence, classify candidate learnings through `HARNESS_SIGNALS.md`,
fill the final-distillation outcomes in `.orbit/loop.md`, rerun the helper, and
then rerun the same git command.
For a genuinely tiny local change, the final-distillation section can record
the no-review/no-new-signal rationale explicitly.

Historical worktrees that only contain gitignored `.orbit/` evidence are
cleanup targets, not automatic harness-improvement sources. If the useful
lesson already landed elsewhere, fill or report the final distillation as
`already-covered` or `no-new-signal` instead of promoting another guardrail.
Regular loop improvement comes from the active feature's evidence. Broad
history or worktree mining is a separate explicitly requested workflow, not the
default finalization path.

## Post-Feature Signal Audit

Normal feature work runs without a standing watcher by default. An explicitly
requested loop-observer lane may run during a loop; see
`.agents/skills/loop-observer/SKILL.md`. It runs in `observe` mode by default
without steering the run, or in opt-in `coach` mode with logged,
non-authoritative process-rubric corrections only. Kick off the feature
implementer through the implementation workflow, let it complete the feature
loop, and preserve the worktree and `.orbit/` artifacts. The fresh post-feature
analyzer runs before merge as part of final distillation — the merge packet's
`- Fresh analyzer:` row records the result — and signal-audit adjudication
completes before cleanup.

The analyzer is read-only. It inspects the feature orchestrator/Solo session
messages, active `.orbit/loop.md`, `.orbit/evidence/`,
`.orbit/quality-gates/`, persisted `.orbit/sessions/` archives when present,
Solo scratchpads, worker and reviewer reports, retained terminal or PTY
evidence, verification output, human corrections, and the final diff or commit.
It reports whether the loop was proper, flawed, or blocked by missing evidence.
The analyzer may use `.orbit/sessions/` archives as trace evidence when they
exist.

The analyzer checks guardrail decisions instead of supervising live work:

- `correct-noop`: no durable guardrail was needed, and the evidence supports
  that result.
- `missed`: a durable guardrail should have been added or tightened.
- `redundant`: a guardrail was added even though existing guidance or
  enforcement already covered it.
- `wrong-target`: a real signal was promoted, but the target is too broad,
  undiscoverable, or not verifiable.
- `defer`: the concern may be real, but evidence, ownership, or recurrence risk
  is not clear enough yet.

The feature owner or human adjudicates the analyzer report. Patch Orbit only
when the report identifies a concrete recurring or costly signal, the smallest
guardrail target is clear, and the verification for that target is reachable.
If the report finds only local cleanup, existing coverage, stale artifacts, or
ordinary feature work, record the no-new-signal rationale and do not add a new
rule.

## Feature Slices

Use the least durable state that can keep the work coherent.

- A small request can start directly in one feature worktree with one
  `.orbit/loop.md`.
- `bin/orbit-prepare-worktree` seeds `.orbit/loop.md` from
  `LOOP.md.example` when the packet is missing. The handoff owner or feature
  orchestrator enriches that seeded packet with the source context, current
  slice, Done Contract, and scratchpad pointer before worker dispatch.
- A request that is too large for one implementation slice gets one lightweight
  Solo scratchpad. The scratchpad records feature intent, rough slice order,
  slice outcomes, open decisions, and the final verification gate. It is not a
  full spec and not a command log.
- Scratchpad creation is a pre-dispatch gate for multi-slice features. Create
  or identify the feature roadmap before preparing the implementation worktree
  or spawning workers, then put its `solo://` URL at the top of `.orbit/loop.md`
  and in worker prompts. If the work executes in a different Solo project or
  machine from the source scratchpad, create a reachable execution-project
  roadmap that links back to the source scratchpad and carries the source
  roadmap substance: feature request, slice order, current-slice acceptance
  criteria, deferred slices, and open decisions. A link-only execution
  scratchpad is not enough because local workers and reviewers may not be able
  to read the source project.
- Solo todos are optional assignment cards. Create them only when a slice needs
  asynchronous delegation, queueing, or explicit tracking outside the active
  orchestrator thread. If a todo exists, keep it thin: point to the scratchpad
  and name the slice instead of copying the whole loop contract.

One feature maps to one implementation worktree by default. Build related
slices in that same worktree and merge to `main` only after the whole feature
passes the feature-level final gate, including retained topology proof when the
diff requires topology evidence. Do not
merge internal slices independently unless a slice is explicitly split into a
separate feature with its own final gate.

Within a feature worktree, `.orbit/loop.md` is the current-slice contract, not
the feature history. Before rewriting it for the next slice, archive the
completed active `.orbit/` state with `bin/orbit-session-archive` (see Session
Archives for the archive home and naming rule). Keep prior slice
outcomes in the feature scratchpad, session archives, and the actual code
history in Git. The top of `.orbit/loop.md` should name the feature scratchpad,
summarize completed slices in one line each, and identify the current slice so a
worker knows the branch may already contain earlier feature work.

If a multi-slice feature reaches worker dispatch without a feature roadmap
scratchpad link, or with only a thin cross-project link that does not mirror the
roadmap substance into the execution project, pause the feature loop and fix the
scratchpad before continuing. Classify the miss in `.orbit/loop.md` final
distillation and update `harness-signals/` only when existing guidance did not
make the gate clear.

### Loop Packet Escalation

`LOOP.md.example` leads with the compact single-slice packet because most
feature loops should not carry multi-slice ceremony by default. Escalate to the
full multi-slice packet in `LOOP.md.example` when the slice has any of these
properties: multi-slice feature, parallel workers, topology-relevant diff,
product-contract change, or release scope.

Escalated loops use the full packet, the reviewer persona selected by the
`Root Routing` table, and a fresh post-feature analyzer before merge. Otherwise,
use the compact packet, the reviewer selected by the routing table when that
table requires one, and record the no-analysis rationale in the final packet
when skipping the analyzer.

Packet tiering reduces coordination ceremony only. It never tiers down TDD,
the verification evidence required by the final branch diff, retained topology
proof when required, or session archive requirements.

## E2E Readiness And Resource Pool

This section is for reading shared E2E provider state and existing artifacts
only. Agents must not run `composer test:e2e*` commands. Normal feature
completion uses retained topology proof instead of prepared E2E.

The E2E provider pools are shared across every Orbit worktree on this machine.
Worktrees symlink `.env.e2e` back to the main checkout, so provider selection,
runner capacity, lease directories, and host pools are shared state. Read the
active providers from `.env.e2e`, not from hard-coded hostnames: Docker feature
tests use `ORBIT_E2E_DOCKER_TEST_RUNNERS`, and Incus preparation/topology work
uses the configured Incus host variables such as `ORBIT_E2E_INCUS_HOSTS` and
`ORBIT_E2E_INCUS_HOST_SLOTS`.

Active `orbit-e2e` containers, networks, volumes, VMs, or lease files on those
configured providers are normal while other features run in other worktrees.
Resource existence or container count alone is not a stale-resource signal.

When inspecting a user-run E2E lane in a worktree:

1. `composer e2e:preflight` — verifies the configured Incus host is
   reachable. This is a manual diagnostic command; do not run it as an agent
   unless the task is retained topology setup rather than E2E testing.

2. Treat the provider pool as a blocking lease pool. If the user reports a long
   wait at startup, it usually means every configured slot is currently leased
   by another worktree. Do not reap merely because resources exist on a
   provider.

3. Use host diagnostics only after a wait exceeds
   `ORBIT_E2E_SLOT_WAIT_SECONDS`, or when recovering from an interrupted run.
   Derive Docker hosts from `.env.e2e`:

   ```bash
   set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a

   printf '%s\n' "${ORBIT_E2E_DOCKER_TEST_RUNNERS:-}" \
     | tr ',' '\n' \
     | while IFS=: read -r host slots container_cap; do
         [ -n "$host" ] || continue

         if [ "$host" = "local" ]; then
           docker ps \
             --filter "name=orbit-e2e" \
             --format '{{.Names}}\t{{.Status}}'
         else
           DOCKER_HOST="ssh://$host" docker ps \
             --filter "name=orbit-e2e" \
             --format '{{.Names}}\t{{.Status}}'
         fi
       done
   ```

   Interpret output as context only. The resources may belong to live runs from
   other worktrees.

4. If the shared pool is busy, report that the user-run command can wait or
   reduce only that command's temporary demand. For Docker, the user can pass a
   smaller `ORBIT_E2E_DOCKER_TEST_RUNNERS` value or
   `ORBIT_E2E_PARALLEL_PROCESSES=<n>` in the command environment. Do not commit
   or permanently edit `.env.e2e` just to make one worktree smaller; that file
   controls every worktree.

5. Reaping is destructive recovery, not pre-run hygiene. Use the dry run only
   as inventory, and remember that its output can include legitimate resources
   from other worktrees:

   ```bash
   composer e2e:reap-docker
   composer e2e:reap-incus
   ```

   Run with `--force` only after confirming the resources belong to an
   interrupted run you own, or after confirming no live E2E process can be using
   the configured providers:

   ```bash
   composer e2e:reap-docker -- --force
   ```

   Do not use `--older-than=0m` on shared providers unless intentionally
   deleting retained or abandoned resources you own.

The slot pool is a blocking lease pool: workers that cannot lease a slot
wait, they do not fail loudly. A long hang at the start of `composer
test:e2e:docker` or `composer test:e2e:incus` usually means the configured
pool is fully booked by another worktree, not a real failure.

## Retained Incus Inspection Gate

Retained Incus is the mandatory hands-on verification step whenever the change
touches real VM/node behavior: OS package installs, role baselines, doctor
restore, tool repair, SSH/sudo, WireGuard, systemd, host mutation, gateway API
shims, source-mounted checkout behavior, or anything that previously failed
only inside an Incus topology.

Any feature that creates or changes a CLI command must also pass a retained
ingress VM Solo-terminal gate before live/release-candidate deployment. This
includes new commands, flags, options, arguments, human output, JSON schemas,
validation, prompts, command side effects, and command-family behavior.
CLI retained topology proof must run in a Solo terminal, not only through a
detached host command or captured artifact. Keep that Solo terminal open
through feature completion and leave it available afterward. The preserved
terminal lets the user later validate the addressed CLI commands and their output.
The retained topology may be reaped after feature completion, but the Solo
terminal stays preserved as the validation anchor.

For CLI changes, use this ordering:

1. Create or update focused Pest tests for the command contract.
2. Implement the smallest slice that makes the focused command behavior work.
3. Spawn or request a Solo terminal.
4. Acquire a retained Incus topology with the relevant source-mounted VM.
5. Verify which Orbit launcher will be exercised. For source-mounted retained
   topology proof, run the command through the source checkout
   (`./apps/cli/orbit` from `/home/orbit/orbit-run`) or prove that
   `/usr/local/bin/orbit` resolves to that source checkout. For
   release-candidate or live-node proof, use the installed binary path being
   validated.
6. Open an interactive shell inside the relevant retained VM, usually the
   ingress or operator VM, before running the changed command. The Solo
   terminal should land at `/home/orbit/orbit-run` inside the VM so the user can
   watch progress, spinners, blinking indicators, prompts, and streaming output
   while the command runs.
7. Run or request CLI reviewer PTY frame analysis from the same runtime context
   before asking the user to inspect human UX/output. For retained Incus proof,
   prefer running the capture script inside the Solo terminal shell attached to
   the target VM. The reviewer inspects `summary.txt`, `chunks.jsonl`, and
   `transcript.txt` for cadence, liveness, skipped frames, wrapping, ANSI
   framing, and final shape.
8. Prove the observed human output, JSON output, prompts, failure paths, and
   side effects that changed.
9. Give the user a chance to inspect and confirm the VM-observed CLI behavior
    only after the reviewer has summarized the PTY confidence basis or exact
    remaining mismatch.
10. Then run broader quality or release-candidate flow when applicable.

Do not spend the live topology or release-candidate path on a CLI change before
this retained VM proof and user verification point. Do not treat retained Incus
as optional "extra confidence" for those changes. The retained check is the
operator-facing proof gate for feature completion.

Acquire the topology from the implementation worktree and source-mount only the
roles that need the current checkout:

```bash
composer e2e:incus -- --start --topology=<kind> --checkout-roles=<roles> --json
```

For CLI command changes, use the topology that creates a dedicated ingress VM.
Source-mount the ingress role, plus only the other roles whose current checkout
is needed for the command under test:

```bash
composer e2e:incus -- --start \
  --topology=operator_gateway_app-dev_app-prod_ingress \
  --checkout-roles=ingress \
  --json
```

Identify the target instance from the retained topology output or manifest, then
open that retained VM in a Solo terminal and stop at a VM shell prompt before
starting the command. Run the exact changed command path from
`/home/orbit/orbit-run`, covering human and `--json` output when either contract
is affected, plus the relevant failure or prompt path. Record the launcher proof
(`command -v orbit`, `readlink -f`, or the explicit `./apps/cli/orbit` source
launcher command) next to the observed output.

A host-wrapped one-shot command such as
`ssh <host> 'incus exec <instance> -- ... <orbit command>'` is useful for
machine transcripts, JSON capture, or fallback diagnosis, but it does not
satisfy the retained Solo-terminal inspection gate for human rendering. The
gate is only satisfied when the Solo terminal is attached inside the VM before
the human command starts. If the current Solo environment cannot open that
interactive VM shell, use the configured Incus host with `incus exec` as a
fallback and report that the user-inspection gate was downgraded.

Inspect through the configured Incus host from `.env.e2e`; do not assume a
host name unless the environment says so. Typical Beast-backed inspection looks
like:

```bash
ssh beast 'incus list --format csv -c ns | grep <retained-id> || true'
ssh -tt beast 'incus exec <instance> -- sudo -iu orbit bash -lc "cd /home/orbit/orbit-run && exec bash -i"'
# then run inside the VM shell:
./apps/cli/orbit <command>
ssh beast 'incus exec <instance> -- bash -lc "<host command such as gh --version>"'
```

For source-mounted Incus topologies, `/home/orbit/orbit` is the synced source
mount and `/home/orbit/orbit-run` is the VM-local runtime mirror. Run Orbit
commands from `/home/orbit/orbit-run` unless explicitly testing the source
mount itself. After local worktree edits, refresh a retained topology with:

```bash
composer e2e:incus -- --sync --id=<id>
```

That sync is one-way from the implementation worktree to the runner-host source
mount and then to each recorded VM runtime mirror. It transfers filesystem
deltas for included files to the runner host, not only Git-dirty files, and then
refreshes the VM-local runtime mirrors. Keep the implementation worktree as the
source of truth. VM-side edits in `/home/orbit/orbit-run` are scratch work and
are overwritten by the next sync; VM-side edits in `/home/orbit/orbit` mutate
the runner-host copy, not the local worktree.

`--sync` refreshes files on disk but does not reload the long-running gateway
API container. After a sync, the gateway lease container keeps serving the
pre-sync code and returns HTTP 500 on every gateway call (including paths the
change never touched, e.g. single-node `doctor`), which reads as a product
regression but is not one. Restart the gateway lease containers on the gateway
VM before re-verifying:

```bash
ssh <incus-host> 'incus exec <gateway-instance> -- bash -lc \
  "docker restart orbit-gateway-e2e-topology-lease-http orbit-gateway-e2e-topology-lease-tls"'
```

Then re-run the changed command. A 500 on an unrelated gateway path right after
`--sync` is a stale-container signal, not a code defect — restart first, then
classify.

Record the retained topology id, topology kind, checkout roles, inspected
instances, Solo terminal or fallback session, commands run, and observed result
in the Solo report. If you mutate state for a focused check, isolate unrelated
prepared-state drift first and say what was isolated.

After feature completion, release and verify retained topology cleanup:

```bash
composer e2e:incus -- --stop --id=<id> --json
ssh <incus-host> 'incus list --format csv -c ns | grep <id> || true'
```

If `--stop` misses owned instances because the retained manifest is incomplete,
delete only the owned leftovers by exact instance name, verify the grep is
empty, and report the cleanup anomaly. Do not close or archive the Solo terminal
as part of topology cleanup; preserve it for later user validation of the
command address/output transcript.

## Feature Cleanup

Merge and cleanup are separate boundaries.

- Merge happens after the feature branch is committed, verified, final
  distillation is filled — including the fresh post-feature analyzer, which
  runs before merge as part of final distillation — and the merge boundary
  gate passes. Leave the completed feature worktree and branch intact after
  merge by default.
- Signal-audit adjudication completes before cleanup, while the worktree is
  still available. Review `.orbit/loop.md`, `.orbit/evidence/`,
  `.orbit/quality-gates/`, Solo scratchpads, reviewer output, and retained
  terminal or PTY artifacts. Confirm accepted, rejected, already-covered, and
  deferred signals were processed and that no harness signal was lost.
- Worktree cleanup happens only after that audit is complete or the user
  explicitly approves cleanup. Archive the completed active `.orbit/` session
  with `bin/orbit-session-archive` before cleanup (see Session Archives for the
  archive home and naming rule; the merge boundary gate requires the archive
  before `git worktree remove` or `git branch -d`). Follow the Merge Boundary
  Gate above before running cleanup.
- Feature completion cleanup happens only after the user confirms the live
  topology works as expected, or explicitly says the feature can be considered
  complete. Then archive the feature scratchpad, close or resolve related Solo
  todos, and stand down related Solo agents or retained terminals.

Keep cleanup scoped to the feature. Do not archive unrelated scratchpads, close
unrelated todos, or stop unrelated agents just because they are in the same Solo
project. Before archiving Solo state, make sure the scratchpad or todo records
the merge commit, final verification, live/user acceptance when applicable, and
any preserved follow-up. If a worktree, scratchpad, todo, or agent remains open
for post-analysis, report the reason and owner.

## Parallelization Gate

Before executing a goal, feature, or quality-gate tuning pass, the orchestrator
must decide what can run in parallel. The default is parallel dispatch for
independent slices. Serial execution is a justified exception, not the default
shape.

Being part of one goal, feature, or harness-improvement effort is not a
dependency. A slice is serial only when it needs another slice's result, edits
the same owned files, mutates the same provider or temp state, exceeds provider
capacity, or has an unavoidable merge-order constraint.

Record the decision in `.orbit/loop.md`, the feature scratchpad, or the worker
plan before workers start:

- candidate slices or lanes
- owned files and domains
- shared provider resources
- shared temp or local state paths
- dependencies on another lane's result
- merge-order constraints
- lanes intentionally deferred, with the concrete reason and owner

For quality-gate optimization, split in-memory/Pest and `composer quality-check`
work into separate lanes by default. Do not spawn Docker or Incus E2E workers;
E2E artifacts are read-only evidence unless the user explicitly runs a
`composer test:e2e*` command from a shell. Do not overlap aggregate
`composer quality-check` with active user-run provider E2E unless the shared
E2E support state is proven isolated; run that aggregate gate after the
user-run provider command is idle.

## Solo Role Matrix

Solo is the worker substrate for Orbit repo development. Use it to split work
only when ownership can stay clear.

| Role | Default Agent | Owns | Does Not Own |
|------|---------------|------|--------------|
| Feature orchestrator | Solo-managed Codex is the usual default | Done Contract, worktree, worker prompts, scope control, review, verification, final report, next step | Blind implementation or accepting worker output without inspection |
| Implementation worker | Solo-managed worker; Grok is the usual default | Bounded PHP, CLI, Pest, E2E, and app/package code slices | Final commit, merge-back, release, broad refactors, unrelated dirty files |
| Documenter / librarian worker | Solo-managed Codex | Documentation contracts, command docs, docs-first handoffs, focused docs drift analysis | Final product decision, code implementation, broad audit unless requested |
| CLI verifier | Codex or another smart model | PTY capture, retained VM command proof, JSON/human output evidence | Product redefinition or release approval |
| Code / CLI reviewer persona | Solo-managed Antigravity reviewer | Focused code or CLI review from the assigned persona, changed diff, implementation report, and evidence | Implementation, product redefinition, merge approval, cleanup, or final promotion decisions |
| Post-feature analyzer | Solo-managed Codex analyzer | Read-only review of orchestrator/Solo session messages, `.orbit` artifacts, verification evidence, final diff, and guardrail decisions | Live steering, implementation, harness edits, merge approval, cleanup, or final promotion decisions |
| Loop observer / loop coach | Solo-managed observer; explicit request only, never a default lane; `observe` is default and `coach` is opt-in per invocation | Live observation of an active loop per `.agents/skills/loop-observer`: wrong turns, friction, and timing notes in observe mode, or logged non-authoritative process-rubric corrections in coach mode | Product/design/code suggestions, completion decisions, automation/triggers, implementation, reviews, merge approval, cleanup, or spawning workers |
| Overflow lane | `mini` through Solo/SSH | Independent feature, review, verification, or investigation work | Shared mutable state, generic E2E host assumptions, uncoordinated merge authority |

The active feature-owner thread is the source of work. It can start in Codex
CLI, the Codex app, or another capable LLM surface, but the default
long-running Solo feature orchestrator is Codex. Discover the enabled
`Codex` tool with `list_agent_tools`, then `spawn_agent`. If Codex is not
available through Solo, stop and report the blocker instead of substituting
another model.
Spawned workers and retained verification terminals run through Solo so
ownership, process ids, and terminal proof remain inspectable. Workers receive
the active Done Contract, worktree path, owned files or domains, stop and pivot
conditions, and reporting shape. Solo `spawn_agent` and `spawn_process` have no
cwd parameter and spawned lanes open at the project root, so pin the working
directory at launch: pass the assigned worktree through `extra_args` using the
agent CLI's working-directory flag — Codex lanes use
`["--cd", "<absolute worktree path>"]`, Grok lanes use
`["--cwd", "<absolute worktree path>"]`. For agent CLIs without a
working-directory flag — Claude has none, and Antigravity (`agy`) has none
(`--add-dir` only widens workspace scope and does not set a working root) —
spawn a Solo terminal that first `cd`s into the worktree and launch the agent
inside it. Before relying on any other CLI's working-directory flag, confirm it
in that CLI's `--help` output. Launch-time pinning is verification input, not a
substitute for it: worktree-scoped Solo workers must still confirm `pwd` and
`git branch --show-current` before broad reads or edits; if the spawned agent
still opens at the project root, relaunch through a Solo terminal that first
`cd`s into the worktree. If those boundaries are hard to state, use one worker
serially instead of parallel workers.

Post-feature analyzers use the enabled `Codex` tool through Solo. Discover it
with `list_agent_tools`, then `spawn_agent`. If Codex is not available through
Solo, stop and report the blocker instead of substituting another model. Code
and CLI reviewer personas use the enabled Antigravity tool
through Solo. Because Antigravity provider-session archiving is unsupported
until a reliable session-file contract exists, the feature owner must preserve
the reviewer report itself as the evidence artifact. This agent selection does
not widen the persona's role boundary.

Before execution, use the parallelization gate above. A serial plan for isolated
goals, slices, or lanes is incomplete unless it names the concrete dependency,
shared state, provider capacity limit, or merge-order reason. If two tasks have
disjoint ownership and neither needs the other's result, dispatch them in
parallel through Solo by default. Serialize only when tasks edit the same files,
mutate the same provider resources, depend on a prior result, or cannot name a
clear merge order. In parallel-worker mode, workers must also scope formatters
and fixers to their owned files; broad Mago formatting, broad Rector, or
aggregate fixers belong to the feature owner after worker diffs are reconciled.

Documentation-heavy work may start with a Codex documenter/librarian worker.
Code implementation can run after the feature owner accepts the docs contract as
stable enough. Docs and code may proceed in parallel only when the product
contract is settled, ownership is disjoint, and the feature owner owns
reconciliation before commit.

## Root Routing

Use this table to pick the smallest workflow that can prove the change.

| Surface | Skill | Authority Docs | Test Lane | Reviewer Needed | Loop Depth | Hard Stop |
|---------|-------|----------------|-----------|-----------------|------------|-----------|
| Docs-only | `updating-documentation`; `auditing-docs-drift` only for an explicit consistency scan | `apps/docs/content/**`, `PRODUCT_DECISIONS.md`, or root harness docs depending on scope | `composer docs-lint` when product docs change; otherwise `git diff --check` | `.agents/review-personas/docs-librarian.md` or human if authority changes | Record only repeated drift | Product docs conflict with latest product decision |
| Documentation-heavy feature | `updating-documentation`, `implementing-features`; optional Codex documenter/librarian worker | Product docs, command docs, product-decision ledger, changed tests | Docs contract review, then focused Pest owned by implementation | `.agents/review-personas/docs-librarian.md` before accepting docs contract | Record unclear authority, repeated docs/code mismatch, or docs-worker handoff gaps | Docs contract is unstable, authority conflict needs a decision, or docs/code workers disagree |
| Quality-gate failure or slowdown | `quality-gate-triage`, plus `pest-testing`, `e2e-verification-lanes`, or `cli-output-pty-capture` by lane | `apps/docs/content/testing/README.md`, `quality-gates.md`, `in-memory/performance.md`, `e2e/environment.md`, `e2e/performance.md` | Inspect existing evidence under `.orbit/quality-gates/` and `.orbit/evidence/`; do not rerun expensive gates just to classify | Owner/human only after classification points at product behavior | Record recurring flakes, missing baselines, or confusing lane failures | Aggregate provision command, live-node mutation, or product fix before classification |
| Post-feature analysis | `.agents/review-personas/post-feature-analyzer.md`, then `implementing-features` for orchestrator adjudication | `HARNESS.md`, `HARNESS_SIGNALS.md`, `harness-signals/README.md`, `.orbit/loop.md`, `.orbit/evidence/`, `.orbit/quality-gates/`, orchestrator/Solo session messages, changed diff, and evidence packet | No tests by default; run `git diff --check`, discoverability `rg`, docs-lint when product docs changed | Fresh Solo Codex analyzer report for non-trivial loops; orchestrator owns final decision | Promote only real repeated or costly mistakes with a counterfactual guardrail; reject missed, redundant, or wrong-target guardrails clearly | Guardrail added from weak evidence, no rejected/no-op rationale, analyzer asked to implement, or session/artifacts missing enough evidence to judge |
| CLI command | `command-designer`, `cli-output-pty-capture` when human rendering or cadence matters, `implementing-features` | Command docs under `apps/docs/content/`, command tests, `AGENTS.md` | Focused Pest first; retained topology proof; PTY frame capture and reviewer analysis before human UX review | `.agents/review-personas/cli-command.md` via Solo Antigravity, or human for UX/product contract changes | Search signals, update/create record for repeated command-contract issues | No failing/passing command proof, no retained topology proof when CLI behavior needs it, no PTY frame analysis before human UX review, or live topology would be touched without approval |
| Orbit Agent Rust services | `.agents/skills/tauri-agent-development/SKILL.md`, `implementing-features` | `apps/agent/**`, `apps/macos/**`, `apps/docs/content/architecture.md`, `apps/docs/content/tech-stack.md`, `apps/docs/content/domains/1_node/node-concepts.md` | Focused Cargo checks from `apps/agent` and `apps/macos`; `composer quality-check` for broad handoff; host-Mac topology proof on the implementing Darwin machine for native `apps/macos` diffs; Computer Use for native tray/menu rendering changes | `.agents/review-personas/tauri-agent.md` via Solo Antigravity, or human for native UX/product contract changes | Record repeated native-menu, Cargo-gate, host-Mac proof, or docs/unit-map drift | No current Cargo proof, no `host-macos` topology row for native macOS UI diffs, no Computer Use evidence when native tray rendering changed, non-Darwin implementation host, or the change expands installer/signing/privileged execution scope without approval |
| Gateway API | `implementing-features`, Laravel/PHP skills | `apps/docs/content/**`, gateway routes/controllers/tests | Focused gateway Pest; retained topology proof when behavior crosses node/topology boundaries | API/product reviewer when contract changes | Record repeated API contract or routing mistakes | API docs and implementation disagree, or authorization/security impact is unclear |
| Provisioning/live-node | `implementing-features`; `e2e-verification-lanes` only for existing artifact triage or manual command reference | `apps/docs/content/testing/README.md`, provisioning docs, product decisions | Retained topology inspection, then approved live-node proof | Human before live mutation | Always capture topology/node evidence; record expensive or repeated failures | Provider pool/auth is ambiguous, role target is unclear, or live mutation lacks approval |
| Release | `release` | Release skill, changelog/version files, product docs touched by release | Release gates: doctor before, `update:all`, doctor after, `node:list`, plus exception checks | Human before tag, publish, or merge/push beyond the approved release step | Record release-gate surprises and recurring fleet drift | Any release gate fails or approval boundary is not explicit |
| App/package shared core | `implementing-features`, Laravel/PHP skills | `packages/core/**`, affected app docs/tests | Package tests plus focused impacted app tests; broaden to `composer quality-check` for shared contracts | Owner/reviewer for cross-app behavior | Record boundary leaks or repeated shared-contract misses | Affected apps are unknown, or shared behavior lacks targeted coverage |

## Done Contract

For non-trivial work, the feature orchestrator fills the active slice contract
before implementation. Keep it short enough to copy into `.orbit/loop.md`.
Workers may challenge the contract, but they must not silently weaken scope,
evidence, reviewer checks, stop conditions, or pivot conditions.

When a request includes concrete output samples, command transcripts, UI
examples, or negative examples, the Done Contract keeps those raw examples or a
precise pointer to them. Any decomposition into slices must name which parts of
the raw request are in the current slice, which are deferred, and why deferral
does not invalidate the acceptance contract. A reviewer finding that matches
the original raw request is a contract gap, not an optional enhancement, unless
the feature owner had explicitly deferred it before implementation began.

```markdown
Current slice:

Done when:

Evidence:

Reviewer checks:

Stop if:

Pivot if:
```

`Stop if` means the agent should halt and hand back because continuing would be
unsafe or outside scope. `Pivot if` means the agent can continue, but should
change approach instead of repeatedly patching the same path.

The contract is not ceremony. It defines when the agent should continue, when it
should stop, which approach changes are allowed, and which evidence is enough
for handoff.

## Slice Verification

Validate each slice with the narrowest checks that keep the feature branch
honest: focused Pest, docs-lint, static checks, or PTY proof when the slice
changes terminal behavior. Do not spend E2E on feature slices by default. The
finalization gate derives the feature-level proof from the final branch diff:
docs-only changes need docs-lint evidence, non-docs changes need quality-check
evidence, topology-relevant PHP changes need retained topology proof, and native
Orbit Agent macOS UI changes need host-Mac topology proof from the implementing
Darwin machine. Run the matching topology proof when the active slice cannot be
judged without real topology behavior.

When topology proof is required for acceptance and cannot be completed,
the feature loop halts if the blocker cannot be resolved inside the current
slice. Do not finalize, merge, clean up, or mine final loop improvements while
required topology proof is still blocked. Record the exact blocker,
owner, and unblock condition in `.orbit/loop.md` under `Required verification`, set the loop
outcome to `blocked`, then hand back unresolved work.

Treat this as the `blocked` feature-loop outcome, not as a candidate learning.
It becomes a loop-improvement signal only when the reason for the topology
proof block reveals a recurring process gap.

## Review Scope

Reviewer personas inspect the changed files, named authority docs, focused
tests, implementation report, and captured evidence for the slice under review.
They may read project-wide patterns from `AGENTS.md`, `HARNESS.md`, skills, and
authority docs to evaluate the diff, but they do not scan or relitigate the
whole project unless the user explicitly asks for a broad audit.

Broad documentation audits are a separate workflow. Use
`auditing-docs-drift` for explicit contradiction, drift, stale terminology, or
anchor-sweep requests; do not smuggle that full-repo audit into routine feature
review.

## Loop Stack

Orbit repo development follows a simple loop stack:

1. **Implement**: scoped change in an isolated worktree; align docs, tests, and
   code.
2. **Verify**: run the narrowest useful checks for the change type (Pest,
   quality-check, retained topology proof when touched).
3. **Triage**: when something fails or review finds a gap, identify the missing
   context or guardrail.
4. **Distill**: record candidate signals in `.orbit/loop.md` as they appear,
   then classify them as `promote`, `already-covered`, `reject`, or `defer`.
5. **Handoff**: report `complete`, `blocked`, or `complete + loop improvement`
   with evidence and the next concrete step. Do not make the user ask what
   comes next.

The harness surfaces the map; the loop turns session signals into better
guardrails.
