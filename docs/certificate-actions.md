# Certificate actions in native parent workflows

Use `certificateActions()` from `extensions/behaviors/operations/certificates/actions.ts` on the leaf's admitted Node worker. Its `request`, `publish`, `inspect` and `remove` methods run fixed system programs directly. The private leaf key stays on that Node.

For a parent with several leaves, place `requestAll` on each effect Node, one `signBatch` step on control Node 209, then `publishAll` on each effect Node. All three methods belong to `@nckrtl/certificates`. They run in the parent's native DAG and create no child Operations.

The control step uses the canonical model name `orbit-certificate-signing`, with target `orbit-node-209` and label `node: '209'`. Its global arguments `vaultName` and `secretKey` identify the approved Swamp vault entry. Only the custody owner configures those identifiers. Effect models need neither field. Signing fails with `certificate.ca_custody_unconfigured` when the control model has no locator; it has no Gateway signing fallback.

`signBatch` takes the parent's `operation_id`, `kind`, `node_id`, and `run_id`, plus `leaves` (the complete admitted leaf array) and `requests` (the array of per-Node request arrays). The root `node_id` remains the parent Node; the step placement is always 209. It returns the existing `signedBatch` resource:

```ts
{ certificates: [{ node_id, scope, certificate_base64, root_certificate_base64 }] }
```

A single-leaf workflow uses `sign` with `leaf` and `request`; it returns the existing `signed` resource. Current leaves skip signing and need no vault read.

The approved vault entry contains JSON with `certificate` (the public root PEM) and `private_key` (the existing CA key PEM). The method reads it inside the placed step through `context.vaultService`. The fixed OpenSSL program receives it on stdin and uses anonymous Linux memory files. It writes no CA key to disk, arguments, workflow data, resources or logs. Only public certificate bundles leave the step. The signer requires an RSA 4096 CA, an admitted RSA 2048 CSR, matching Node, scope, domain and root digest, and the existing 397-day TLS leaf policy.

Lower consumers can import `signCertificateBatch(leaves, flatRequests, {vaultService, vaultName, secretKey}, signal?)` from `certificates/signing.ts`. Parent families should use the canonical model rather than define another CA locator or signing primitive. Live custody migration requires the approved owner and vault identifiers; disposable CA tests need no fleet access.
