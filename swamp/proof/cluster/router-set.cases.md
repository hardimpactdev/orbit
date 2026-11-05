# cluster:router:set verification cases

The proof runs the built CLI against a fresh nested topology with a Gateway, a `vpn` Node and a Cluster whose members are on one LAN, some of them serving Cluster Routes. It checks the Gateway record, the Router's Caddy and firewall, DNS answers and real HTTPS traffic from members. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| First Router | A Cluster with no Router and Cluster Routes. Set a member as Router. | The Operation succeeds and the member's `router` assignment is active. It has the router role setup, its certificates are signed and installed, and its Router sites serve every Cluster Route. Members reach each Route over HTTPS through it. LAN rules allow 443/tcp and 53/tcp and udp from each eligible member, and the workload members allow the Router. Fails today for DNS: Cluster DNS on the Router is not built, so members do not ask it for DNS. |
| Replace the Router | A Cluster with a Router and traffic running. Set another member. | Requests keep succeeding through the whole change. The new Router is active and the old assignment is deleted. DNS answers members' Cluster names with the new Router's LAN address after the grace. The old Router's sites, certificates and its own LAN rules are gone, and WireGuard baseline rules stay. |
| Repeat | Set the Node that is already the active Router, with no replacement pending. | Exit 0, skipped with `cluster.router_unchanged` in human and JSON output. Nothing runs and nothing changes. Fails today: the intent is a no-op with no reason code. |
| Candidate cannot serve | Make a workload unreachable from the candidate. | The probe fails and the Operation fails. The old Router keeps serving, DNS is unchanged, and the candidate's assignment is `failed`. Packages and Caddy on the candidate stay. |
| Failed step with restore | Make a setup, certificate, firewall or Caddy step fail. | The captured Caddy, firewall and DNS state is back, the old Router serves, and the result says whether the restore passed. A rerun after the fix succeeds without duplicates. |
| DNS selection fails | Make the private DNS selection fail. | The captured state is restored, both Routers keep serving, and the candidate's assignment is `failed`. A rerun after the fix succeeds. |
| Restore fails | Make a step fail and then the restore fail too. | The old assignment stays active, the candidate is `failed`, the result says the restore failed, and `doctor` shows the drift. |
| Retire fails | Make the old Router's retirement fail after the DNS switch. | The candidate stays the Router, the old assignment stays recorded as unfinished, and a rerun finishes it. |
| Private keys | After the run. | The candidate's private keys never left it, and the Gateway signed only the requests. |
| Busy | Start a second Router change on the Cluster. | It waits up to 30 seconds, then fails with `cluster.router_busy`. The first run is unaffected. |
| Unfinished replacement | Leave a replacement unfinished, then start another change. | `cluster.router_transition_conflict`. Nothing changes. |
| Invalid candidate | A Node that is not a member, an inactive member, and a macOS or iPadOS member. | `cluster.router_node_invalid`. Nothing changes. |
| No LAN address | A Linux member without a LAN address. | It can be the Router and is reached by its WireGuard address. |
| Interrupt | Cancel a run, let one time out, and stop the worker mid-run. | Each ends like a failed step. The old Router keeps serving and a rerun finishes. |
| Missing input | In a terminal, run the command with no arguments, then with only the Cluster. Then run it noninteractively and with `--json` without the Cluster, and without the Node. | The terminal asks for the Cluster and then the Node from the active members. Ctrl-C ends with `input.cancelled` and no request. The noninteractive calls are refused with `cluster.id_invalid` and `node.id_invalid` and send no request. Fails today: the arguments are required and nothing prompts. |
| Dropped inputs and lost response | Run with `--idempotency-key`, then lose the response to the start and repeat the command. | The option is refused as unknown, and exactly one Operation exists. Fails today: the CLI offers `--idempotency-key`. |
| Refusals | A caller with no access grant to the candidate, and an unknown Cluster or Node. | `node_access.required` and `http.404`. Nothing changes. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | One progress indicator, then the final result with the Operation and request IDs. The piped output has no animation or escape codes. With `--json`, stdout holds only the final Operation. |
