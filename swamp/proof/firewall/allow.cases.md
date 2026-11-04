# firewall:allow verification cases

Agreed with Nick on 2026-11-04. The proof runs the built CLI against a fresh nested topology and checks the Gateway record, the UFW state on the Node and real traffic. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

The proof topology has a broad `proof-network` allow. Test traffic must not pass through it: each case first shows its test path is blocked, without cutting management access.

| Case | Setup and action | Required observations |
| --- | --- | --- |
| New restricted allow | Healthy listener; a control probe connects; the tested source is blocked. Allow that source. | The source connects. An excluded source stays blocked. One active Gateway rule and one UFW entry, in the right order. |
| Already applied | Repeat the same correct rule. | Exit 0, skipped with `firewall.rule_exists` in human and JSON output. No second Gateway rule or UFW entry. Fails today: the command refuses the duplicate. |
| Partial application | Only some of the requested protocol or address-family entries exist. Allow the rule. | The missing entries appear. Existing correct entries stay. Traffic and order match the contract. |
| Interrupted reorder | A requested entry sits in the wrong place. Interrupt after its deletion, before it is inserted again. Repeat. | The failure stays visible. The repeat puts the entry back in the right place. Unrelated rules are unchanged. |
| Conflict | A deny exists with the same port, protocol and source. Allow it. | `firewall.rule_conflict`. No new Operation, no Node change. |
| Busy target | Another firewall write runs on the Node. Allow a rule. | `operation.target_busy`. No second writer touches the Node. |
| Protocols and families | Explicit TCP, explicit UDP, no protocol (both), IPv4 and IPv6 sources. | Probes and stored intent agree for every requested protocol and family. |
| Port range | A listener inside the range, a control port outside it. | The range is allowed. The outside port stays as it was. |
| Failure after a partial change | Make the apply fail after at least one entry was applied. | The failed Operation and the rule stay visible. Applied entries stay. Unrelated state is unchanged. A retry settles without duplicates. |
