# Instance clone

`operation-instance-clone` is one native DAG. The Gateway records immutable source and destination placements. The `admission` step reads the source Node from that intent; `candidate` runs on that exact worker. Every destination and failure step uses the admitted destination worker. All callbacks share the root run UUID.

Candidate inspection verifies clean Git source and repository commit availability without changing the candidate. Destination steps create the fixed production account, prepare the initial checkout, resolve the admitted source, classify it, write stored environment values, prepare Vite+ dependencies, and record the completed Instance. Shared local source, production-home, and environment behaviors perform system actions directly. No agent command is invoked.

The initial Gateway declaration refuses Projects that need Route publication, database attachments or SQLite seeding, Process/Schedule definitions, or VM placement. The DAG does not silently skip those features.

The candidate reads only its public source intent and approved Git material. Environment material remains at the destination. Private values stay inside effect callbacks and never become workflow resources. The Gateway participant boundary enforces these scopes.

Candidate failure prevents all destination effects. Cooperative cancellation records a cancelled result. Per-stage failure steps record the bounded failed stage; the shared native failure reporter closes an Operation if the family cannot issue its first callback. A recorded terminal result remains unchanged.

Run the DAG proof with the pinned native binary:

```sh
SWAMP_BIN=/usr/local/bin/swamp python3 -m unittest extensions/behaviors/operations/instance-lifecycle/clone_native_test.py
```

The shared source and production-home tests exercise real Git, local ownership, path checks, and production layout changes. The DAG harness substitutes only those effects and Gateway I/O; it preserves step order, expressions, failure branches, and the native root identity.

A candidate must have a recorded source profile before clone admission. Native registration uses the shared classifier to return only `php_version` and `laravel`, alongside its Git identity. The Gateway stores those fields, so clone can copy stored environment values through the existing owner checks. Unsafe metadata refuses registration before adoption. A non-PHP checkout records `php_version: null` and `laravel: false`.

Vite+ preparation uses the canonical deploy lower inside the parent run. The Gateway admits platform, architecture and the public Vite+ recipe. The worker reads Project manifests as the application account, materializes the manager as the Node account, installs missing exact runtimes without Project hooks, and verifies the store. Registry settings remain in local memory. Public checking, active and failed runtime references are projected before and after installation. No preparation child Operation or agent action is used.
