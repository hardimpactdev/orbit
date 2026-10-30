# Convert an Orbit operation family

The Gateway validates requests, records intent and projects results. Swamp
executes the work. Use the shared
[direct Operation template](direct-operations.md). The former claim, lease,
dispatcher and keyed-receipt recipe is retired.

1. Keep the family's API validation and owning admission. Declare its fixed
   workflow and projection in the Gateway family's `direct-operations.php`.
2. Run the Node action in a Swamp workflow with native placement, locking,
   recovery and defaults. Root actions use the Node user's existing passwordless
   sudo. Add no Gateway execution relay or orchestration.
3. Prove each kind on Node 209 or dev before takeover, including the native
   start response, run binding, admitted input read, real action and final
   projection. Record both Gateway and native outcomes. Preserve failures and
   recovery evidence.
4. In one owning PR per kind, select the direct writer and delete the replaced
   Gateway contract, PHP executor, polled discovery/claim/run registration and
   polled writer option. Keep admission and projection tests. One kind has one
   dispatch path. Rollback is a revert PR.
5. Once every kind in a polled family has moved and its release is live, retire
   that family's polling workflow, discovery and slot definitions. Preserve
   native history and existing data; `swamp workflow delete` also deletes
   history and is not a source-only retirement command.

Firewall uses `operation-firewall-direct` and
`extensions/behaviors/operations/firewall/direct.ts`. It has no discovery
schedule, claims, Gateway agent-run contract or keyed receipts. The Node 209
migration pool remains its temporary canary placement until the per-Node workers
are ready. Other Nodes must use their own exact worker before their admission
scope expands.

## Tests

Prefer `swamp workflow validate` and unit tests for family behavior. Native
execution tests use `tests/native_harness.py::NativeTestCase`; migrate an
existing family fixture when touching it instead of copying another harness.
Call `super().setUp()` for an isolated scratch repository, then
`copy_behaviors(*families)`. `cli(*args, success=True, input=None)` runs the
binary selected by `SWAMP_BIN` there, clears inherited serve connection fields,
and checks its exit status. Use the repository's pinned binary.

Set `native_env` for explicit fixture values and `secrets` to a tuple of private
sentinels. Every CLI call checks stdout, stderr and every regular scratch file,
including native history, logs and bundles. Family files retain their fixture
injection, workflow placement checks and domain assertions. The harness leaves
workflow placement intact; a local fixture is not a serving-worker canary.
