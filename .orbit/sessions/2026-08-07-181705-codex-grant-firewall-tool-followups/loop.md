# Orbit Feature Loop

- Scratchpad: none (compact local loop)
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-grant-firewall-tool-followups
- Branch: codex/grant-firewall-tool-followups

## Goal

Four operator-reported silent-behavior defects are fixed with regression coverage: node:grant names the requested permissions it did not apply to an existing grant instead of discarding them silently, firewall:allow/deny keep a stored reason and owner when a converging run does not supply one and now reject converging onto a protected role-owned rule outright, tool:install drops the --status flag the gateway always rejected, and the CLI app-source test cleans up only the temp directories it created rather than globbing every match in the shared temp dir. The sharded lane assigns each file to exactly one group, so cross-shard destruction was not reachable there; the hazard is overlapping pest invocations, which do happen in agent workflows.

## Scope

- Owned: apps/gateway (NodeGrantController, FirewallRuleIntent), apps/cli (ToolInstallCommand and its tests, InternalAppSourceCreateCommandTest), apps/docs/content tool-install pages and generated command catalog
- Constraints: node:grant stays create-only per its documented contract; no unrelated behavior changes; docs and catalog must match the changed signature
- Out of scope: app-level runtime defaults with instance/workspace overrides (separate design), harness gate and receipt-lint gaps

## Authorization

- User decisions verbatim: "6. lets make sure nothing gets wiped regarding reason when updated unless reason gets updated", "8. address, clearly a big" (node:grant), "9. not sure why there is a status flag for tool:install", and the fake-bin isolation follow-up they approved as task #11.

## Proof

- Verification:
  - focused: passed - NodeGrantController 29 passed including coverage-based warning cases, a wildcard-grant no-warning case, and one updated silence expectation; Firewall suite 94 passed including preserve-on-omit, replace-on-supply, and the protected role-owned rejection; CLI tool command suite 59 passed after removing the obsolete --status validation test; full CLI suite 2532 passed; sharded CLI lane bin/orbit-cli-pest-quality 2524 passed with all five shards exit 0
  - broader: passed - artifact `.orbit/quality-gates/quality-check-2026-08-07T160712Z-dc2d74fa43fc.json` on exact commit f6010bbfd29270befcbafbefcc5260440277adba, exit_code 0, every subgate 0, dirty false
  - runtime: passed - candidate=f6010bbfd29270befcbafbefcc5260440277adba; venue=retained-incus; environment=dev-fixture; target=retained Incus topology dev-6cb7e5 kind operator_gateway_app-dev on beast with the candidate overlaid on operator, gateway, and app-dev; expected=the grant warning names only genuinely uncovered permissions and suggests an additive command, a wildcard grant warns not at all, an omitted --reason preserves the stored note through to the node backend, a protected role-owned rule rejects user convergence, and tool:install no longer accepts --status; observed=re-grant returned node.grant_permissions_ignored naming only tool:read with next_command --add='tool:read', running that command widened the set to node:read plus tool:read rather than replacing it, a stored wildcard grant returned no warnings and kept ['*'], a converge without --reason kept the note and ufw show added on the app-dev node carried comment 'keep this note', a user converge onto an owner=metrics rule returned firewall_rule.protected and left owner, protected, and reason unchanged, and --status was rejected as a non-existent option; result=passed; evidence=`.orbit/evidence/grant-firewall-tool-followups/PROOF-MANIFEST.md`
- Blast radius: complete - evidence=reviewer-run trace of NodePermissionRegistry coverage semantics against the new warning, FirewallRule owner/protected derivation and every writer of role-owned rules, repo-wide search for remaining tool:install status senders including packages/sdk, and an inventory of the shared-temp glob pattern across apps/cli/tests; result=one HIGH privilege-reducing suggestion fixed before merge, SDK and docs gaps closed, protected-rule mutation vector closed, and ~14 sibling test files carrying the same glob pattern recorded as a follow-up (each prefix-isolated, none able to reach these fixtures); firewall:remove has emitted firewall_rule.protected since before this branch and its own contract still does not list it, recorded as a follow-up rather than widened into this diff
- Review: passed - human-judgment=not-required; independent general reviewer VERDICT=PASS, BLAST_RADIUS=complete on exact candidate f6010bbfd29270befcbafbefcc5260440277adba after two FIX rounds (a privilege-reducing next_command that would have revoked a wildcard grant, then the undocumented firewall_rule.protected failure introduced by the store guard); reviewer traced --add merge semantics at the receiving end and declined a runtime receipt
- Reviewed feature tip: f6010bbfd29270befcbafbefcc5260440277adba
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: f6010bbfd29270befcbafbefcc5260440277adba
- Accepted main tip: bf5567ac674cdd5fc8e5f135eafe4462d0d9096b

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
