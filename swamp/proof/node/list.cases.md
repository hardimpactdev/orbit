# node:list verification cases

`node:list` reads Orbit's records and has no chart. The proof runs the built CLI against a fresh nested topology with several Nodes and compares the output with the Gateway records and the Nodes. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Every Node | From the Gateway Node, with Nodes that differ in roles, platform, TLD, Cluster and addresses. | One row per Node, sorted by name and then ID. Every column matches the Node's record. |
| Access scope | From a Node with an access grant to only some Nodes. | Only those Nodes are listed. A Node without a grant does not appear in human or JSON output. |
| Agent column | One Node with its agent running, one with the agent stopped past the liveness window, one that never reported, one without an agent, and the Gateway's agent view stopped. | `online`, `offline`, `not seen`, `—` and `unknown`, in human output and in the JSON `agent` state. After the agent restarts, the row returns to `online` without any command. |
| Missing values | A Node without roles, TLD, Cluster or LAN address. | Each shows `—` in human output. JSON keeps its documented null or empty value. |
| Cluster name | A Node in a named Cluster. | The CLUSTER column shows the name. JSON has `cluster_id` and the name. Fails today: the column shows the numeric ID. |
| Records only | Make a Node unreachable over SSH, and watch its network. | The Node is still listed. The command sends nothing to any Node. |
| Follows changes | Add a Node, add a role, remove the Node, listing after each. | The list reflects each change after the change completes. |
| Empty | A caller with no accessible Nodes. | Exit 0. Human output says `No nodes.`. JSON has an empty list. |
| Output forms | A wide and a narrow terminal width, output piped to a file, and `--json`. | A table at the wide width and labeled fields at the narrow one, with no column lost. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. |
| Unknown caller | Call from an address that is not an active Node. | `peer.identity_unknown`, exit 1, nothing listed. |
