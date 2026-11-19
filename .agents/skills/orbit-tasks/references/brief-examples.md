# Brief examples

Worked examples for [Orbit Tasks](../SKILL.md). Each one is a real group from Project 78, rewritten in the brief shape. The Operation IDs, times and paths are real. Where the original left a choice open, the example fills in a value to show the form, and says so. A writer does not pick such a value: [writing rule 11](../SKILL.md#writing-rules) puts it to the owner as a marked question, and the owner's answer goes into the brief.

## The two shapes

Group brief. The title states the outcome.

```markdown
**Problem:** What is wrong or missing, and why it matters now.

**Expected outcome:**
1. ...

**Guardrails:**
- Decided: ...
- Must hold: ...
- Accepted limits: ...
- Out of scope: ...

**Open question:** only when the cause is not known

**Proof:** only when tests cannot show the outcome
```

Subtask brief, with one subtask in the group:

```markdown
All expected outcomes of the group. Read first: <paths>.
```

Subtask brief, with several subtasks:

```markdown
**Slice:** Expected outcomes <numbers>. Builds on <subtask>. After this subtask, <what works>.

**Read first:** <paths>

**Guardrails:** <only what is specific to this slice>
```

## A bug with an unknown cause, one subtask

From group 5944. The limit in outcome 1 and the margin in outcome 2 are values chosen for this example.

Title: **A sleeping Instance without a restore plan wakes fast, and the Operation accounts for the time**

```markdown
**Problem:** Waking sleeping fixture 2120 took 9.3 s (Op 29036,
22:42:14–22:42:25). Wakes took about 1–3 s before.
- Slow: Op 29036, Instance 2120. `restore_mode` `ordinary`, `prepared_restore`
  `skipped` / `no_plan`. It ran as a Swamp job. The step "Wake the container"
  took 9.46 s (22:42:14.794–22:42:24.253). `resume_seconds` was 0.017 and
  `web_probe_seconds` 0.376. About 9 s of the step is not accounted for.
  `ready_at` was 22:42:22.67, 8 s after the step started.
- Fast: Op 28970, Instance 1898, 21:55:58. `restore_mode` `prepared`,
  `prepared_restore` `complete`, inline with no Swamp steps. Trigger 0.28 s,
  confirm 2.3 s, about 3 s in total.
- Not new in release 2158: Op 28870 (Instance 1898, 20:25, release 2157) took
  the ordinary path too. The step took 11.7 s, resume 0.017 s, probe 1.47 s.
- Recent wakes on Node 8 (Instances 3712 and 3728) also take about 10 s.

**Expected outcome:**
1. A sleeping Instance that has no prepared-restore plan is ready within 2 s,
   measured from the wake request to `ready_at`.
2. The wake Operation's result names where its time went. The named parts add
   up to the time between its start and its end, within 0.5 s.
3. `ready_at` keeps its meaning: the app answered and the asleep marker is gone.
4. A prepared wake is not slower than today (about 3 s in total, Op 28970).

**Guardrails:**
- Must hold: a wake never reports ready before the app answers. A visitor
  would get an error page.
- Out of scope: a new design for prepared restore. Another bug you find:
  report it in the handoff note and commit no fix. If it blocks the proof, ask.

**Open question:**
- Where do the roughly 9 s in "Wake the container" go, outside the resume and
  the web probe?
- Why do Instance 2120, Instance 1898 in Op 28870, and Instances 3712 and 3728
  have no prepared-restore plan?
Name both causes with file and line in the handoff note. Ask only when an
answer makes an expected outcome wrong.

**Proof:** In this copy's nested fleet, put a test Instance to sleep and wake
it five times through the normal wake entry point.
- Record for each wake: the Operation ID, `restore_mode`, the step times,
  `ready_at` and the Operation's end.
- Cases that would expose a wrong answer: a prepared wake measured in place of
  an ordinary one; time that moved to a step after `ready_at`; ready reported
  before the app answers.
- Must be real: the sleep, the wake Operation and the app's first answer.
- May be a stand-in: the test app.
- If the nested fleet cannot take the slow path, say so and ask.
- Subtask 1 owns the full proof.
```

Subtask 1 of 1: **Find the lost time in the ordinary wake and remove it**

```markdown
All expected outcomes of the group. Read first: `docs/cli/instance/wake.mdx`,
`docs/reference/prepared-restore.md`,
`swamp/workflows/workflow-operation-instance-wake.yaml`.
```

```json
[
  {"id": "wake-accounting", "type": "command", "fails_on_base": true,
   "description": "The wake Operation's result accounts for its elapsed time",
   "command": "vendor/bin/pest tests/Feature/Domain/Instances/InstanceSleepWakeTest.php --filter='ordinary wake'",
   "directory": "apps/gateway",
   "paths": ["apps/gateway/tests/Feature/Domain/Instances/InstanceSleepWakeTest.php"]},
  {"id": "wake-doc", "type": "file", "change": "modified",
   "description": "The wake page describes the new result fields",
   "path": "docs/cli/instance/wake.mdx"},
  {"id": "wake-proof", "type": "review",
   "description": "Five real ordinary wakes in the copy, each within the limit of outcome 1, with Operation IDs, step times and ready_at; both causes named with file and line"}
]
```

What to notice:

- The brief names no fix. The original listed fix ideas: "job dispatch, marker or Caddy wait, a sleep or poll interval".
- The limit has a clock: from the wake request to `ready_at`. The original said "about 1–2 s".
- Outcome 4 says what must keep working.
- The proof lists the cases that would pass by mistake, and names the subtask that owns it, also with one subtask.

## A change with a decided approach, one subtask

From group 5971.

Title: **Converge runs no apt cache refresh when the wanted packages are installed**

```markdown
**Problem:** In the refresh of Instance 2454 after release 2162 (Op 29505),
the roles phase spent most of its time in apt tasks with `update_cache: true`
and `cache_valid_time: 3600`. Nothing was installed.
- nested-app-prod: 134 s in the Squid task. Squid was installed already.
- nested-app-dev: about 213 s for the apt update and 24 s for `btrfs-progs`
  (`swamp/ansible/tasks/instance-fleet-node.yml`).
- The apt lists in the nested base are hours old, so each roles run does a
  full apt update through two Squids.
- Since #4060 the Squid task reads the package state first and skips apt
  (`swamp/ansible/tasks/instance-egress.yml`). The `btrfs-progs` task still
  refreshes. Other converge plays are not checked yet.

**Expected outcome:**
1. A roles run on a Node whose wanted packages are all installed at the wanted
   version runs no apt cache refresh, also when the apt lists are older than
   one hour.
2. A package that is missing, or installed at another version than its pin, is
   still installed or repaired, with a cache refresh first. A package with only
   its config files left counts as missing.
3. A fresh Node ends with the same packages as today.
4. A failed read of the package state never counts as "installed". Apt runs.

**Guardrails:**
- Decided: read the installed state once for each play, in one batched read,
  as the package-hold read of #4060 does.
- Accepted limits: an apt index refresh that serves another purpose than
  installing a package stays as it is.
- Out of scope: package pins, the update policy and the proxy setup. Another
  bug you find: report it and commit no fix. If it blocks the proof, ask.

**Proof:** On a nested Node whose apt lists are older than one hour, run the
converge before and after the change.
- Record the roles phase seconds from both Operation results.
- Cases that would expose a wrong answer: the roles phase skipped because its
  digest did not change; apt lists refreshed within the last hour.
- Must be real: both converge Operations and the age of the apt lists.
- Subtask 1 owns the full proof.
```

Subtask 1 of 1: **Skip the apt cache refresh for installed packages**

```markdown
All expected outcomes of the group. Read first:
`swamp/ansible/tasks/instance-fleet-node.yml`,
`swamp/ansible/tasks/instance-egress.yml`,
`swamp/tests/ansible_reuse_install_test.py`.
```

```json
[
  {"id": "apt-decisions", "type": "command",
   "description": "Fixture cases: installed with stale lists (no refresh), missing, config files only, wrong pin, failed read",
   "command": "bin/ci-units run --units '[\"swamp/tests/ansible_reuse_install_test.py\"]' --times /tmp/apt-unit-times.json",
   "directory": "."},
  {"id": "apt-timing", "type": "review",
   "description": "Roles phase seconds before and after on a nested Node with stale apt lists, by Operation ID; the roles phase ran both times"}
]
```

What to notice:

- The batched read is a design, and it stays, because it is decided. It sits under Decided.
- The Problem states what main already has. The original brief also asked for the Squid part, which #4060 had shipped.
- This is not a bug, so the `command` has no `fails_on_base`.
- There is no Open question. The section would have nothing to say.

## A feature with three subtasks

From group 5915. The line in angle brackets shows where a fact from another repository goes.

Title: **Task copies run their turns through the Conn orchestration RPC with a Gateway-issued grant**

```markdown
**Problem:** Swamp drives a task copy's turns through the CLI today: it parses
CLI output, finds the thread by its title, opens short-lived CLI sessions and
uses a WebSocket helper made for tasks. The agreed replacement is one supported
Conn Server API.
- Orbit's contract: `docs/reference/development-containers.md`, "Task-copy RPC
  (target)" and "Task worker grant" (#4034), and `docs/reference/conn.md`,
  "Managed grant revocation" (#4027). Both are merged.
- Conn's side is merged on its main: the Rust target fence (#139) and the task
  worker grant with atomic owner checks (#140).
- Conn's wire shape for the grant:
  <the owner pastes the shape here; a copy cannot read the Conn repository>

**Expected outcome:**
1. For a task copy, the Gateway issues a task worker grant in exactly Conn's
   wire shape: the caller-to-target binding, the task and copy identity and a
   fixed method allowlist. It is valid for the copy's lifetime.
2. A grant for another task is refused. At task end the grant is revoked
   through the existing revocation fence, and its session can no longer read
   or write.
3. A copy turn on a Rust Conn target runs through the RPC with that grant:
   launch, continue, and a question that ends the turn with its later answer.
4. Under failure the RPC path holds: two sends at once give exactly one
   accepted run; a lost reply is recovered without a second run; one stale
   request can be cancelled exactly.
5. For Rust Conn targets the old path is gone: task CLI parsing, thread lookup
   by title, short-lived CLI sessions and the task WebSocket helper.
6. What worked before still works: a question ends the turn, task evidence is
   recorded as today, and the durable-launch helper and stock T3 targets
   behave as before.

**Guardrails:**
- Decided: the two contracts named above. If Orbit's contract and Conn's shape
  differ, align both to one shape and record the decision in
  `docs/reference/development-containers.md`.
- Accepted limits: stock T3 targets keep the old path. State which parts
  remain and why.
- Out of scope: grant support in stock T3. Another bug you find: report it and
  commit no fix. If it blocks the proof, ask.

**Proof:** In one task copy, against the Rust Conn server that the copy runs.
- Must be real: the Gateway-issued grant, the Rust Conn server, the RPC calls
  and the revocation.
- May be a stand-in: a minimal prompt for the turn.
- Keep receipts under `.orbit-artifacts/`: thread, run and command IDs with
  timings.
- Subtask 3 owns the full proof.
```

Subtask 1 of 3: **The Gateway issues and revokes the task worker grant**

```markdown
**Slice:** Expected outcomes 1 and 2. After this subtask the Gateway issues
and revokes the grant. Nothing uses it yet.

**Read first:** `docs/reference/development-containers.md` ("Task worker
grant"), `docs/reference/conn.md` ("Managed grant revocation"),
`apps/gateway/tests/Feature/Extensions/Conn/ConnManagedRevocationTest.php`.
```

```json
[
  {"id": "grant-tests", "type": "file", "change": "created",
   "description": "Tests: issue, scope, wrong task refused, revoked at task end",
   "path": "apps/gateway/tests/Feature/Extensions/Conn/ConnTaskWorkerGrantTest.php"},
  {"id": "grant-tests-pass", "type": "command",
   "description": "The grant tests pass",
   "command": "vendor/bin/pest tests/Feature/Extensions/Conn/ConnTaskWorkerGrantTest.php",
   "directory": "apps/gateway",
   "paths": ["apps/gateway/tests/Feature/Extensions/Conn/ConnTaskWorkerGrantTest.php"]},
  {"id": "grant-doc", "type": "file", "change": "modified",
   "description": "The grant shape, and the alignment decision if one was needed",
   "path": "docs/reference/development-containers.md"}
]
```

Subtask 2 of 3: **Prove the RPC path live in one copy**

```markdown
**Slice:** Expected outcomes 3 and 4, shown live. Builds on subtask 1. After
this subtask the RPC path is shown to hold. Swamp's driver still uses the CLI.
A failed case here can change subtask 3.

**Read first:** `docs/reference/development-containers.md` ("Task-copy RPC
(target)"), `swamp/ansible/t3_copy_rpc.mjs`.

**Guardrails:** Stop every server you started and remove what you created in
the copy before you hand off.
```

```json
[
  {"id": "rpc-live-proof", "type": "review",
   "description": "Receipts for launch, continue, question and answer, wrong caller refused, one accept on concurrent send, lost-reply recovery, one exact cancel, and the fence after revocation, each with IDs and timings"}
]
```

Subtask 3 of 3: **Swamp's task driver uses the RPC path for Rust Conn targets**

```markdown
**Slice:** Expected outcomes 5 and 6, and the full proof. Builds on subtask 2.
After this subtask a copy turn on a Rust Conn target runs through the RPC, and
the old path is removed for those targets.

**Read first:** `swamp/ansible/t3_copy_rpc.mjs`,
`swamp/tests/t3_copy_rpc_test.ts`,
`swamp/tests/ansible_task_copy_turn_test.ts`.
```

```json
[
  {"id": "driver-tests", "type": "command",
   "description": "The RPC helper and task copy turn tests pass",
   "command": "bin/ci-units run --units '[\"swamp/tests/t3_copy_rpc_test.ts\",\"swamp/tests/ansible_task_copy_turn_test.ts\"]' --times /tmp/driver-unit-times.json",
   "directory": "."},
  {"id": "remaining-paths", "type": "file", "change": "modified",
   "description": "Which parts of the old path remain for stock T3, and why",
   "path": "docs/reference/development-containers.md"},
  {"id": "old-path-removed", "type": "review",
   "description": "For Rust Conn targets no code parses task CLI output, looks a thread up by title, or opens a CLI session; the durable-launch caller still works"},
  {"id": "full-proof", "type": "review",
   "description": "The group's proof through Swamp's driver: launch, continue, question and answer, and the fence after revocation, each with IDs and timings"}
]
```

What to notice:

- The group brief holds every outcome once. Each subtask names its outcomes by number.
- Each subtask builds on the one before it and can be checked on its own. That is why this is three subtasks and not three groups.
- Subtask 2 is a finding that must come first: if the RPC path fails a case, subtask 3 changes. Its deliverable is a `review`, not a `file` on a doc page.
- The last subtask owns the full proof, and the proof names it.
