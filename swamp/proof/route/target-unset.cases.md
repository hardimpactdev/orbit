# route:target:unset verification cases

`route:target:unset` only changes Orbit's records and contacts no Node, so it has no chart. The proof runs the built CLI against a fresh nested topology with Instances and Routes, and compares the output with the Gateway records and real requests. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Clear a target | A pending Route whose Instance is not active. Clear it and confirm, then repeat with `--yes`. | The Route stays with its domain, scope and publication, and has no target. No Node is contacted and no run starts. With `--yes`, no prompt and no lookup appear. |
| Already empty | Clear a Route that has no target. | Exit 0, skipped with `route.target_unchanged` in human and JSON output. Nothing changes. Fails today: the Route is returned with no reason code. |
| Active Instance | Clearing would leave an active Instance without a Route. | `route.target_conflict`. Nothing changes. |
| Active Route | An `active` Route that has targets, a custom proxy Route, and a Route with public Reverb attachments. | `route.reconciliation_required`, `route.kind_unsupported` and `websocket.route_in_use`. Nothing changes. |
| Unactivated replacement | A replacement Route that never activated. | `route.target_conflict`, naming `route:destroy`. Nothing changes. |
| Consent | In a terminal, read the prompt, decline it, then confirm. Also run noninteractively and with `--json` without `--yes`. | The prompt names the domain and the effect, with No selected. Declining, Ctrl-C and end of input end with `input.cancelled`. The noninteractive calls are refused with `input.confirmation_required` before any request. Nothing changes until confirmed. Fails today: the prompt names the domain only, and the CLI looks the Route up before it refuses, so an unknown Route gets `http.404` first. |
| Missing input | In a terminal, run the command with no argument. Then run it noninteractively and with `--json` without the Route. | The terminal asks for the Route from an interactive list. Ctrl-C ends with `input.cancelled` and no request. The noninteractive calls are refused with `route.id_invalid`. Fails today: the argument is required, nothing prompts, and the parser refuses a missing argument first. |
| IDs only | A domain, `0` and `-1` instead of an ID. | `route.id_invalid`, with no request. |
| Refusals | A caller with no grant to the Node the Route is scoped to, a caller with a grant to one of several members of a Cluster-scoped Route's Cluster, a caller with a grant to only one of the Ingress and Process Nodes of a public custom proxy Route, and an unknown ID. | `node_access.required` for the first and third, success for the second, and `http.404`. Nothing changes on a refusal. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | The Route shows as `route:show` does. The piped output has no animation or escape codes. With `--json`, stdout holds only the Route. |
