# route:show verification cases

`route:show` reads Orbit's records and has no chart. The proof runs the built CLI against a fresh nested topology with Routes in several states, and compares the output with the Gateway records. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Every field | An active app Route with a web root and one without, a custom proxy Route with an upstream, a Process-based custom proxy Route, and an analytics tracking Route. | Each field matches the record, in human and JSON output. The web root shows `Instance root` when empty. The custom proxy Routes show Upstream and Process. The analytics Route shows its Analytics Instance and no targets. Fails today: the analytics Instance is not printed, so Targets shows `—`. |
| Unfinished change | A Route in a replacement pair, one with a target set step in progress, and one that failed. | The replacement fields (`Replaces Route`, `Replaced by Route`, `Replacement step`), `Target set step`, `Failed step` and `Error` show where the change stopped. A settled Route shows `—` for them. |
| Names | A Route with a Project, a Scope, a generation basis Node, a replacement pair and Instance targets. | Each shows by name and ID. Fails today: they show bare IDs, and Scope shows `Node 7` or `Cluster 3`. |
| Records only | Make a Node unreachable over SSH. | The command succeeds and sends nothing to any Node. |
| Missing input | In a terminal, run the command with no argument. Then run it noninteractively and with `--json` without the Route. | The terminal asks for the Route from an interactive list. Ctrl-C ends with `input.cancelled` and no request. The noninteractive and `--json` calls are refused with `route.id_invalid` and send no request. Fails today: the argument is required and nothing prompts. |
| IDs only | A domain, `0` and `-1` instead of an ID. | `route.id_invalid`, with no request. |
| Refusals | A caller with no grant to the Node that owns a Node-scoped Route, a caller with a grant to one of several members of a Cluster-scoped Route's Cluster, a caller with a grant to only one of the Ingress and Process Nodes of a public custom proxy Route, a caller without Gateway authority for an empty Cluster, and an unknown ID. | `node_access.required` for the first, fourth and third, success for the second, and `http.404`. Nothing is shown on a refusal. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | Aligned labels at the wide width, and values that wrap at the narrow one. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. |
