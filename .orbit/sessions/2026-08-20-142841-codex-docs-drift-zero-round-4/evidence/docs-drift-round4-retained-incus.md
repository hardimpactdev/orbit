# Docs Drift Round 4 Retained Incus Proof

- candidate: `a7d08f385c7a22d0b6a6c5ad031efa832c914b6e`
- venue: `retained-incus`
- environment: `dev-fixture`
- topology_id: `dev-c9b4ac`
- host: `beast`
- operator_vm: `orbit-e2e-dev-c9b4ac-operator`
- gateway_vm: `orbit-e2e-dev-c9b4ac-gateway`
- Solo terminal: process `2530` (`round4-retained-incus-proof`)
- result: `passed`

## Boundary and identity

`bin/orbit-secret-scan` returned `SECRET_SCAN: PASS`. No
`composer test:e2e*` command was run.

The local worktree reported candidate
`a7d08f385c7a22d0b6a6c5ad031efa832c914b6e`. The operator launcher resolved
to `/home/orbit/orbit-run/apps/cli/orbit`. The operator and gateway copies of
`apps/gateway/app/Services/Proxy/ProxyRouteQuery.php` had SHA-256
`c3e83998d1de5e3d895881dc506a6bc405e4f66f4f2a2dab187b7f23fb89ee04`,
which matched the local candidate. The operator copy of the proxy domain doc
also matched the local candidate at SHA-256
`f091c838e660d65b3a71963a47dd7d07c1b960106da9cb790ccf2118bf5bf7f1`.

## Isolated registry fixture

The fresh topology initially returned an empty proxy registry. Gateway
maintenance then inserted two isolated rows on `app-dev-1`:

```text
invalid-round4.test => app
valid-round4.test   => custom
```

The valid custom route used `kind=proxy` and upstream
`http://127.0.0.1:8080`. The invalid compatibility tuple used `owner_type=app`,
`kind=app`, and null `app_id`, `instance_id`, and `workspace_id`. This state is
not creatable through the public command surface and was inserted only to
exercise invalid persisted compatibility data.

## Public list behavior

From the Solo terminal, the operator ran:

```bash
orbit proxy:list --json
orbit proxy:list --filter=instance --json
orbit proxy:list --filter=custom --json
```

The default result returned one route and omitted `invalid-round4.test`:

```json
{"success":{"data":{"routes":[{"domain":"valid-round4.test","kind":"proxy","owner":{"type":"custom","name":null},"node":"app-dev-1","target":{"type":"upstream","value":"http://127.0.0.1:8080"},"redirect_code":null,"tls":{"managed_by":"orbit","trusted_by_gateway_ca":true},"status":"unknown"}]},"meta":{"filter":"all","node":null,"count":1}}}
```

The instance filter also omitted the invalid tuple:

```json
{"success":{"data":{"routes":[]},"meta":{"filter":"instance","node":null,"count":0}}}
```

The custom filter retained the valid route with `count=1`.

## Raw compatibility state remains available

After all three public reads, a gateway-side read showed the invalid tuple was
still stored without mutation:

```text
domain       = invalid-round4.test
owner_type   = app
kind         = app
app_id       = null
instance_id  = null
workspace_id = null
```

This proves the candidate hides invalid instance-backed tuples from public list
rendering without deleting or normalizing the raw ownership data used by
conflict and removal paths.
