# Certificate actions in native parent workflows

The Orbit CA private key lives only in the Gateway. Swamp holds no CA copy or CA
vault locator. The Gateway signing primitive checks the CSR Node, scope, domain
and root digest against the recorded intent and applies the existing RSA4096 CA,
RSA2048 leaf and 397-day certificate policy. Only public leaf and root
certificates return to Swamp.

The placed `command/shell.execute` model `orbit-certificate-signing-http`
contains the visible signing request script in its `globalArguments.run`. It
makes one POST to `/api/v1/direct-operations/{operation}/certificates/sign` with
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

Use one parent DAG: `roleRequest` on the effect Node, canonical `sign` or
`signBatch` on control209, then `rolePublish` on the effect Node. These methods
belong to `@nckrtl/certificates`. Pass `leaf`, the public `request`, and the
public `signed` bundle with the existing root arguments and effect
`target_node_id`. `signBatch` also accepts role leaves alongside Route leaves.
It uses the same public decoder and Gateway signing policy; there is no CA vault
locator.

The lower methods are `.request(leaf, signal?)` and
`.publish(leaf, request, signed, signal?)`. They return the existing public
Request and Applied shapes. The pending RSA key stays in a root-owned local
directory; publication consumes it locally and removes the pending copy after
success. It never enters arguments, resources or logs.

Publication keeps
`/etc/caddy/orbit-cert-current/{gateway.pem,gateway.key,root-ca.pem}`,
`/etc/caddy/orbit-metrics-cert-current/{metrics.pem,metrics.key}`
`/etc/caddy/orbit-websocket-cert-current/{websocket.pem,websocket.key}` and
`/etc/caddy/orbit-analytics-cert-current/{analytics.pem,analytics.key}`. Each
publication also keeps the public `root-ca.pem` for local renewal checks. An
existing owned version link is accepted only within that role's fixed versions
directory. The shared Caddy lock covers key preparation and each complete
publication transaction, including reload and rollback. The Gateway projects
role state only after the native stages succeed.

## Native leaf actions and the official certificate extension

Standalone leaf workflows receive the complete public intent and return one public presence result through the shared terminal subscriber. They use Swamp command steps for Node-local actions and the official `@swamp/ssh` extension for remote actions. There is no family subscriber, intent callback, status callback or private-key resource.

Official `@swamp/cfgmgmt/certificate` was checked at upstream `d6f7afdcd6d04a63fdbc6ed56c403832770f67a6`. Its check/apply methods require both `certContent` and sensitive `keyContent`. They cannot generate a CSR or publish against an existing Node-local key path. Using that deployment interface would move the leaf private key out of its existing Node-local custody. The remaining scripts use OpenSSL for this specific gap: request a CSR with the local key, publish the returned public certificate beside that key, inspect the pair, or remove the admitted scope. They do not implement SSH, retry, scheduling or signing policy. The Gateway owns certificate policy.

`@nckrtl/certificate-script-assets.read({node_id,intent})` returns `input_json` and `request_script`, `publish_script`, `remove_script`, `inspect_script` and `trust_script`. The manifest ships the Python files. Metadata and command steps use the same worker. Set public `ORBIT_CERTIFICATE_INPUT` to `input_json`; request stdout is `{state:"current"}` or `{state:"request",request_base64}`. `decodeRequest({leaf,stdout})` returns the public CSR record. Publication also takes public `ORBIT_CERTIFICATE_SIGNED`. Inspection takes public `ORBIT_CERTIFICATE_EXPECT_PRESENT` and prints `{present}`. Trust installation and Caddy reload are separate native actions after publication. Current leaves skip signing and publication; native resume keeps the existing candidate key.

Before removal, the parent must finish its Caddy withdrawal and successful reload on that Node. The removal action then runs `caddy adapt` against the current local Caddyfile and refuses scopes referenced by `load_files` or `load_folders`. A failed configuration read refuses deletion. Admission-time `in_use:false` is necessary but not sufficient. No Gateway read supplies this check.
