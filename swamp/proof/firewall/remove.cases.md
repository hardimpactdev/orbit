# firewall:remove verification cases

The proof runs the built CLI against a fresh nested topology and checks the Gateway record, the UFW state on the Node and real traffic. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md).

Each case first shows with a control probe that its traffic path behaves as the rule says, so the result is not decided by a broader rule such as the `proof-network` allow.

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Remove an allow | A restricted allow lets one source connect to a listener; another source is blocked. Remove the allow. | The source is blocked again. The other source is unchanged. The Gateway record and the UFW lines are gone. |
| Remove a deny | A deny blocks one source; an allow for another source exists. Remove the deny. | The blocked source connects again. The other rule is unchanged and keeps its order. The record and lines are gone. |
| Order kept | Several rules exist, narrow ones above broad ones. Remove one in the middle. | The remaining rules keep their relative order, and all stay above Orbit's own rules. |
| Choose by source and protocol | Rules for the same port differ by protocol or source. Remove one with `--protocol` and `--from`. | Only the matching rule and its lines are gone. The sibling rules and their traffic are unchanged. |
| Any source only | An allow for one source and an allow for any source share the port. Remove with `--from=any`. | Only the any-source rule is removed. |
| Port range | An allow for a range, and a rule for one port inside it. Remove the range. | The range lines are gone. The single-port rule and its traffic are unchanged. |
| Both families | A rule with IPv4 and IPv6 lines. Remove it. | Both families' lines are gone. |
| Kept for Orbit | A deny on a port that a role or `orbit:wireguard-members` also admits in the same shape. Remove the operator rule. | The record is gone. The Orbit line stays, and a WireGuard peer reaches the port as before. |
| Lines already gone | The record exists but its lines were removed on the Node by hand. Remove the rule. | Exit 0, skipped with `firewall.rule_absent` in human and JSON output. The record is deleted. No other line changes. Fails today: the result carries no skip reason. |
| No match | No rule matches the port. Remove it. | `firewall.rule_not_found`. No Operation, no Node change. |
| Ambiguous | Several rules match the port. Remove with the port only. | `firewall.rule_ambiguous` with each match in the error and in human and JSON output. No Operation, no Node change. |
| Confirmation | Decline the prompt, run without a terminal and without `--yes`, then run with `--yes` and `--json`. | `input.cancelled`, then `input.confirmation_required`, each exit 1, with no request, no Operation and no Node change. With `--yes` the rule is removed. |
| Busy target | Another firewall write runs on the Node. Remove a rule. | `operation.target_busy`. No second writer touches the Node. The record is unchanged. |
| Failure after a partial removal | Make the removal fail after at least one line was deleted. | The failed Operation stays visible and the rule is marked `failed` with its step. Removed lines stay removed. Unrelated rules are unchanged. A repeat finishes the removal and deletes the record without error. |
