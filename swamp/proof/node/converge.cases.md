# node:converge verification cases

The proof runs the built CLI against a fresh nested topology with a Gateway, a VPN hub Node, an `app-dev` Node that owns an Instance, and a Node with Tools. It checks the Gateway record and the machine state. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Converge a drifted Node | Downgrade the agent, remove a role tool and break a role unit on the Node, then converge it. | The Operation succeeds. The agent and CLI are at the release pins, the role tools are at their pins and held, the unit runs, and the check passes. |
| Footprint drift | Change the agent's configuration, remove the `orbit-env` group access on an Instance `.env`, open its mode to others, delete the temporary-file rule, then converge. Also converge a Node that already matches. | The files, group access, mode and rule are restored. The agent restarts only when one of its files changed, and not for a Node that already matches. |
| Held software | After the converge, change a recipe tool through the package manager and install a Tool that needs another version of a held package. | The held version does not move. An Ubuntu security update still installs. The Tool fails alone. |
| Tools | A Node with two Tools, one of which fails. | The other Tool is installed. Only the failing Tool is marked failed on its own record, and the Operation still succeeds. |
| Network drift | Break the Node's tunnel configuration and the hub's peer entry, then converge. | The hub is fixed first, then the Node. A recent handshake exists afterwards and a packet crosses the tunnel in both directions. |
| No drift | Converge a Node that already matches. | The Operation succeeds. No file is rewritten, nothing is reloaded or restarted, and the tunnel is not touched. |
| Node with Instances | Converge a Node that owns an Instance. | The Operation succeeds. The Instance, its Processes and the role assignments are unchanged. |
| Best-effort steps | Leave a Route residue that cannot be removed, and make closing an Instance `.env` fail. | The Operation succeeds and the leftovers stay. The next converge removes them once they can be. |
| Hub and no hub | Converge the VPN hub itself, with no peers, and a Node with no recorded hub. | Both succeed. The hub's check does not need a handshake, and the Node without a hub runs no network phase. |
| Failed phase | Make a phase fail after the binaries. | The Operation fails with the failed phase. Finished phases stay and nothing is rolled back. |
| Rerun after failure | Fix the cause and run the same command. | The Operation succeeds, starts from the first phase, skips what matches, and leaves no duplicates. The run folder is removed after both runs. |
| Run folder | After a successful run and after a failed one. | The run's private files are gone from the Swamp Node in both cases. |
| Unsupported Node | Converge a macOS Node, an unmanaged Node and an inactive Node. | `node.converge_unsupported`. No Operation and no change. |
| Busy | Start a second converge for the same Node, and one for another Node on the same hub. | `operation.target_busy`. The first run is unaffected. |
| Missing input | In a terminal, run the command with no argument. Then run it noninteractively and with `--json` without the Node. | The terminal asks for the Node from an interactive list. Ctrl-C ends with `input.cancelled` and no request. The noninteractive and `--json` calls are refused with `node.id_invalid` and send no request. Fails today: the argument is required, nothing prompts, and the code for a missing Node is `node.reference_required`. |
| Dropped options | Run the command with `--force`, and with `--idempotency-key`. | Each option is refused as unknown and nothing runs. Fails today: `--force` re-applies every artifact and the CLI offers `--idempotency-key`. |
| Lost response | Lose the response to the start, then repeat the command. | Exactly one Operation exists for the converge. Fails today: the CLI needs `--idempotency-key` to avoid a second Operation. |
| Node by ID only | Pass a Node name instead of its ID. | `node.id_invalid`, with no request. Fails today: the CLI accepts a name. |
| Refusals | A Node the caller has no access grant to, and an unknown ID. | `node.access_denied` and a not-found error. Nothing changes. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | One progress indicator, then the final result with the Operation and request IDs. The piped output has no animation or escape codes. With `--json`, stdout holds only the final Operation. |
