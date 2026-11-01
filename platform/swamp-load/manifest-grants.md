# Manifest grant load step

`manifest_grants.reconcile` applies the loaded `swamp/config/gateway-workflow-grants.json` through existing native access commands. It backs up policy, creates only missing exact actions, preserves prior grants/groups, verifies readback and reloads access policy only after creation. An uncertain mutation retains a checkpoint for inspection; it is not repeated automatically. Applied policy can receive a read-only recheck.

The current loader lives outside this repository. `manifest-grants.patch` is its exact call-site change: in the shared accepted-feed consumer, after successful runtime publication and before either consumption path, inside the existing Gateway and host interlocks. Install the module beside `/usr/local/libexec/orbit-swamp-main-load.py` and adopt the patch in the normal authorized loader update after merge. The separate byte-for-byte loader import PR must not be required to merge this step first.

This change adds no restart, health loop, workflow lookup, token, credential, lock, Gateway PHP proof or business admission. Existing configured-token verification and runtime acceptance procedures stay with the current load owner. Source merge alone does not install the patch or establish runtime acceptance.
