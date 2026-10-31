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

## Standalone native command workflows

`certificate.converge` and `certificate.remove` receive their complete public
leaf intent as workflow inputs. The Gateway uses `workflow_inputs` and a fixed
`terminal_result` selector. Neither workflow reads intent or posts status from a
worker. Their final inspection step prints the public `{present}` result; the
shared terminal subscriber reads native history and projects it once.

The certificate script manifest ships one request, publication, removal and
inspection entry point plus their local certificate functions. A pure metadata
step resolves the cached paths and serializes public input. Each effect is one
placed builtin shell step. Signing remains the sole Gateway call. The request
and publication steps keep the private key on its effect Node. No CA vault or
execution-secret copy is needed by these standalone workflows.

A current valid leaf skips native signing and publication with workflow guards.
Failures skip subsequent actions and are projected from the terminal run; native
resume is the recovery path. Nodes 1 and 18 remain blocked for builtin shell
until the shared proxy transport is ready. Existing parent lower methods remain
owned by their parent rewrites; standalone workflows no longer use them.

The metadata behavior is `@nckrtl/certificate-script-assets`. Call
`read({node_id, intent})` with a `Leaf`, `Removal`, or `Scope`. It returns
`input_json` and `request_script`, `publish_script`, `remove_script`, and
`inspect_script` paths. Pass `input_json` as `ORBIT_CERTIFICATE_INPUT` under
`task.inputs.env`. The request decoder is `decodeRequest({leaf, stdout})`; its
`record` output is `{node_id, scope, state, request_base64?}`. Malformed or
private output produces only `certificate.request_invalid` and no resource.

Removal requires admitted `in_use: false`. Inspection also takes the public
`ORBIT_CERTIFICATE_EXPECT_PRESENT` variable (`"true"` or `"false"`). A mismatch
prints `certificate.presence_mismatch` and exits nonzero. Invoke scripts with
`sudo -n --preserve-env=ORBIT_CERTIFICATE_INPUT /usr/bin/python3 "<cached-path>"`,
including any other named public variables in the preserve list. Metadata and
effect steps must use the same physical worker.
