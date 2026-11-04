# firewall:deny verification cases

Agreed with Nick on 2026-11-04. The proof runs the built CLI against a fresh nested topology and checks the Gateway record, the UFW state on the Node and real traffic. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

A deny must block traffic that would otherwise pass. Each case first shows its test path is open, through a control probe and the rule the case relies on, without opening management access to the probe.

| Case | Setup and action | Required observations |
| --- | --- | --- |
| New deny | Healthy listener; an allow for anywhere on the port; the tested source connects. Deny the port. | The source is blocked. One active Gateway rule and one UFW entry per protocol and family, above the allow. |
| Restricted deny | An allow for anywhere on the port; two sources connect. Deny one source. | The denied source is blocked. The other source still connects. The deny sits above the allow. |
| WireGuard peers | `orbit:wireguard-members` admits a peer to the port. Deny the port without `--from`. | The peer is blocked. The deny sits above `orbit:wireguard-members`. A narrower allow for one peer, added first, keeps that peer connected. |
| Already applied | Repeat the same correct rule. | Exit 0, skipped with `firewall.rule_exists` in human and JSON output. No second Gateway rule or UFW entry. Fails today: the command refuses the duplicate. |
| Partial application | Only some of the requested protocol or address-family entries exist. Deny the rule. | The missing entries appear. Existing correct entries stay. Traffic and order match the contract. Fails today for a failed rule: the Gateway refuses any rule with the same identity, whatever its status. |
| Interrupted reorder | A requested entry sits in the wrong place. Interrupt after its deletion, before it is inserted again. Repeat. | The failure stays visible. The repeat puts the entry back in the right place. Unrelated rules are unchanged. Fails today: the repeat is refused, as above. |
| Protected port | Deny SSH 22, the WireGuard port, a role port, and a range that includes one of them, each with no `--from`, with a single-address `--from`, and with a CIDR that covers the WireGuard network. | `firewall.protected_port` for each. No Gateway rule, no new Operation, no Node change. Management SSH and WireGuard still work. |
| Conflict | An allow exists with the same port, protocol and source. Deny it. | `firewall.rule_conflict`. No new Operation, no Node change. |
| Busy target | Another firewall write runs on the Node. Deny a rule. | `operation.target_busy`. No second writer touches the Node. |
| Protocols and families | Explicit TCP, explicit UDP, no protocol (both), IPv4 and IPv6 sources. | Probes and stored intent agree for every requested protocol and family. |
| Port range | Listeners inside the range, a control port outside it. | The range is blocked. The outside port stays open. |
| Failure after a partial change | Make the apply fail after at least one entry was applied. | The failed Operation and the rule stay visible. Applied entries stay. Unrelated state is unchanged. A retry settles without duplicates. Fails today: the retry is refused, as above. |
