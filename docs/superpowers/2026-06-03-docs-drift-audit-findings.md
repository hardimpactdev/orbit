# Docs Drift Audit — Findings (2026-06-03)

Scope: `apps/docs/content/` excluding `porting/`. Authority order:
mission > architecture > concepts > tech-stack > domain READMEs > technical contracts.

Verified by reading the cited files. Severity: A = direct contradiction with
authority; B = stale/ambiguous terminology in normative docs; C = broken
ref / catalog gap / phantom code.

---

## A1 — Schedule public pages describe a node-side scheduler

**Severity:** A
**Files:** domains/9_schedule/1_schedule-add/schedule-add.md:7, domains/9_schedule/4_schedule-remove/schedule-remove.md:7

**The drift:** Authority is emphatic that the scheduler is gateway-only.
tech-stack.md:330-336 ("There is no scheduler daemon on non-gateway nodes");
schedule-concepts.md:72-73 ("Gateway-only scheduler invariant: All schedule
evaluation, dispatch, locking, and history live on the gateway." / "No
node-side scheduler: Targets receive dispatched commands via `RemoteShell` at
execution time and hold no local mirror."). But the two public command-page
intros say:
- schedule-add.md:7 — "The Orbit Scheduler **on the target node** picks up the new schedule on its next sync."
- schedule-remove.md:7 — "The target node's Orbit Scheduler stops evaluating the schedule on its next sync."
Both invent a per-node scheduler daemon that syncs/evaluates. The longer
"What Happens" prose in the same files (schedule-add.md:40) describes the
gateway-only model correctly, so only the intro lines drifted.

**Why it matters:** A reader concludes each target node runs its own scheduler
daemon and there is sync latency between gateway config and a node-local
schedule store. That is the exact mental model the architecture rejects, and it
misleads operators debugging "why didn't my schedule run" toward a non-existent
node-side component.

**Recommended fix:**
1. schedule-add.md:7 → "The command records schedule configuration on the gateway. The gateway-only Orbit Scheduler reads the gateway database every tick and dispatches the schedule to its resolved target via `RemoteShell`."
2. schedule-remove.md:7 → "The command removes gateway configuration. The gateway-only Orbit Scheduler stops dispatching the schedule on its next tick; there is no node-side scheduler state to clean up."

---

## A2 — Tool catalog still models PHP/Composer as container-only (host-toolchain reversal not propagated)

**Severity:** A
**Files:** domains/3_tool/README.md:70, domains/3_tool/README.md:72, domains/3_tool/catalog/README.md:73-75

**The drift:** Authority now says app-dev/app-prod nodes carry a **host** PHP
toolchain converged **as node tools**. tech-stack.md:66 (app-dev/app-prod
require host PHP/Composer/Laravel installer); node-concepts.md:281-292 ("those
app-role nodes additionally carry a host PHP command-line toolchain — host PHP
8.4 and 8.5, Composer, and the Laravel installer — installed and repaired as
node tools, because `app:exec`, app setup, and deployment run Composer and
Artisan on the host"). The individual catalog files agree: php-cli.md:13
("prebuilt static binaries (dl.static-php.dev bulk preset)", "Installable and
updatable by Orbit on app-dev/app-prod nodes"); composer.md:13 ("host binary
(`/usr/local/bin/composer`)", "Installable and updatable by Orbit on
app-dev/app-prod nodes").

But the canonical tool tables still describe the old container model:
- README.md:70 — `php-cli` Backend "runtime container capability", "Provided by `orbit-gateway` and app/workspace PHP images", capability "probe" only.
- README.md:72 — `composer` Backend "runtime container capability", "Provided inside source-dev gateway/app/workspace PHP images; production gateway updates use `orbit-gateway` images instead of host Composer", capability "update" only.
- catalog/README.md:73-75 — "PHP, Composer, and Caddy runtime capabilities live in Orbit-managed containers."

**Why it matters:** The family README table is the canonical tool list. It
contradicts both the authority and its own catalog files about where PHP and
Composer live and whether they are installable host tools. A maintainer
implementing or repairing the toolchain gets opposite answers depending on
which doc they open.

**Recommended fix:**
1. README.md:70 → `php-cli` Backend "host static binaries (dl.static-php.dev bulk preset)", Support model "Role baseline host toolchain on `app-dev`/`app-prod`; installable & updatable by Orbit", capability "lifecycle, update, fix, adopt" (match php-cli.md).
2. README.md:72 → `composer` Backend "host binary (`/usr/local/bin/composer`)", Support model "Host toolchain on `app-dev`/`app-prod`; installable & updatable by Orbit", capability "install, update, fix, adopt" (match composer.md).
3. catalog/README.md:73-75 → replace the "PHP, Composer ... live in Orbit-managed containers" sentence with: app/workspace **web** runtime is FrankenPHP containers; the **host PHP CLI toolchain** (`php-cli`, `composer`, `laravel-installer`) is a role-baseline node toolchain on `app-dev`/`app-prod` used by `app:exec`/`workspace:exec`/deploy. Keep Caddy as the `orbit-caddy` container.
4. Code follow-up flag: confirm `php-cli`/`composer` tool definitions expose `install`/`update`/`fix`/`adopt` matching the catalog files.

---

## A3 — Firewall doctor node-eligibility drops `router` and `ingress`

**Severity:** A
**Files:** domains/4_firewall/firewall-doctor.md:30 (and :51)

**The drift:** firewall README:13-16 and firewall-concepts both define eligible
firewall targets as nodes with role `gateway`, `router`, `app-dev`, `app-prod`,
`database`, `agent`, or `ingress` (7 roles), and README:16 explicitly says
database-only nodes "accept operator-managed firewall rules." But
firewall-doctor.md:30 (Node eligibility probe layer) says the node must resolve
to "a visible active Ubuntu node with at least one active role assignment from
`gateway`, `app-dev`, `app-prod`, `database`, or `agent`" — dropping `router`
and `ingress`. `firewall_rule.node_invalid` (line 51, "role-incompatible node")
inherits the narrowed list.

**Why it matters:** `ingress` is the public-edge role most likely to carry
custom operator firewall rules. Under this doctor probe, an ingress-only node's
rules are flagged `firewall_rule.node_invalid` even though the family README
says ingress is a valid target. Operators get false drift on exactly the node
where firewall rules matter most.

**Recommended fix:**
1. firewall-doctor.md:30 → add `router` and `ingress` so the eligibility list matches the README/concepts 7-role set: "...from `gateway`, `router`, `app-dev`, `app-prod`, `database`, `agent`, or `ingress`."
2. Confirm `firewall_rule.node_invalid` (line 51) text references the same 7-role eligibility.
3. Code follow-up flag: align the firewall probe's eligible-role set with the README list (add router, ingress).

---

## A4 — `node:update --tld` excludes agent nodes, but agent requires `tld`

**Severity:** A (borderline A/B — capability gap)
**Files:** domains/1_node/7_node-update/technical/1_node-update.md:39, :57, :68-74, :104-105

**The drift:** Authority treats `tld` as a shared **node-level** field required
by `app-dev` **and** `agent`, changeable as a desired-state change.
concepts.md:26; node-concepts.md:108-110, 169, 174-180 ("Changing the
node-level `tld` ... triggers baseline convergence for every active role
assignment that depends on it"); node-doctor.md:27, 106-107 (probes agent tld
drift and maps `*.{tld}` for agent). But node-update's technical contract
forbids `--tld` unless the target carries an active `app-dev` assignment
(1_node-update.md:39 "Forbidden when: Target does not carry an active `app-dev`
role assignment"; :57 valid roles = "nodes with an active `app-dev` role
assignment"; :73-74 gateway/operator targets fail `node.field_role_incompatible`).
An `agent` node therefore has no command path to change its `tld` after
`node:new`, even though the agent role requires one and doctor reports agent tld
drift.

**Why it matters:** Either agent TLD is meant to be mutable (then node:update
wrongly rejects it and there is a coverage gap), or it is immutable after
creation (then no doc states that and the convergence-on-change language in
node-concepts is wrong). A reader/maintainer can't tell which, and an operator
who wants to change an agent node's TLD hits a wall with no documented path.

**Recommended fix (needs a product decision — present 2 options):**
- Option 1 (recommended): make `--tld` valid for nodes with an active
  `app-dev` **or** `agent` assignment. Update 1_node-update.md:39/:57/:68-74
  (and signature/JSON error tables) to allow agent targets; gateway/operator/
  no-tld-role targets still fail `node.field_role_incompatible`. Code follow-up.
- Option 2: declare agent TLD immutable after `node:new`; state that explicitly
  in node-concepts.md and node-update, and soften the "every active role
  assignment that depends on it" convergence language to app-dev only.

---

## B1 — Workspace README labels `workspace:exec` as container execution

**Severity:** B
**Files:** domains/6_workspace/README.md:222-224, domains/6_workspace/workspace-concepts.md:27-29

**The drift:** README:222-224 has a "### Container execution commands" heading
and "These commands run ad-hoc PHP, Composer, or Artisan commands **inside a
workspace runtime container**." workspace-concepts.md:30-33 says the opposite:
exec runs "on the node's **host PHP toolchain** ... The commands run on the
host, **not inside the container**." The parallel app docs already use the host
model (app README:38; app-concepts.md:82-84). Additionally, the "Workspace
runtime container" bullet (workspace-concepts.md:27-29) clause "and runs
workspace-scoped PHP and Composer commands" is stale in the same way (the
container serves the FrankenPHP web route; exec runs on the host).

**Why it matters:** Host vs container execution is a real behavioral difference
(filesystem, environment, isolation). A reader expecting container semantics for
`workspace:exec` will be wrong. This is the host-PHP-toolchain reversal that
reached the app domain but not the workspace README section.

**Recommended fix:**
1. README.md:222-224 → rename heading to "### Command execution" and reword: "These commands run ad-hoc PHP, Composer, or Artisan commands on the node's host PHP toolchain (matched to the workspace's PHP version) against the workspace source — not inside the workspace runtime container."
2. workspace-concepts.md:27-29 → drop "and runs workspace-scoped PHP and Composer commands" from the runtime-container bullet (the container serves the FrankenPHP web route; exec is host-side).

---

## B2 — node README calls `tld` an "app-dev role-assignment TLD"

**Severity:** B (related to A4)
**Files:** domains/1_node/README.md:519

**The drift:** README:519 — "`app-dev` role-assignment TLD changes route through
`orbit node:update [name] --tld=...`." node-concepts.md:161-180 explicitly puts
`tld` under "Node-level settings the role requires" (NOT role-assignment
settings) and states it is a shared node-level field for app-dev and agent.

**Why it matters:** Calling tld an "app-dev role-assignment TLD" reinforces the
A4 confusion and the (incorrect) idea that tld belongs to one role's assignment
rather than the node row.

**Recommended fix:**
1. README.md:519 → "the node-level `tld` (shared by `app-dev` and `agent`) is changed through `orbit node:update [name] --tld=...`." (Coordinate wording with the A4 decision on agent targets.)

---

## B3 — node README "three concepts" then lists five

**Severity:** B
**Files:** domains/1_node/README.md:25-50

**The drift:** README:25 "Orbit distinguishes three concepts:" followed by five
bullets: Gateway role, VPN role, Router role, Node roles, Client identity. The
"three" is stale from before `vpn`/`router` were split into named
gateway-coupled roles.

**Why it matters:** Minor, but a normative count that's visibly wrong erodes
trust and confuses readers counting role categories.

**Recommended fix:**
1. README.md:25 → "Orbit distinguishes these concepts:" (drop the number), or "five concepts" if a count is wanted.

---

## C1 — `laravel-installer` tool missing from canonical tool tables

**Severity:** C (same cluster as A2)
**Files:** domains/3_tool/README.md:64-84 (tool table), domains/3_tool/catalog/README.md:80-83 (role-baseline table)

**The drift:** laravel-installer.md is a full catalog file (Backend "Composer
global package (`laravel/installer`)", "Installable, updatable, and removable by
Orbit on app-dev/app-prod nodes") and node-concepts.md:283 + composer.md:39 name
it part of the host PHP toolchain baseline. But it appears in neither the family
README tool table (lines 64-84) nor the catalog README role-baseline table
(lines 80-83, which lists only `viteplus` and `rustfs`).

**Why it matters:** A role-baseline host tool that exists as a contract file but
is absent from the canonical lists is invisible to anyone reading the catalog
top-down; the canonical table is supposed to be exhaustive.

**Recommended fix:**
1. Add a `laravel-installer` row to README.md tool table (Backend "Composer global package", category `runtime`, capability "install, update, remove, fix, adopt").
2. Add `php-cli`, `composer`, `laravel-installer` to the catalog README role-baseline table (lines 80-83) under owning roles `app-dev`, `app-prod`. (Coordinate with A2.)

---

## C2 — schedule-doctor references a phantom `run_history_hook_*` code

**Severity:** C
**Files:** domains/9_schedule/schedule-doctor.md:100

**The drift:** The ScheduleDoctorFixTest.php test-mapping row says restore
covers "`scheduler_missing`, `scheduler_stopped`, `lock_stuck`, and
`run_history_hook_*` codes." No `run_history_hook_*` code exists in the Schedule
Issue Codes table (lines 49-58) or the Fix Map (lines 65-79), and the Fix Map
only restores `scheduler_missing`, `scheduler_stopped`, and `lock_stuck`.

**Why it matters:** A test-mapping contract that names a non-existent issue code
will mislead anyone implementing/maintaining the test about what the schedule
probe emits and restores.

**Recommended fix:**
1. schedule-doctor.md:100 → drop "and `run_history_hook_*` codes" so the row reads "...repair for `scheduler_missing`, `scheduler_stopped`, and `lock_stuck`."

---

## Non-findings (checked, clean)

- Caller-role leakage: no "Caller Role Rule" / `caller_role_not_allowed` / control-gateway-app-unknown gating anywhere. Authorization is consistently grants-only.
- `doctor --family=security`: only appears as negative assertions ("intentionally invalid"). Correct.
- Broken architecture/tech-stack anchors: all cited anchors resolve to real headings.
- Preset list (agent-self, operator, read-only, developer, admin, gateway-admin): consistent everywhere.
- State-family count = 9: operation README, activity README, and doctor.md (lines 108-119, including database_connection at 119) all enumerate the full 9. (Subagent claim that doctor.md omits database_connection was incorrect — rejected.)
- `registry_synced_at` JSON-undefined-field example: no longer present.
- DNS ownership split, VPN coupling, S3 ownership, agent-IDE-vs-agent-role: clean in cf/vpn/php/agent-ide/dns/db/s3 domains.
