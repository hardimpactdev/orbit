# firewall:list verification cases

`firewall:list` reads Orbit's records and has no chart. The proof runs the built CLI against a fresh nested topology and checks the output against the Gateway records and the Node. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Empty | A Node with no operator rules. List it. | Exit 0. Human output says `No firewall rules.`. JSON has an empty list. |
| Every status | Rules that are provisioning, active, failed and removing, made through the real commands. List the Node. | Each rule shows its own status. In JSON, the failed rule carries `failed_step` and `error_code`; the others do not. |
| Order | Create an allow for anywhere on a port, then a deny for the same port from a CIDR, then an allow from one address inside the CIDR, plus rules on other ports created in between. | Human and JSON output list the address rule, then the CIDR rule, then the anywhere rule. The rules on other ports are in creation order. The sequence matches the operator rows in `ufw status numbered` on the Node. Fails today: both outputs sort by port. |
| Both protocols | A rule with no `--protocol`. | Protocol shows `any` in human and JSON output. |
| Only operator rules of this Node | Another Node has rules. The Node has its own `orbit:` rules and a rule added by hand with `ufw`. | Only the Node's recorded operator rules appear. |
| Records only | Delete an active rule's line on the Node by hand, then make the Node unreachable. List it. | The rule is still listed with its recorded status. The command succeeds and sends nothing to the Node. |
| Follows changes | Allow a rule, then remove it, listing after each. | The rule appears as active after the allow and is gone after the remove. |
| Output forms | Long labels at a wide and at a narrow terminal width; output piped to a file; `--json`. | Wrapped cells at the wide width and labeled fields at the narrow one, with no field lost. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. |
| Refusals | `--node=abc`; a Node the caller has no access grant to; an unknown Node ID. | `firewall.node_id_invalid`, `node.access_denied` and a not-found error. Exit 1, nothing listed, no Node change. |
