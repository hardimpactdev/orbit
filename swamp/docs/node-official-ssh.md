# Official SSH actions for light Nodes

VPN1 and usexit18 actions use the official `@swamp/ssh` extension from the normal Node209 control worker. Use package `2026.10.23.1` (model `2026.10.20.1`), pulled with `swamp extension pull @swamp/ssh@2026.10.23.1`. The package is supplied by `swamp-club/swamp-extensions/ssh`; do not vendor or wrap its transport. Core command/shell runs on the executing worker. The official SSH extension supplies the remote execution that light Nodes need.

Workflow overrides belong under `task.globalArgs`, with the complete `name`, `transport` and `hosts` map. A step-level `globalArgs` is invalid. Use `task.inputs` for method arguments. A stable `behaviorName` keeps native model serialization. `hostKeys` implies the official pinned policy; other transport settings keep the official defaults.

The canary keeps its existing workflow UUID. It has separate identity, directory, write, read, remove-file and remove-directory steps. All execute on `orbit-node-209` with the string Node209 label. `exec` receives public argv; `script` sends its body over stdin. Root effects use `sudo: true`. Each run has its own runtime directory. Cleanup follows failed read/write outcomes through native dependencies.

Node209’s `orbit-node-worker.service` loads the approved existing key with `LoadCredential=proxy-ssh-identity:/etc/orbit/node-proxy/id_ed25519`. Official SSH uses `identityFile: /run/credentials/orbit-node-worker.service/proxy-ssh-identity`. Only Node209 receives this credential. Systemd exposes it to the configured worker account after restart; the source key stays root-owned 0600. Worker-wide read scope is approved. This uses the same key and existing VPN1/usexit18 access edges, with no new vault, grant or key.

Script environment uses OpenSSH SendEnv. Remote AcceptEnv and, for root scripts, sudo env_keep must allow each variable. The canary needs neither. Keep secrets out of argv and script bodies; use only the official stdin or documented environment support.

## Proxy worker removal plan

1. Publish and prove the official canary on VPN1, then usexit18. Move all remaining family callers to official SSH steps on a normal control worker.
2. Account for active, queued and paused roots still selecting the old logical Node1/18 workers. Their owners decide when those roots finish or are retired; preserve history and pauses.
3. After the old selectors have no remaining consumers, stop and disable only the two proxy worker units on209 through the approved owner route. Keep local light-Node workers masked.
4. Remove the obsolete proxy installer/renewal source and selections, then let the sole issuer retire only their worker credentials. Preserve the approved SSH key and public host keys for official SSH. Do not delete the whole proxy directory.

This is a removal plan, not authorization to stop units, revoke credentials or change key custody. No Serve restart or worker binary upgrade belongs to this migration.

Apply the rendered Node209 unit in an ordinary maintenance window. Check `gateway-release-auto-status` and announce the worker restart before applying it; never restart during a Gateway release handoff. Verify the worker reconnects and its systemd credential is readable without printing the key. After normal source publication, run VPN1 identity/effect/cleanup first, then usexit18.
