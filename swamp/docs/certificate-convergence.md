# Native certificate steps

`certificate.converge` uses one native workflow with request, sign, publish,
inspect and result steps. `certificate.remove` uses remove, inspect and result
steps. Effects run on the exact admitted Node worker with sudo. Signing runs on
control Node 209. Swamp owns placement, locks and retries.

Route parent workflows call the same `@nckrtl/certificates` methods in their own
DAG. A convergence leaf is
`{node_id, scope, user, domain, version, root_hash, agent_sha256}`. A removal
leaf is `{node_id, scope, user, agent_sha256, in_use:false}`, admitted after
withdrawing all stored sites that name the scope. Both accept optional `home`.
The fixed local program resolves the admitted username with `getent passwd`
before request, publication, inspection or removal. It requires the account's
owned home directory and rejects any supplied home that differs. The Gateway
admits database state and the public root; it performs no account lookup. The
resolved home stays local.

Every method receives the root `{operation_id, kind, node_id, run_id}` and
`target_node_id` when the effect Node differs from the root. Placement and the
Node label use the effect Node. `request` returns `{state:"current"}` or
`{state:"request",request_base64}` containing only a public CSR. `publish`
accepts `leaf`, `request` and `signed`, skips a current leaf and returns
`applied`. `inspect` accepts the scope and `previous: applied`, and returns
`presence`. `remove` refuses `in_use:true`.

A parent places `requestAll` on each participant, one `signBatch` on control209,
then `publishAll` on each participant. The signer uses the canonical
`orbit-certificate-signing` model and its approved Swamp vault locator. It
receives the complete admitted leaves and nested participant request arrays,
then returns
`{certificates:[{node_id,scope,certificate_base64,root_certificate_base64}]}`.
`publishAll` matches requests and signatures by Node and scope and requires
final presence. `removeLeaf` removes and inspects one local leaf. These
participant methods never project parent status. See
[Certificate actions](certificate-actions.md) for the canonical signer and
role-pair interfaces.

Leaf private keys stay on the effect Node. The existing CA private key stays
inside the control209 vault signing step. Only public CSRs and certificate
bundles enter native resources. There is no Gateway signing primitive, CSR
checkpoint or child Operation. No new secret type is introduced.
