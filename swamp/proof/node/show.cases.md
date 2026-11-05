# node:show verification cases

`node:show` reads Orbit's records and has no chart. The proof runs the built CLI against a fresh nested topology with several Nodes and compares the output with the Gateway records. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Every field | A Node with roles, a Cluster, addresses, overrides, a TLD, an apps folder and access grants in both directions. | Each field matches the record. `Access to` and `Accessible by` list each Node as name and ID. JSON carries the same values, the stored `settings`, and the `agent` state. |
| Cluster name | A Node in a named Cluster. | `Cluster` shows the name. JSON has `cluster_id` and the name. Fails today: the field shows the numeric ID. |
| Apps folder | A Node with an apps folder, and one without. | The field is `Apps folder` and shows the path, or `—`. Fails today: the label is `Projects path`. |
| Agent states | An agent running, one stopped past the liveness window, one that never reported, a Node without an agent, and the Gateway's agent view stopped. | `online`, `offline since <time>`, `not seen`, `—` and `unknown` in human output, and the matching JSON `state` and `since`. A restarted agent returns to `online` without any command. |
| Missing values | A Node without LAN address, overrides, TLD or Cluster. | Each shows `—`. JSON keeps its documented null value. |
| Failed Node | A Node with a recorded failed step. | `Failure` shows the failed step and error code. A healthy Node has no `Failure` field. |
| Records only | Make the Node unreachable over SSH, and watch its network. | The command succeeds and sends nothing to any Node. |
| Follows changes | Change the Node with `node:update`, a role or an access grant, showing after each. | Each change appears after it completes. |
| Missing input | In a terminal, run the command with no argument. Then run it noninteractively and with `--json`. | The terminal asks for the Node from an interactive list. Ctrl-C ends with `input.cancelled` and no request. The noninteractive and `--json` calls are refused with `node.id_invalid` and send no request. Fails today: the argument is required and nothing prompts. |
| Output forms | A wide and a narrow terminal width, output piped to a file, and `--json`. | Aligned labels at the wide width, and values that wrap at the narrow one. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. |
| Refusals | An ID that is not a positive integer, a Node the caller has no access grant to, and an unknown ID. | `node.id_invalid`, `node_access.required` and a not-found error. Exit 1, no Node field printed. |
