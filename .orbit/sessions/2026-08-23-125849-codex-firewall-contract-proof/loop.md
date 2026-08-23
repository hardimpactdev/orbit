# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-firewall-contract-proof
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-firewall-contract-proof
- Branch: codex/firewall-contract-proof

## Goal

Firewall changes share one target-platform predicate, one managed UFW comment identity producer, and one reusable candidate-bound Beast retained-Incus proof rig that exercises allow, list, Doctor, remove, rule order, and ownership safety without rebuilding bespoke proof code.

## Scope

- Owned: Firewall platform predicate producers=`FirewallTargetPlatform` SQL and `FirewallRuleProbe` runtime eligibility; consumers=`FirewallRuleIntent`, `FirewallRuleQuery`, Doctor/probe tests`; managed identity producers=`LocalFirewallRuleAction` and gateway canonicalization`; consumers=`UfwFirewallRule`, firewall probe, public SSH baseline installer, CLI apply/remove`; proof producers=`candidate CLI build plus Beast Incus fixture`; consumers=`retained-incus acceptance receipt and future firewall candidates`; dangerous invariants=SQL/PHP parity, exact `ubuntu` or literal `ubuntu_` eligibility, reason-or-`orbit:rule-name` identity, never infer ownership from ports, preserve unrelated/baseline rules, prepend managed allow before broad deny, remove only identity-owned rules, exact candidate binary/receipt, fail-closed cleanup; primitive=firewall contract and retained proof rig; transitions=success:allow-list-doctor-remove-pass-on-exact-candidate|failure:retain-target-and-evidence-with-named-failed-step|retry:reuse-same-rig-on-new-candidate|stop-restart:resume-or-clean-exact-owned-fixture|stale:reject-candidate-binary-or-target-identity-mismatch
- Constraints: Keep authorization and active-role eligibility unchanged. Preserve protected WireGuard SSH and unrelated UFW rules. Run Incus only through Beast. Use one retained proof venue, not a human-only E2E lane. Keep docs, gateway, CLI, core contract, and parity tests aligned.
- Out of scope: Non-firewall platform predicates, non-UFW backends, unrelated architecture refactors, fleet deployment, release publication, and any `composer test:e2e*` command.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/FirewallRetainedProofHelperTest.php`; `bin/orbit-gateway-vendor-bin mago lint tests/Feature/E2ESupport/FirewallRetainedProofHelperTest.php`; `composer docs-lint`
  - broader: passed - `composer quality-check` for `7323a43886b9c08e4799ab1eb5968b44903847f1` artifact `.orbit/quality-gates/quality-check-2026-08-23T105139Z-b5d9755a9a16.json`
  - runtime: passed - candidate=7323a43886b9c08e4799ab1eb5968b44903847f1; venue=retained-incus; environment=dev-fixture; target=dev-501dc2; expected=allow-list-doctor-remove pass with owned comment and unrelated same-port preserved; observed=allow-list-doctor-remove passed, managed allow preceded deny, protected same-port survived; result=passed; evidence=`.orbit/evidence/firewall-retained-proof/7323a43886b9c08e4799ab1eb5968b44903847f1.json`
- Blast radius: complete - evidence=full 5,111-file synced tracked-tree comparison, repository quality-check, and fresh exact-diff review; result=SQL/PHP target eligibility and managed-comment consumers agree, allow/list/Doctor/remove ordering and ownership pass on the exact Beast target, proof-only code stays outside published core, and the shared topology is retained
- Review: passed - candidate=7323a43886b9c08e4799ab1eb5968b44903847f1; fresh Opus-high diff review plus targeted closure reviews found all concrete production and proof-identity defects closed; human-judgment=not-required; optional polish did not trigger rework
- Reviewed feature tip: 7323a43886b9c08e4799ab1eb5968b44903847f1
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 7323a43886b9c08e4799ab1eb5968b44903847f1
- Accepted main tip: 3bbf0742044904654a1a9b6ab7602dc2b7434983

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
