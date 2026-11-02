# Certificates

The Orbit CA private key lives only in the Gateway. A Node makes its own key and CSR with one `openssl req`; the key never leaves the Node.

`scripts/sign.py` is the only shipped certificate script. It runs on 209 through `command/shell` and is the one step that calls the Gateway: one POST of one CSR to `/api/v1/direct-operations/{operation}/certificates/sign`, then the signed leaf and the Orbit root on stdout as PEM. A refusal fails the step without retry. `@nckrtl/certificate-script-assets.read` returns its path.

Official steps do the rest:

- `route-certificate-publish` installs Route leaves on one Node, with the account copy that development servers read. See [Routes](../../../../../docs/reference/routes.md).
- `route-certificate-remove` removes a withdrawn scope with `cfgmgmt/directory` `ensure: absent`.
- `node-role-certificate-publish` installs a fixed role pair. See [Native Node role certificates](../../../../../docs/reference/node-role-certificates.md).

`certificateActions()` keeps scope removal for Node residue cleanup. `retained_role_policy.py` is source text that the inline Caddy and role removal programs import; it is not a shipped file and goes when those programs convert.
