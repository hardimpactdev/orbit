# node:metrics verification cases

The proof runs the built CLI against a fresh nested topology with a Metrics Node that scrapes a monitored Node, and compares the output with the values on the monitored machine. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Snapshot | A monitored Node under a known CPU, memory and disk load. | Cores, memory, swap, load, uptime, pressure and disks match what the machine reports within the scrape interval, in human and JSON output. `/` is the first disk, and `tmpfs` and `proc` are absent. |
| Pressure | A Node with no pressure data. | The three pressure values are `0`. |
| Records only | After the command. | Nothing is stored and no run, Operation or change on any Node exists. |
| No samples yet | A Node whose exporter has not reported. | `node.metrics_unreachable` (502). Nothing is stored. |
| Grafana down | Stop Grafana on the Metrics Node. | `node.metrics_unreachable` (502). |
| Metrics role problems | Remove the Metrics role, give two Nodes the role, stop the Metrics Node, and remove its stored credentials. | `metrics.assignment_missing`, `metrics.assignment_drift`, `metrics.node_inactive` (all 409) and `metrics.credentials_missing` (422). Nothing is stored. |
| Partial data | Make the cores, pressure or disks query fail. | The command succeeds. The failed field is empty and pressure is `0`, and a missing value shows `—`. Fails today: Cores shows an empty value instead of `—`. |
| Unavailable Node | An inactive Node, and a Node without a WireGuard address. | `node.metrics_unavailable` (422). |
| Missing input | In a terminal, run the command with no argument. Then run it noninteractively and with `--json` without the Node. | The terminal asks for the Node from an interactive list. Ctrl-C ends with `input.cancelled` and no request. The noninteractive and `--json` calls are refused with `node.id_invalid` and send no request. Fails today: the argument is required, nothing prompts, and the code for a missing Node is `node.reference_required`. |
| Node by ID only | Pass a Node name, `0` and `-1` instead of an ID. | `node.id_invalid`, with no request. Fails today: the CLI accepts a name. |
| Refusals | A Node the caller has no access grant to, and an unknown ID. | `node_access.required` and `http.404`. Nothing is shown. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | Aligned labels at the wide width, and values that wrap at the narrow one. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON, with the raw numbers. |
