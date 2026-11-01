# Empty-workspace TaskCancel proof

`no-workspace.json` drives normal Project and Task group creation, then `tasks:cancel` through the CLI, Gateway, native workflow and normal projection. It creates no task VM or workspace. The expected admitted input checks that empty teardown accompanies the absent workspace. The native `withdrawn` assert accepts the empty Route list without reading outputs of the skipped Route child.

Parity uses only the frozen `PROOF_EXEC`, `PROOF_OPERATION_ID` and `PROOF_OUTPUTS` inputs. It compares the native result with the projected Operation and reads the prepared group through normal `tasks:show`. No direct database, Swamp datastore, vault, host or provider mutation is part of this variant.

The topology Gateway must have Tasks enabled through its normal ai-router and Tasks setup before `prepare`. A fresh Gateway with Tasks disabled refuses `tasks:create`; this fixture does not change extension state, inject management keys or bypass admission. The shared topology seed owns this prerequisite.

The receipt remains pending the shared F13 topology runner. This variant covers the empty-scope native assert and terminal projection. The existing seven native DAG tests separately cover withdrawal success, public `ready:false` blocking VM deletion, stopped provider behavior and teardown failure. A full routed-workspace/provider cleanup variant needs the TaskVM lifecycle topology and is not claimed here. #2320 used C’s urgent live-fix exception and has no F13 fixture to reference.

The provisional budget is 60 seconds, with the workflow’s explicit 60-second queue timeout. Production p95 is not measured. Keep #2323 draft until the required receipt exists.
