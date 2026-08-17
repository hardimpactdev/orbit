# Solo 754 retained-Incus runtime receipt

candidate=`3761c3ec015a642c6d0efa885bfe847eb6ea51a6`
git_tree_clean=true
venue=retained-incus
environment=dev-fixture
topology_id=`dev-589c8f` (operator_gateway_app-dev; operator `orbit-e2e-dev-589c8f-operator`, gateway `orbit-e2e-dev-589c8f-gateway`, dev `orbit-e2e-dev-589c8f-dev`)
source_binding=source-mounted checkout synced to candidate; migration file sha256 `a57baca0c514aba9443865ea37f9e591ea4c12b160a5af06104f63ea3ad6cf56` identical across worktree, VM mount `/home/orbit/orbit`, and container `/srv/orbit`
driver_identity=Solo orchestrator agent process 2470 (actor `mcp-8ee210fa5f98daf2`), driving `ssh beast incus exec` into the retained VMs; DB reads via PDO `/tmp/dbread2.php`, migrations via artisan on disposable DB copies `/tmp/mig.sh`
quality_receipt=`.orbit/quality-gates/quality-check-2026-08-17T084544Z-0ad71ee4c826.json` (sha256 `530a0fcd6c2216159bd35cbab30bc98d6a340b25538daaf6d686f03c6105a44e`; commit 3761c3ec, dirty=false, exit 0, all 45 subgates zero)

## Live gateway rows (real app:new + workspace:new on app-dev-1)

App `proofapp`, instance `development` (id=2, driver_config.domain=null), serving node `app-dev-1` (node_id=5).

| route id | owner_type | domain | instance_id | app_id | node_id | workspace_id |
|---|---|---|---|---|---|---|
| 2 | app | proofapp.test | 2 | 2 | 5 | (null) |
| 3 | workspace | proofws.proofapp.test | 2 | 2 | 5 | 1 |

Derivation: instances.id=2 → app_id=2 (App derives); both routes share instance_id=2; workspace row carries workspace_id=1.

## Six-surface matrix

| # | Surface | Method | Expected | Observed | Result |
|---|---|---|---|---|---|
| 1 | Primary App | live `app:new` row id2 | owner_type=app persists one positive instance_id, app/node derived | instance_id=2, app_id=2, node_id=5 | PASS |
| 2 | Workspace | live `workspace:new` row id3 | same instance_id, matching workspace_id | instance_id=2, workspace_id=1 | PASS |
| 3 | Public analytics | migration fixture id4 (`app-analytics`, kind=proxy, config.instance.domain=route-host) on disposable copy | forward-fill / rollback-preserve / reapply-restore instance_id=2 | instance_id=2 across up→down→up; config.instance_id=2 on rollback | PASS |
| 4 | Public WebSocket | migration fixture id5 (`app-websocket`, kind=proxy, config.instance.domain=route-host) on disposable copy | forward-fill / rollback-preserve / reapply-restore instance_id=2 | instance_id=2 across up→down→up; config.instance_id=2 on rollback | PASS |
| 5 | Forward migration (incl. malformed/ambiguous fail-before-schema-mutation) | `migrate --path=<ownership migration>` on disposable copies | fills instance_id for all 4 families; ABORTS before adding column on ambiguous row | 4 families filled instance_id=2; ambiguous route (app 2 with 2 instances, no id evidence) threw "has no provably unique owner among Instance candidates [2, 3]" and column NOT added (fail-before-schema-mutation) | PASS |
| 6 | Rollback / reapply | `migrate:rollback` then `migrate` on disposable copy | rollback writes config.instance_id and drops column; reapply restores exact instance_id | down→config.instance_id=2 for all 4, column dropped; up→instance_id=2 restored for all 4; second down→up cycle identical | PASS |

## Additional required behaviors

| Behavior | Observed | Result |
|---|---|---|
| Exact instance_id persistence | all instance-backed families persist positive instance_id=2 | PASS |
| Idempotency | re-run `workspace:setup` → identical rows (ids 2,3; one per domain; unchanged instance_id) | PASS |
| Restart durability | `docker restart orbit-gateway` → routes unchanged (id2 app inst2, id3 workspace inst2); also survived topology sync | PASS |
| proxy:list projection | `proxy:list --filter=instance --json` → owner.type=instance, name proofapp.development, target instance | PASS |
| invalid filter | `proxy:list --filter=app` → validation_failed (allowed: all,instance,workspace,gateway,analytics,websocket,s3,tool,custom,redirect) | PASS |
| Deleted-owner fail-closed | deleted owner instance 2 while same-app sibling instance 3 present → route instance_id→NULL, belongsTo null, `InstanceProxyRouteOwnershipResolver::resolve()` returned NULL with NO fallback to the sibling | PASS |

## Regression note

The first candidate (84fea328) FAILED surface 5/6 on live-shaped data: the migration aborted on
dev-instance routes whose `config.instance.domain` = the route host (dev instances have
`driver_config.domain=null`). Fixed in `3761c3ec` (`configuredInstanceId` now tolerates a
non-matching cached string hint, `continue` instead of throw) while preserving fail-closed on
AMBIGUOUS (multi-candidate) and COMPETING identities — proven by surface 5's ambiguous abort.
See `.orbit/evidence/solo-754-runtime-defect-instance-domain.md`.

Analytics/WebSocket public serving is not exercisable live (no prepared E2E topology carries an
analytics or websocket backend role); their ownership persistence is proven via the migration
fixtures above (surfaces 3/4), which run the real production migration on real gateway SQLite.

result=passed
