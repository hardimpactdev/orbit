# node:ssh-access:converge verification cases

The `orbit` CLI has no command for this yet, so the proof calls the real API endpoint (`POST /api/v1/nodes/{node}/ssh-access/converge`) and the MCP tool `node-ssh-access-converge`. It runs against a fresh nested topology with a Gateway and a managed Linux Node, and checks the Gateway record, the Node's `authorized_keys` and a real login. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Add the key | The Node's `authorized_keys` lacks the `swamp` role key. Converge. | The task succeeds. The key is in `authorized_keys`, and a new SSH session with the `swamp` identity runs `true`. The Gateway records the digest of the target and the keys. |
| Operator keys | Record an operator key in Orbit, then converge. | The key is added next to the `swamp` key. A key Orbit stops recording is never removed. Fails today: Orbit records no operator keys, so only the `swamp` key is written. |
| Rotated key | Rotate the `swamp` role key, then converge. | A new run adds the new key and the old line stays in `authorized_keys`. |
| Other keys untouched | `authorized_keys` holds unrelated keys, comments, odd ordering and a missing final newline. Converge. | Every other line is byte for byte as before. Nothing is removed, reordered or rewritten. |
| Keys already there | Converge a Node that already has both keys. | The task succeeds and `authorized_keys` is unchanged. |
| Same keys again | Converge twice with unchanged keys while the first task is queued or running. Then change the Node's address or host key and converge again. | The second call returns the first task's ID and starts no new run, and nothing is sent to the Node. A changed target gives a new run even with the same keys. |
| Key removed after success | After a successful converge, remove the key by hand and converge again. | A new run restores the key. A succeeded task is not reused. Fails today: the call returns the old succeeded task and restores nothing. |
| Idempotency | Repeat a call with the same `Idempotency-Key`, then with the same key and different input. | The first returns the same Operation. The second is refused with `operation.idempotency_conflict` (409). |
| Failed write | Make the account refuse a key write. | The task fails with the failed step. The recorded digest stays as it was. Keys added before the failure stay. After the cause is fixed, the same call succeeds and leaves existing keys as they are. |
| Identity not accepted yet | A Node that does not accept the `swamp` role identity, before bootstrap added its first key. | The task fails at the write step, because the write authenticates with that identity. The recorded digest stays as it was. |
| Login check fails | Make the final login fail after the keys are written. | The task fails at the login check. Added keys stay and the recorded digest stays as it was. |
| Root account | A Node whose managed account is `root`. | `node.ssh_account_invalid`. No task, nothing changes. |
| Unsupported Node | An inactive Node, an unmanaged Node and a macOS Node. | `operation.kind_unavailable`, with no task and no change. Fails today: no status or platform guard exists, so these Nodes are admitted when they have an address, user and host key. |
| No SSH target | A Node without an address or stored host keys, and a missing identity key configuration. | `swamp.ssh_target_unavailable` and `swamp.ssh_identity_unavailable` (503). No task and no change. |
| Body is ignored | Send keys or members in the request body, and a malformed body. | `validation.failed` (422). The keys come only from Orbit's records. |
| No private key | After a run. | The task's intent and result contain no private key. |
| Busy | Start a second SSH access change on the Node while one runs with different keys. | `operation.target_busy` (409). The first run is unaffected. Fails today: no Node hold is taken, so a second run starts. |
| Cancel, interrupt and timeout | Cancel a run, let one outlast the budget or the 30 s queue timeout, and stop the worker mid-run. | Each ends like a failed step. The old digest stays and a rerun finishes. |
| Refusals | A caller without an access grant to the Node, and an unknown ID. | `node_access.required` and `http.404`. Nothing changes. |
