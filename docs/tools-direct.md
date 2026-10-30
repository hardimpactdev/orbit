# Direct Tools intent

`operation-tool-direct` runs `tool.install`, `tool.update`, `tool.remove`,
`tool.adopt` and `tool.manager_converge` through the
[shared direct Operation boundary](direct-operations.md). The Gateway declares
one workflow, `writer_config: swamp.direct_writers`, immutable `tool_writer`
and `ToolIntentProjection`. It uses `StartSwampRun` and the one
`ORBIT_SWAMP_RUN_TOKEN`. No family token, controller, request, route, primitive
or Gateway execution relay is added.

The Gateway admits package identity, enrolled account, agent pin and public
recipe data. Its read projection exposes only those safe inputs. Native Node
policy selects fixed typed commands from the closed manager registry. Result
projection stores bounded manager/package versions and refusal codes; it never
stores manager stdout or execution receipts.

The canary uses the existing Node209 `pool=migration` workers. Admit canary
intent only for Node209; keep the shared writer map polled fleet-wide. When
per-Node workers are available, place the action on exact
`orbit-node-<admitted-node-id>` before fleet takeover.
`tools-node-<id>` is a stable behavior identity for native scope serialization.
The workflow uses `writes: true`, `vaults: []` and native placement, concurrency
and retention defaults. There is no placement fallback or polling schedule.

## Policy and native recovery

The direct and retained writers share `policy.ts`: constraints gate normal
candidates, install refuses silent adoption, adoption never mutates, and removal
requires only the exact recorded root with no autoremove. Manager materialization
is part of the native action, rather than a Gateway child Operation. macOS
verification preserves the enrolled account's existing scope.

Before mutation the workflow stores its selected package observation as a native
workflow resource. This is an observation, not an effect receipt. Recover/resume
reads current inventory and reconciles an uncertain install, update or removal
before considering another mutation. A saved absent baseline distinguishes a
resumed install from silent adoption. A resumed removal that is already absent
needs no new removal plan. The before-observation and bounded outcome use native
workflow lifetime; no external data-version pin is stored in the Gateway.

Unknown action outcomes and lost projection responses escape to native recovery.
A terminal Gateway read avoids another action. Bounded Gateway HTTP calls do not
inherit the workflow cancellation signal. Cancellation is checked before action;
a started action must stop safely and retain an uncertain outcome if needed.

## Activation gates

The local adapter refuses before executing anything until lane16 supplies the
approved shared local socket API and its closed Tools catalogue. There is no
executable sudo client or alternate launch path. This source change installs no
worker, account, sudo rule, token, grant or runtime unit. Preserve the worker's
current protections. Fixture injection tests policy while that upstream boundary
is pending.

`ORBIT_SWAMP_DIRECT_WRITERS` defaults to `{}`; all five kinds remain polled.
Before switching a kind to `direct`, prove the approved action end to end on a
dev/canary target, including Gateway start-response binding before first read.
Per-Node callers must match admitted `Operation.node_id`. A worker cannot bind
the root run ID. Record Operation/run IDs and before/after latency. Reverting a
map entry to `polled` changes new intent only; admitted intent retains its writer.

Legacy parents still request `tool.manager_converge` child Operations and verify
their old completion contracts. Keep that kind polled for these callers until
parent owners move manager actions into their own native DAGs. No shared child
core is deleted by this slice.

Production success precedes deletion of the old writer, descriptors, manager
child admission and corresponding tests. Remove `operation-tools` polling after
all kinds and outstanding polled work are settled. Shared executor core remains
with the boundary lane. No family net-negative or latency improvement is claimed
by this preparation slice.

The Node209 ten-second cadence baseline was 45/45 empty Tools discovery runs,
about 6,392 inodes/hour. Evidence is
`~/swamp-execution/evidence/run-producers-20261030/attribution.json`.

## Verification

`direct_test.ts` covers all five kinds across all five managers, constraints,
exact removal, incomplete inventory, manager refusal, shared API identity,
cancellation and native reconciliation. `direct_native_test.py` exercises the
pinned native workflow with injected Gateway/Node fixtures: all kinds, lost
projection and uncertain install/update/remove recovery. Placement is removed
only in a disposable checkout; fixture success is not live canary proof.

Run Deno tests/check/lint/fmt for this directory and
`SWAMP_BIN=/path/to/pinned/swamp python3 -m unittest discover -s extensions/behaviors/operations/tools -p direct_native_test.py`.
Gateway `ToolIntentsTest.php` covers immutable safe admission, start-binding and
first-read ordering, Node identity, bounded results, inventory projection and
reversible writer selection.
