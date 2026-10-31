# Fleet maintenance

Swamp schedules peer DNS reconciliation, machine recipe maintenance and fleet
visits. Orbit admits each Operation, binds its commands to recorded intent, and
records the proven result. The runner dispatches a kind and worker; this family
claims inside that invocation and saves its assignment before effects. A retry
keeps that Operation and never claims a different one.

- `fleet.cli_converge` is the optional CLI child for Node workflows. It pins the
  desired release and profile in the Gateway, installs through `cli.install`,
  and proves a final result. Ineligible Nodes and unpublished releases succeed
  with `outcome: skipped` and a reason. Other failures remain failed children
  for the parent to acknowledge. The Gateway records foreign binaries only from
  a proven refusal and clears that mark only after a proven install.
- `fleet.peer_dns` starts directly through `operation-fleet-peer-dns` on the
  exact `orbit-node-ID` worker, or the existing `swamp209-migration-a` worker
  only for admitted Node209 intent. Both require the matching Node label. It
  uses the configured Node user’s sudo to run the pinned local inspector, repair
  only drift, and verify the admitted policy and usable uplink. The Gateway
  keeps intent and a bound result, without a claim, execution receipt or target
  lock. Native behavior serialization owns per-peer concurrency. The scheduled
  discovery branch and old Gateway run and receipt declarations for this kind
  are deleted. Its direct-only declaration selects the direct writer by default;
  an explicit operator polled setting refuses admission because that path is
  deleted. Rollback is a revert PR. Known agent refusals map to bounded `vpn.*`
  codes without diagnostics, including unreadable inspection and repair failure
  before publication. Unknown replies still fail natively without claiming a
  terminal outcome.
- `fleet.recipe` applies the deployed recipe, installs pinned PHP, checks
  managed inventory and Doctor, and verifies the exact delivery. Automatic
  visits exclude Incus. Explicit visits keep the drained-host preflight. The
  Gateway keeps the last verified recipe while a separate mutation attempt marks
  the Node unverified. Only application, Doctor and delivery verification
  together replace the success record; a failed or interrupted attempt preserves
  it.
- `fleet.recipe_pr` calls Gateway command `github.recipe-pr` to open, update,
  retire or merge one recipe PR. The App token stays in the Gateway. Swamp
  carries only its secret reference. Signed App snapshot PRs still merge on
  green checks.
- `fleet.rollout` installs and updates the CLI, awaits a `node.converge` child,
  and verifies the Node. The Gateway derives the child’s `force_artifacts` and
  `first_visit` booleans; Swamp sends only the child key. Pending children use
  the shared `/wait` primitive. The Gateway reads proven child results for Caddy
  skips.

The retained discovery and maintenance workflows run on the `migration` worker
pool on Node 209; direct peer DNS runs on its own Node worker. The family
executor has a 60-second queue timeout and claims a bounded batch each
one-minute tick; maintenance retains its 300-second queue timeout. Each family
reads its own claimable summary on the configured orchestrator Node. Native
recovery tests use local copies with placement removed; deployed workflows keep
their worker labels.

The maintenance workflow runs each minute. It refreshes desired fleet state
every five minutes and reads `/api/v1/fleet/peer-dns/plan` each minute. The
Gateway returns Node ids and SHA-256 digests of the effective DNS server, peer
public key and sorted routing domains, without contacting peers or admitting
Operations. Peer DNS is admitted when this digest changes, plus once per UTC
hour for host drift. Active work is awaited; failed work retries; a lost
admission reply reuses its saved key even across a policy or hour change. With
unchanged policy and successful work, admission falls from 288 to 24 Operations
per Node per day. The nine managed live targets therefore fall from 2,592 to 216
Operations/day (91.7% fewer), plus changed-policy admissions and retries. This
is a steady-state projection; outages and queueing reduced the observed prior 24
hours to 1,132.

Maintenance opens daily PRs after 03:00 Amsterdam and admits ordered recipe and
rollout visits. Catch-up visits wait five minutes. App-dev-only Nodes may start
anytime; shared and production Nodes start from 03:00 inclusive to 06:00
exclusive Europe/Amsterdam. An effect already running may finish later. The
Gateway checks current roles and its own clock before every later run.
Restricted re-entry with steps and no saved completion fails with
`fleet.visit_interrupted`, except for a durable Gateway-proven wait on the
parent’s own footprint child. The Gateway records an inherited `fleet.window`
constraint for automatic recipe and rollout children. Its check uses current
target roles before hydration and dispatch. After that child settles, the
Gateway exposes verified wait proof in the family execution context. A closed
window interrupts the parent before another effect. Interruption cleanup reads
all active target receipts and keeps the hold while any is running or
unreadable. Pending children settle before parent completion.

Admission intent is saved before posting. A lost reply keeps its original path,
payload and key. Remote invocation intent is also saved first. On recovery, kit
I's receipt read resolves the target effect: terminal receipts are reused,
running effects wait, and only an unknown effect is invoked with the same key.
Completion intent is permanent and replayed without another Node command.

This family uses shared kit A/F/G for Gateway commands and App references, B/C
for parent-bound children and declared object lists, and I for target receipts.
Those primitives belong to their shared owners. The Gateway omits dependent
kinds until the loader advertises their required capabilities. Admission and
planning return `operation.kind_unavailable` until the shared APIs and lane 6’s
`node.converge` are installed. Until then, nightly recipe maintenance, fleet
rollout and manual `node:converge-recipe` have no executor. HTTP recipe
admission requires `automatic: true`; manual runs stay in artisan. No manual
enable flag is used. Under its admission lock, Orbit closes visits with missing
or terminal Operations and alerts once when an active visit stays open for 30
minutes. Retire the old Gateway fleet timer during the paired deployment.

Run the focused proof locally with at most four workers:

```sh
deno fmt --check extensions/behaviors/operations/fleet
deno lint extensions/behaviors/operations/fleet
deno check extensions/behaviors/operations/fleet/mod.ts
deno test --allow-read=. extensions/behaviors/operations/fleet/*_test.ts
python3 -B -m unittest discover -s extensions/behaviors/operations/fleet -p native_test.py -v
```

CLI transport failures read target receipts and probe reachability before one
bounded retry. Unreachable Nodes remain catch-up targets; definite self-update
failures halt without a retry.

The native tests use a disposable repository and fake Gateway. They run the real
pinned Swamp parent and family workflows, then resume in another process after a
lost effect or completion reply. Live proof follows deployment on the
orchestrator's designated dev Node; record its Operation id, steps, results and
deployed SHAs.

Roll out the paired Gateway peer-DNS plan endpoint before this maintenance
behavior. Existing target-list reads remain a projection of the same plan. No
new authority, standing credential or Node access is added.

## Discovery during direct-start migration

The retained Fleet discovery schedule runs once per minute at second 4. It ends
when the last polled Fleet kind moves to direct admission. The orchestrator's
2026-10-30 Node 209 profile measured 40 runs in about ten minutes: 31 were
empty, while 9 executed recipe-PR work. Those runs left 1,240 new surviving
inodes. The live cadence was 10 seconds; the prior source cadence was 30
seconds. This measured exception reduces empty discovery while the direct
writers are being proven.

The maximum scheduled wait grows from 10 to 60 seconds. Actual claim latency
also includes worker queue time. After loading, measure actual run spacing and
surviving inode growth rather than infer an achieved reduction from source.
Evidence: `~/swamp-execution/evidence/run-producers-20261030/attribution.json`
on Beast. No new Gateway scheduler or execution machinery is introduced.

`fleet.recipe_pr` uses its own direct workflow and has no polled discovery or
executor branch. It runs on the admitted Gateway Node's exact worker for the
shared private GitHub reader. See `docs/fleet-recipe-pr-direct.md` for policy,
retry preparation and the production cutover.
