# Direct Conn pairing creation

Only `conn.pairing_create` starts `operation-conn-direct`. Its owning Gateway
slice selects the direct writer and removes the PHP issue command, pairing HTTP
relay and receipt tests. Other Conn and Vault kinds retain their existing paths
until their own takeover. Rollback reverts the owning PR.

Workflow input contains only Operation, kind and Node identifiers. The shared
private-material endpoint requires the Gateway-recorded run, exact caller Node
and unfinished Operation; every response is no-store. The effect-only callback
keeps the admin session and generated credential in memory. The worker observes
safe issuing/link/expiry metadata, then calls shared secret-output so the
existing ConnPairingCredentialStore enforces owner, issue and link checks and
encrypts the credential. Public results, resources and logs contain no
credential bytes.

The safe issue_started flag comes from Gateway-recorded issue metadata. Even if native checkpoint data is lost, an already started issue without a stored credential refuses before a second T3 link can be minted.

The job targets `orbit-node-<node_id>` with the matching Node label. It cannot
use another Node or a local execution fallback. The active Gateway must have its
configured Node worker before pairing creation takes over.

The native behavior and checkpoint are keyed per Operation. An uncertain issue
without a saved credential fails instead of creating another link. A stored
credential recovers a lost completion without issuing again. The approved
HTTP201/read-once pairing and bounded wait remain unchanged.

The shared secrets base is merged; each owning credential consumer still
requires orchestrator approval. If its direct writer requires released code,
merge after approval, verify auto-release/source load, then canary within minutes
on a disposable or test target on Node 209 or dev. Revert at once on failure;
no separate candidate Gateway is required. Swamp main loads on Node 209 on merge,
at most once per five minutes.

Each family's first canary must use the real `gatewayHttp` client and scan the
whole scratch tree and captured logs for private values. Record old/new
native-start and first-projection timings without private bytes. Injected Http
fixtures are regression evidence and do not satisfy this canary requirement.
No shared source merge implies family activation or runtime readiness.
