# Native Node worker installation

`extensions/behaviors/node-worker/install.ts` exports `configureNodeWorker(config, credentials, invoke)`. The native parent supplies the reviewed command runner. The callable adds no admission, issuer, SSH proxy, or agent action. Config is the admitted `{node_id, user}` or null for opt-out. A null credential pair preserves the existing root-owned files.

The installer checks available RAM before changes: the pinned 403,378,168-byte Swamp artifact plus 256 MiB. Downloads are staged beside the destination. The pinned SHA-256 is checked on the staged file before publication; a mismatch removes staging and publishes nothing. Nodes1 and18 refuse local Swamp installation.

The configured Node user must already have passwordless sudo. Credentials stay root-owned 0600 below root-owned 0700 `/etc/orbit/swamp-worker` and use systemd `LoadCredential`. A standalone Python prestart checker validates RAM and custody without an agent action. The Node user's private cache stays under `/var/lib/orbit-node-worker`.

The generated unit uses `Restart=on-failure`, `RestartSec=15`, and `StartLimitIntervalSec=0`. A prolonged Serve outage therefore keeps retrying instead of exhausting systemd's start limit. Starts, restarts, and opt-out stops use `--no-block`, so a step does not wait for its own worker to drain. An operator mask is preserved on opt-out. Third-party package work remains outside this worker's sudo authority.

Callers own selection, credential issuance, ordering, and safe deployment. This lower callable changes no live unit until a native parent invokes it. Existing retry-policy rollout does not need to be repeated when this source lands.
