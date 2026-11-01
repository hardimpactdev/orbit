# Role parent proof inputs

These v0 fixtures come from the real `AdmitNodeRoleOperationAction` admission tests in `NodeRoleParentNativeTest.php`. Set `SWAMP_PROOF_CAPTURE_DIR` to regenerate the public envelopes. Only disposable environment fields are replaced by the binding names in B’s `swamp-proof-fixture-v0.md`. The source fixtures contain no fleet credentials.

They are draft inputs, not successful F10 receipts. Do not run them as proof until the following inputs are finalized with B:

- The capture test’s placeholder observer download and CLI checksum must be replaced by actual published artifacts. The agent CA must be a disposable proof CA. The observer points only at a closed loopback endpoint here.
- WebSocket relocation requires distinct source and target Nodes plus an isolated certificate signer and complete publication participants. V0 supplies only one target host binding. The current target binding in the source fields is an explicit placeholder, and cannot prove a cross-Node move. No fleet host is a substitute.
- B must provide disposable source/signer bindings or the supported setup contract. The runtime-material file is a throwaway test value, never a fleet credential.

The parity commands inspect the public native terminal receipt. The source parity tests also cover admission identity and secrets (`NodeRoleParentNativeTest.php` and `NodeRoleRelocationOperationTest.php`), created-only WebSocket cleanup (`node_role_parent_native_test.py`), and source withdrawal ordering. F10 must additionally check the real target service/files through the runner’s supplied SSH prefix before merge.

`node-role-parent-timings.json` records local native measurements and limits. They exclude real download/package/network time, so these budgets remain provisional until F10. Queue timeout is 30 seconds for both roots. Runtime prerequisites, forward role/runtime/certificates/publication, DNS withdrawal grace and source cleanup retain the legacy order. The existing native WebSocket lower owns activation restoration and release cleanup; the add parent removes only a newly-created runtime after later publication failure. Terminal source cleanup alone retires source credentials.

## Remaining legacy phases

| Role | Add sequence still to port through the shared factory | Singleton relocation |
| --- | --- | --- |
| Gateway | prerequisites; private DNS; Caddy VPN ordering; runtime/observer; certificates/publication; firewall; CLI | target forward proof; existing publication withdrawal; native DNS grace where applicable; source runtime and credential cleanup |
| AppDev | storage and hibernation paths; role prerequisites; PHP; Composer/JavaScript managers; runtime/publication; footprint; CLI | not relocatable |
| AppProd | canonical PHP/JavaScript/Composer prerequisites; Caddy VPN ordering; publication/firewall; runtime footprint; CLI (Nodes owner’s increment pending) | not relocatable |
| Metrics | Metrics runtime and credentials; exporter fleet contribution; publication/firewall; footprint; CLI | target contribution, then source publication and old-owner cleanup while retaining shared exporters (#2278) |
| Analytics | managed ClickHouse/Postgres preparation; Plausible runtime; certificates/publication; firewall; footprint; CLI | not relocatable |
| CiRunner | canonical host/project preparation; prerequisites; observer/footprint; firewall; CLI | not relocatable |

Database, Ingress and WebSocket forward contributions are available from dependency #2226. WebSocket is the only currently supported singleton relocation. VPN and Router are protected from generic mutation. Unsupported contributions refuse before assignment and vault effects; they are not claimed migrated by this draft.
