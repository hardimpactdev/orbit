---
title: "Native Node role certificates"
description: "Compose fixed Node role certificate actions in a native parent run."
---

# Native Node role certificates

`roleCertificateSteps(name, leafExpr, fullGlobals, dependency?, guard?)` in `swamp/extensions/behaviors/operations/certificates/role_steps.ts` composes one `node-role-certificate-publish` child. The parent supplies the admitted role leaf and complete official SSH `name`, `transport` and `hosts` globals with the target named `target`, approved identity, and stored host keys. Every action runs on209 through official `@swamp/ssh`; targets need no Swamp worker.

The leaf is the canonical `NodeRoleCertificateInput` value `{node_id,scope,domain,version,root_hash}`. Gateway, Metrics, WebSocket and Analytics retain their fixed paths and public root checks. The child prepares a Node-local key and public CSR, calls the existing parent-bound Gateway signer, and publishes the public certificate beside that same local key. The root run ID reaches the signer unchanged. An already current pair skips signing and is checked again by publication. A refused request, signature or publication fails the child before readiness.

Read the child completion at `steps["<name>"].outputs.result`: `{ready:true,node_id,scope,version}`. Inside the child, the terminal method outputs are flat at `steps.result.outputs`. The optional guard uses Swamp skip semantics: a true guard skips the child. The parent counts that contribution before its own terminal result. The child admits no Operation, projects no database state, and makes no Gateway call except the canonical certificate signing primitive. This lower does not own role removal or restore the whole parent.

The Python role action files are the shared policy source for native actions and retained `roleRequest` and `rolePublish` callers. Private keys remain in the existing fixed Node-local workspace and publication transaction. No private key enters Swamp inputs, resources or logs. The retained callers pass native `extensionFile` into their action factory.

Official `@swamp/cfgmgmt/certificate` was checked at the pinned extension version. It requires private key content and cannot request a CSR or publish with an existing local key path. Official SSH provides the transport; the role assets retain only that unsupported certificate action. The existing Gateway signing model owns CA policy and keeps its private key on the Gateway.

After failed activation and successful pair restoration, resume the failed child with `swamp workflow resume node-role-certificate-publish --run <child-run-id> --from publish`. This repeats publication with its recorded public CSR and signature, then activation and finalization. Resuming activation alone does not republish the restored pair. A pending published pair is not accepted as activated by a fresh admission.
