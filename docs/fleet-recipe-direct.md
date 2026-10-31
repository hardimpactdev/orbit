# Native recipe convergence

`fleet.recipe` starts one native run on `orbit-node-<node_id>`. The admitted Node ID supplies both the target and the Node label. The Gateway records desired input and safe results. Native jobs own ordering, cancellation boundaries, and recovery.

The run has fourteen effect checkpoints: recipe preflight, recipe application, PHP build, nine canonical Node footprint stages, CLI convergence and locked delivery verification. Fixed local system programs inspect and install the recipe and PHP build. Each effect verifies its own result. The final delivery step reads the exact observer binary digest and managed CLI binary and version under the self-update lock. It does not invoke the agent. Footprint and CLI actions run as steps of this run and create no child Operations.

The Node agent hash uses the existing read-only private-material reference for the owning Node. Callback-local private bytes never enter native arguments, resources, history, or public results. Configuration does not rotate or store the hash.

A typed refusal stops later effects and projects failure. An uncertain command fails the native root. The shared failure callback records failure without a domain projection. Every effect reads the current root before dispatch, so a resumed terminal root cannot repeat an incomplete write. A lost result response retries projection without repeating completed effects.

## Development proof

Run `recipe_direct_test.ts` for local command inputs, validation, failure, and secret handling. Run `recipe_direct_native_test.py` with the existing isolated native harness for one root, exact placement for multiple Node IDs, failure, uncertainty and resume, cancellation, and lost projection. The fixture makes no fleet effects. Gateway projection tests cover intent redaction and canonical footprint recording.

After the normal main hot reload, verify a recipe Operation on its exact registered Node worker. Check its native root and step history, final recipe intent, footprint digests and locked delivery. No family child, claim, run, receipt or wait request occurs. An uncertain command must reconcile within the same native root.
