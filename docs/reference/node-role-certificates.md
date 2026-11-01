---
title: "Native Node role certificates"
description: "Compose fixed Node role certificate actions in a native parent run."
---

# Native Node role certificates

`roleCertificateSteps(name, leafExpr, fullGlobals, dependency?, guard?)` in `swamp/extensions/behaviors/operations/certificates/role_steps.ts` composes one `node-role-certificate-publish` child. The parent supplies the admitted role leaf and complete official SSH `name`, `transport` and `hosts` globals with the target named `target`, approved identity, and stored host keys. Every action runs on209 through official `@swamp/ssh`; targets need no Swamp worker.

The leaf is the canonical `NodeRoleCertificateInput` value `{node_id,scope,domain,version,root_hash}`. Gateway, Metrics, WebSocket and Analytics retain their fixed paths and public root checks. The child prepares a Node-local key and public CSR, calls the existing parent-bound Gateway signer, and publishes the public certificate beside that same local key. The root run ID reaches the signer unchanged. An already current pair skips signing and publication. The native `systemctl try-reload-or-restart caddy` step reloads an active service before readiness and leaves an inactive service stopped, so a fresh admission also recovers a prior failed activation. A refused request, signature or publication fails the child before readiness.

Read the child completion at `steps["<name>"].outputs.result`: `{ready:true,node_id,scope,version}`. Inside the child, the terminal method outputs are flat at `steps.result.outputs`. The optional guard uses Swamp skip semantics: a true guard skips the child. The parent counts that contribution before its own terminal result. The child admits no Operation, projects no database state, and makes no Gateway call except the canonical certificate signing primitive. This lower does not own role removal or restore the whole parent.

The native child exposes CSR creation, signed-pair staging and verification, pair publication, activation, and pending-key cleanup as separate actions. `role_verify.py` verifies the returned public signature before `role_publish.py` changes the serving pair. `role_cleanup.py` removes the pending key only after native activation succeeds; a failed activation retains the workspace for native resume.

The actions reuse the shared certificate `local.py` helpers for key generation, CSR creation and public signature checks. `role_paths.py` owns the fixed role paths. Official SSH copies the shipped scripts and executes each action with `python3 -B`; public intent and signed certificates use the existing certificate environment inputs. Private keys remain on the target. The child has no custom pair snapshot, restoration or finalization engine.

Retained local callers still use their existing guarded helper library. It is separate from the scripts shipped to the new child. Their action factory reads the same canonical role paths through native `extensionFile`. Those helpers retire with their owning caller migration.

Official `@swamp/cfgmgmt/certificate` was checked at the pinned extension version. It requires private key content and cannot request a CSR or publish with an existing local key path. Official file/directory models provide owner and mode handling, but their pinned schemas lack the admitted full host-key list; file content also cannot refer to a private key kept only on the target. Official SSH provides the transport; the role assets retain only that unsupported certificate action. The existing Gateway signing model owns CA policy and keeps its private key on the Gateway.

After failed activation, resume the failed child with `swamp workflow resume node-role-certificate-publish --run <child-run-id> --from activate`. Native retry uses the already published local pair. The complete parent owns restoration of its admitted publication snapshots. Reissuing a certificate for the same admitted role/domain does not require restoring a previous private pair.
