# Direct Tools intent

`operation-tool-direct` runs `tool.install`, `tool.update`, `tool.remove`,
`tool.adopt` and `tool.manager_converge` through the
[shared direct Operation boundary](direct-operations.md). The Gateway declares
one workflow, `writer_config: swamp.direct_writers`, immutable `tool_writer` and
`ToolIntentProjection`. It uses `StartSwampRun` and the one
`ORBIT_SWAMP_RUN_TOKEN`. No family token, controller, request, route, primitive
or Gateway execution relay is added.

The Gateway admits package identity, enrolled account, agent pin and public
recipe data. Its read projection exposes only those safe inputs. Native Node
policy selects fixed typed commands from the closed manager registry. Result
projection stores bounded manager/package versions and refusal codes; it never
stores manager stdout or execution receipts.

The canary uses the existing Node209 `pool=migration` workers. Admit canary
intent only for Node209. Converted kinds have one direct dispatch path; their
legacy discovery and executor registrations are removed. When per-Node
workers are available, place the action on exact `orbit-node-<admitted-node-id>`
before fleet takeover. `tools-node-<id>` is a stable behavior identity for
native scope serialization. The workflow uses `writes: true`, `vaults: []` and
native placement, concurrency and retention defaults. There is no placement
fallback or polling schedule.

## Policy and native recovery

The direct and retained writers share `policy.ts`: constraints gate normal
candidates, install refuses silent adoption, adoption never mutates, and removal
requires only the exact recorded root with no autoremove. Manager
materialization is part of the native action, rather than a Gateway child
Operation. macOS verification preserves the enrolled account's existing scope.

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

The worker runs as the Node's configured user with its existing passwordless
sudo. Fixed policy commands run through the admitted, root-owned local agent;
the step checks the binary path and digest before launch. Linux mutations and
manager materialization start via `sudo -n --`; the frozen agent supervises the
command under its declared root or enrolled-account identity. Account reads and
macOS account commands run as the configured worker user. The existing package
sandbox UID is not changed and gains no sudo.

The agent and workflow enforce time limits. Streamed output is bounded to4MiB;
stderr, refusal details and malformed output never enter native resources. A
started mutation finishes under its agent deadline even if workflow cancellation
arrives; native recovery reconciles uncertain outcomes. No socket, broker,
account, sudo rule, token, grant or runtime unit is installed by this family.

Each kind ships its direct start, writer selection and Gateway execution deletion
in one slice. Its converted kind is also removed from legacy discovery and
executor registration. Rollback is a revert PR. If the owning writer needs a
release, merge first, then canary immediately against a test target and revert
on failure. The shared boundary lane owns final executor API deletion.

All five Tool kinds use the direct workflow. The legacy `operation-tools`
schedule, discovery model, slot models, executor registration, claim runner and
receipt schemas are retired. Existing native history and data are preserved;
source retirement does not run `swamp workflow delete`.

The Gateway records the bounded constraint string. `constraints.ts` is the
Tool range evaluator in Swamp and uses the existing node-semver dependency for
comparisons. Composer numeric-range forms retain their bounds, including zero
major carets, two-part tildes, exclusions and prereleases. Swamp rejects malformed
ranges before any manager or package command. No compiled intervals cross the
Gateway boundary.

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
refusal of a retired polled writer.
