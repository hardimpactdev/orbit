# Certificates

The Orbit CA private key lives only in the Gateway. A Node makes its own key and CSR with one `openssl req`; the key never leaves the Node.

`@nckrtl/certificate-sign` (`sign.ts`) is the one step that calls the Gateway. It runs on 209 and sends one POST of one CSR to `/api/v1/direct-operations/{operation}/certificates/sign`, trusting only the Orbit root. It returns the signed leaf and the Orbit root as PEM in `stdout`. A refusal fails the step without retry. No certificate script ships.

Official steps do the rest:

- `route-certificate-publish` installs Route leaves on one Node, with the account copy that development servers read. See [Routes](../../../../../docs/reference/routes.md).
- `route-certificate-remove` removes a withdrawn scope with `cfgmgmt/directory` `ensure: absent`.
- `node-role-certificate-publish` installs a fixed role pair. See [Native Node role certificates](../../../../../docs/reference/node-role-certificates.md).

Native Node roots freeze certificate scopes and publication plans at admission. Official file and directory steps apply or withdraw those scopes, and the native signer owns leaf material.
