# node:wireguard-key verification cases

The proof runs the built CLI against a fresh nested topology with a Gateway, a WireGuard hub (`vpn` Node), an unmanaged peer with a WireGuard address, and a managed Node. It checks the Gateway record, the hub's peers and real tunnel traffic. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Set the key | Set a public key on the unmanaged peer, whose own tunnel uses the matching key pair. | The task succeeds. The hub lists the peer with the new key and its allowed address, and a packet crosses the tunnel in both directions with a recent handshake. The target is never contacted. |
| Other peers | After the command. | Every other peer keeps its key, allowed addresses and settings, and its traffic is not interrupted. |
| Replace the key | Replace a key with another while the target keeps the old key pair. | The hub lists the new key and `wg show` no longer lists the old key. The handshake fails until the target's key pair matches, then returns. The command needs no confirmation. |
| Same key again | Send the same key twice. | The second run repeats the hub update and succeeds, with no duplicate peer. |
| Failed hub update | Make the file write fail, then the apply fail, then the check fail, each in turn. | The task fails with `operation.native_failed` and the failed step. The key stays stored. After a failed write the old file stays; after a failed apply the new file is on disk and the live hub has the old key. After the hub is fixed, the same command succeeds, and so does a `node:converge` of the hub. |
| Hub unavailable | Stop the hub's SSH. | `swamp.ssh_target_unavailable` (503) or a failed step, with the key stored. |
| Concurrent runs | Start a second call on the same Node mid-run, a `node:converge` of the hub mid-run, and keys for two different Nodes at once. | The first two are refused with `operation.target_busy`, and the stored key and the hub keep the first run's key. The two different Nodes are refused too while they share the hub. |
| Cancel and interrupt | Cancel a run, let one time out, stop the worker mid-run, and press Ctrl-C while the CLI follows. | The Operation ends like a failed step (`operation.timed_out` for the timeout). The key stays stored and a rerun finishes. Ctrl-C stops following only. |
| Run folder | After a successful run and after a failed one. | The run's private files are gone. |
| Caller is the peer | Run the command from the peer itself. | The Operation finishes on the hub even if the peer's tunnel drops, and `operation:show` from another peer shows it. |
| Key conflict | Use the key of another Node, of a Node the caller cannot access, of the hub, and two concurrent assignments of one key. | `node.wireguard_key_conflict` (409), without exposing the other Node. The stored key and the hub are unchanged. Exactly one concurrent assignment succeeds. |
| Invalid key | An empty value, whitespace, malformed base64, and base64 of the wrong length. | `validation.failed`. No Operation, nothing changes. |
| Managed and gateway Nodes | A managed Node, a Node with only an address, a Node with only a pinned fingerprint, and the Node that holds `gateway`. | `node.wireguard_key_managed` for the first, none for the next two (the second without an address gets `node.wireguard_ip_required`), and `node.wireguard_key_gateway`. Nothing changes. |
| No WireGuard address | An unmanaged Node without an address. | `node.wireguard_ip_required`. Nothing changes. |
| Busy name | Hold the Node's name lock with another lifecycle operation. | `node.provisioning_busy`. Nothing changes. |
| Activity | After a success and after a refusal. | Activity records the admission with the caller, the target and the request ID, and never the key. The outcome is on the Operation. |
| Missing input | In a terminal, run the command with no arguments, then with only the Node. An invalid key is typed first. Then run it noninteractively and with `--json` without the Node, and without the key. | The terminal asks for the Node from an interactive list and the key as text with no default, and asks again for the invalid key. Ctrl-C ends with `input.cancelled` and no request. The noninteractive calls are refused with `node.id_invalid` and `node.wireguard_key_required`, with no request. Fails today: the code for a missing Node is `node.reference_required`. |
| Node by ID only | Pass a Node name instead of its ID. | `node.id_invalid`, with no request. Fails today: the CLI accepts a name. |
| Refusals | A Node the caller has no access grant to, and an unknown ID. | `node_access.required` and `http.404`. Nothing changes. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | One progress indicator, then the final result with the Operation and request IDs. The piped output has no animation or escape codes. With `--json`, stdout holds only the final Operation. Fails today: the request is synchronous and returns the Node, not an Operation. |
