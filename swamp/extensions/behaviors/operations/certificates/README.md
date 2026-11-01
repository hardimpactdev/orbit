# Certificate actions in native parent workflows

The Orbit CA private key lives only in the Gateway. Swamp holds no CA copy or CA
vault locator. The Gateway signing primitive checks the CSR Node, scope, domain
and root digest against the recorded intent and applies the existing RSA4096 CA,
RSA2048 leaf and 397-day certificate policy. Only public leaf and root
certificates return to Swamp.

The shipped `scripts/sign.py` owns the signing request. Public metadata resolves
its `sign_script` path; placed `command/shell.execute` steps run it with Python
`-B`, each under a run-specific behavior name. The shared command model contains
only this command. Workflow inputs cannot choose the script or Gateway origin.
The script makes one POST to `/api/v1/direct-operations/{operation}/certificates/sign` with
`run_id` and public `requests`. Current leaves require no request. No key,
token, private input callback, binding wait or retry is involved. The fixed
Gateway origin and numeric Operation path cannot be selected by workflow input.
The TLS client verifies the existing managed CA.

Existing parent DAGs place this HTTP step on control209, then pass its `stdout`
to the canonical `orbit-certificate-signing` model's pure `sign` or `signBatch`
decoder as `native_stdout`. `sign` also takes `leaf` and `request`. `signBatch`
takes `leaves` and nested participant `requests`. The decoder returns the
existing `signed` or run-scoped `signedBatch` public resource, so publication
remains on the admitted effect Node.
`signCertificateBatch(leaves, flatRequests, native_stdout)` only decodes public
output; it reads no vault and launches no program.

The batch resource is
`{certificates:[{node_id,scope,certificate_base64,root_certificate_base64}]}`.
Decoding refuses missing, duplicate, foreign or private output. The Gateway owns
signing authorization and certificate policy. The worker never chooses a domain
outside the admitted intent.

`certificateActions()` still owns the local
request/publication/inspection/removal lower while those actions move to visible
native steps. Admit `user`; `home` is optional and must equal local
`getent passwd` resolution if supplied. Leaf private keys stay on their Node.
The Gateway performs no account lookup.

This signing change is the narrow root-of-trust correction. Remaining
grandfathered local action programs and parent intent/result callbacks are
removed in their family rewrites; they are not part of this exception.

## Node role pairs

Gateway, Metrics, WebSocket and Analytics use `roleCertificateActions()` from
`certificates/role-actions.ts`. Admit
`{node_id, scope, domain, version, root_hash}`, where `scope` is `role-gateway`,
`role-metrics`, `role-websocket` or `role-analytics`, `version` is 16 lowercase
hex characters and `root_hash` is the SHA-256 digest of the public root PEM.
`role-analytics` uses the reserved `analytics.orbit` hostname
(`AnalyticsHostname::Value` in Orbit). Paths are fixed by scope; callers cannot
supply paths or private material.

New parents compose the `node-role-certificate-publish` child with `roleCertificateSteps(name,leafExpr,fullGlobals,dependency?,guard?)` from `certificates/role_steps.ts`. Supply the canonical role leaf and complete official SSH `name`, `transport` and `hosts` map with target `target`, approved209 identity and full pins. Read `steps["<name>"].outputs.result` for `{ready:true,node_id,scope,version}`. Every child action runs on209. The signer receives the root parent run ID.

The native child exposes separate `scripts/role_request.py`, `scripts/role_verify.py`, `scripts/role_publish.py`, activation and `scripts/role_cleanup.py` actions. Verification stages the signed public pair without changing the serving pair. Pending-key cleanup depends on successful activation, so failure preserves the workspace for native resume. They share the existing certificate `scripts/local.py` key, CSR and public signature helpers. `scripts/role_paths.py` owns the fixed paths for native and retained callers. Official SSH copies the shipped script directory and executes each action. The retained guarded helpers live in `retained_role_policy.py`, outside that directory.

The RSA key stays on its effect Node. Only the CSR and signed public certificates enter Swamp resources. A native `systemctl try-reload-or-restart caddy` action reloads an active service before readiness and leaves an inactive service stopped. This also runs for a current pair, so fresh admission retries an interrupted activation. Failed activation stays failed; resume the child from `activate`. The complete parent owns restoration of its admitted publication snapshots. The child has no pair snapshot or automatic restoration engine. The complete contract is in [Native Node role certificates](https://github.com/hardimpactdev/orbit/blob/main/docs/reference/node-role-certificates.md).

Publication keeps
`/etc/caddy/orbit-cert-current/{gateway.pem,gateway.key,root-ca.pem}`,
`/etc/caddy/orbit-metrics-cert-current/{metrics.pem,metrics.key}`
`/etc/caddy/orbit-websocket-cert-current/{websocket.pem,websocket.key}` and
`/etc/caddy/orbit-analytics-cert-current/{analytics.pem,analytics.key}`. Each
publication also keeps the public `root-ca.pem` for local renewal checks. An
existing owned version link is accepted only within that role's fixed versions
directory. Retained local callers use the existing Caddy lock around their
transaction. The native child uses explicit publication and activation
steps. The Gateway projects role state after those stages succeed.

## Native leaf actions and the official certificate extension

Standalone leaf workflows receive the complete public intent and return one public presence result through the shared terminal subscriber. They use Swamp command steps for Node-local actions and the official `@swamp/ssh` extension for remote actions. There is no family subscriber, intent callback, status callback or private-key resource.

Official `@swamp/cfgmgmt/certificate` was checked at upstream `d6f7afdcd6d04a63fdbc6ed56c403832770f67a6`. Its check/apply methods require both `certContent` and sensitive `keyContent`. They cannot generate a CSR or publish against an existing Node-local key path. Using that deployment interface would move the leaf private key out of its existing Node-local custody. The remaining scripts use OpenSSL for this specific gap: request a CSR with the local key, publish the returned public certificate beside that key, inspect the pair, or remove the admitted scope. They do not implement SSH, retry, scheduling or signing policy. The Gateway owns certificate policy.

`@nckrtl/certificate-script-assets.read({node_id,intent})` returns `input_json` and `request_script`, `publish_script`, `remove_script`, `inspect_script` and `trust_script`. The manifest ships the Python files. Metadata and command steps use the same worker. Set public `ORBIT_CERTIFICATE_INPUT` to `input_json`; request stdout is `{state:"current"}` or `{state:"request",request_base64}`. `decodeRequest({leaf,stdout})` returns the public CSR record. Publication also takes public `ORBIT_CERTIFICATE_SIGNED`. Inspection takes public `ORBIT_CERTIFICATE_EXPECT_PRESENT` and prints `{present}`. Trust installation and Caddy reload are separate native actions after publication. Current leaves skip signing and publication; native resume keeps the existing candidate key.

Before removal, the parent must finish its Caddy withdrawal and successful reload on that Node. The removal action then runs `caddy adapt` against the current local Caddyfile and refuses scopes referenced by `load_files` or `load_folders`. A failed configuration read refuses deletion. Admission-time `in_use:false` is necessary but not sufficient. No Gateway read supplies this check.

Standalone workflows run every step on Node209 and use the admitted approved SSH identity path and pinned target host keys through official `@swamp/ssh` defaults. No target Node needs a Swamp binary or worker. They create and copy a run-specific public script directory, run each action as an official SSH exec step, and remove that directory on success. Failed roots retain their scripts for native resume. No worker key, CA copy, SSH wrapper or connection supervisor is introduced. Node1/18 use this same official transport. The full public envelope is `{intent:{leaf,control_node_id:209,connection,host_keys},execution_secrets:{}}`; the stored leaf input and signing authorization remain unchanged.

Standalone removal reloads the disk configuration before its fresh scope check. Reload failure prevents deletion. Parent workflows using the lower must supply the same successful-reload dependency. Script metadata uses the required resource declaration fields with the standard infinite lifetime and ten-version retention.

Run every certificate action with `python3 -B`. This prevents privileged Python from creating root-owned bytecode in shared asset directories. Cleanup stays unprivileged and removes only the run-specific public scripts after successful inspection. Parent workflows must preserve this invocation flag.
