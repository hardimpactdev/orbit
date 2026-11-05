# cluster:router:unset verification cases

The proof runs the built CLI against a fresh nested topology with a Gateway, an inactive Cluster with a Router and members, and a Cluster without Routes. It checks the Gateway record, the Router's Caddy and firewall, and the members' firewalls. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Clear the Router | An inactive Cluster whose Router serves sites and has LAN rules. Clear it and confirm. | The Operation succeeds and the assignment is deleted. The Router's sites and certificates are gone and Caddy reloads without a restart. The LAN rules only the Router needed are gone on every Node, and ufw shows no Router line. Caddy, packages and the WireGuard baseline rules stay. |
| No Router state | A Router that holds no site, certificate or LAN rule, and a Cluster with no Router. | The assignment is deleted at once, or nothing changes, with no Operation to follow. |
| Active with a TLD | An active Cluster with a TLD. | `cluster.active_router_required`. Nothing changes. |
| Owns a Route | A Cluster that owns a Route, and one that owns an active Route. | `cluster.routes_require_router` and `route.reconciliation_required`. Nothing changes. |
| Failed step | Make the Caddy validation, an SSH step or a rule removal fail. | The Operation fails with the failed step. The assignment stays, marked failed, and finished steps stay. A rerun after the fix skips what is gone and deletes the assignment. Fails today: the assignment is not marked failed, it stays `active`. |
| Interrupt | Cancel a run, let one time out, and stop the worker mid-run. | Each ends like a failed step and the rerun finishes. |
| Busy | Start a second Router change on the Cluster. | It waits up to 30 seconds, then fails with `cluster.router_busy`. The first run is unaffected. |
| Consent | In a terminal, read the prompt, decline it, then confirm. Also run noninteractively and with `--json` without `--force`. | The prompt names the Cluster and the effect, with No selected. Declining, Ctrl-C and end of input end with `input.cancelled`. The noninteractive calls are refused with `cluster.confirmation_required`, and an API call without `force` too. No Operation starts until confirmed. Fails today: the prompt names only the Cluster, not the effect. |
| Missing input | In a terminal, run the command with no argument. Then run it noninteractively and with `--json` without the Cluster. | The terminal asks for the Cluster from an interactive list. Ctrl-C ends with `input.cancelled` and no request. The noninteractive calls are refused with `cluster.id_invalid`. Fails today: the argument is required and nothing prompts. |
| Dropped inputs and lost response | Run with `--idempotency-key`, then lose the response to the start and repeat the command. | The option is refused as unknown, and exactly one Operation exists. Fails today: the CLI offers `--idempotency-key`. |
| Refusals | A caller with no access grant to a member Node of the Cluster, and an unknown Cluster. | `node_access.required` and `http.404`. Nothing changes. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | One progress indicator, then the final result with the Operation and request IDs. The piped output has no animation or escape codes. With `--json`, stdout holds only the final Operation. |
