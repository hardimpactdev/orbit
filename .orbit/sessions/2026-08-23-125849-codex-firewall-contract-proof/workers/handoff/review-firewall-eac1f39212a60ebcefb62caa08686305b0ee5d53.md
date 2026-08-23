# Fresh diff-first review: firewall contract and retained proof

verdict: FIX

HUMAN_JUDGMENT: required — two findings are boundary/product calls, not mechanical
fixes: where the proof-rig classes live (D5, which changes the acceptance venue cost
of every future rig edit), and whether a firewall proof may release the operator's
shared retained Beast topology (P4).

Reviewed the diff `20b35a21a25aa40274102b3a84c8fa0f3cb91ba8..eac1f39212a60ebcefb62caa08686305b0ee5d53`
(23 files, +1361/-32) plus only the focused surrounding code. Did not rerun Pest,
Mago, `composer quality-check`, or the retained proof.

## DEFECT

### D1. SQL and PHP Ubuntu eligibility diverge on case

`FirewallTargetPlatform::constrainUbuntu()` matches with SQLite
`platform like 'ubuntu!_%' escape '!'`, and SQLite `LIKE` is ASCII
case-insensitive; `FirewallTargetPlatform::isUbuntu()` uses case-sensitive
`str_starts_with`. Reproduced against sqlite:

| platform | `isUbuntu()` | `constrainUbuntu()` |
| --- | --- | --- |
| `ubuntu` | true | true |
| `ubuntu_24-04` | true | true |
| `Ubuntu_24-04` | **false** | **true** |
| `UBUNTU_24-04` | **false** | **true** |
| `ubuntu-24-04` | false | false |
| `Ubuntu` | false | false |
| `''` / `null` | false | false |

Effect: for such a node `FirewallRuleIntent::resolveTargetNode()` and
`FirewallRuleQuery::eligibleNodeQuery()` accept it and a rule is created and
enacted, while `FirewallRuleProbe::checkNodeEligibility()`,
`DoctorAdoptPolicy::canAdoptFirewallRules()`, and
`DoctorNodeFamilyResolver::categoriesForNode()` all reject it — a rule that exists
but that Doctor can never adopt or converge. This is the exact SQL/PHP parity
invariant the goal names as dangerous.

Reachable: `PlatformDetector::normalizeId()` lowercases detected platforms, but
`NodeManageController` → `OperatorNodeManager::manage()` → `NodeRegistryWriter`
stores the operator-supplied `platform` string verbatim with no case normalization
or allow-list, and `NodesProbe` adopt writes `observed` verbatim too.

The new `FirewallTargetPlatformTest` is titled "keeps SQL and PHP Ubuntu firewall
eligibility in parity" but its dataset has no case variant, so it asserts a parity
that does not hold. Fix the predicate (e.g. binary-collated
`substr(platform,1,7) = 'ubuntu_'` or `platform glob 'ubuntu_*'`, or lowercase in
`isUbuntu()`) and add the case rows to the dataset.

### D2. The rig can report `passed` after `firewall:allow` actually failed

`run_firewall_proof_scenario()` calls allow with `allowFailure: true` and, when the
gateway returns `firewall_rule.enactment_failed`, calls
`restore_firewall_rule()` (`doctor --restore`) and continues. The doctor step does
the same: unhealthy → `--restore` → re-check → pass. The `observed` string is fixed
text, so the receipt cannot distinguish a clean allow from a repaired one, and
`result=passed` is emitted either way.

This is not hypothetical — `.orbit/evidence/firewall-retained-proof/9b983bb6…json`
records a live `firewall_rule.enactment_failed`. Commit `81017dfb0` removed that
particular cause, but the repair-and-still-pass path is unchanged and predates it
(introduced in `291117e7c`).

Loop transitions require `failure:retain-target-and-evidence-with-named-failed-step`.
The intended path must be proven, not repaired around; at minimum the receipt must
name the repaired step and downgrade the result.

### D3. The seeded managed rule makes the allow assertions unfalsifiable

`RetainedFirewallProofScenario::seedUfwCommands()` step 4 pre-creates the exact rule
under proof: `ufw allow from any to any port 8080 proto tcp comment 'orbit:private-api'`.
`RetainedFirewallProofInspection::parseLine()` captures only index, action, port and
comment — it never parses the `From` column — so the post-allow checks cannot tell
the seeded any-source rule from the CLI's `192.168.1.0/24` rule.

Two compounding weaknesses:

- `managedComments()` applies `array_unique()`, so the guard whose failure message is
  "managed comment identity was not unique after allow" compares comment *strings*,
  not rules. Two `orbit:private-api` rules collapse to one entry and pass.
- `managedAllowPrecedesBroadDeny()` keeps the *last* managed index. With the stale
  seeded rule at index 4 and the deny at 5, the check still returns true.

Concrete false pass: a regression where `firewall:allow` no-ops, or appends a second
rule instead of converging the existing one, yields
`result=passed; observed=allow-list-doctor-remove passed…`. Seed the stale rule on a
distinguishable source, parse `From`, and assert exactly one managed rule with the
expected source.

### D4. Candidate binding omits the gateway code the proof exercises

`RetainedFirewallProofIdentity::CHECKOUT_PATHS` digests six files. The candidate also
changes `FirewallRuleShapeCanonicalizer`, `FirewallRuleProbe`, `DoctorAdoptPolicy`,
`DoctorNodeFamilyResolver`, and `PublicSshDenyInstaller` — all gateway-side, all
exercised by the `list` and `doctor` steps, none covered by the digest.

`observe_remote_head()` is fail-open: if `git rev-parse HEAD` fails in both the
checkout and `/home/orbit/orbit` it returns `null`, and `bindRemote()` then skips the
candidate-SHA equality check entirely. So a stale or partial gateway sync passes
identity binding on the strength of six unrelated-to-the-gateway files.

What does hold: I recomputed `RetainedFirewallProofIdentity::digestCheckout()` from
this worktree and it reproduces the receipt's
`cli_digest=f2e7ee72ab562f9726d618e5ae9593793e552e0899e4adec269de3081d4663c2`, and
`E2ECurrentCheckout` symlinks `/usr/local/bin/orbit` → `<checkout>/apps/cli/orbit`,
so the CLI genuinely ran the candidate. The gap is the gateway half.

### D5. Proof-only classes in `packages/core/src/**` make the rig expensive to change

`RetainedFirewallProofIdentity`, `RetainedFirewallProofInspection`,
`RetainedFirewallProofScenario`, and `RetainedFirewallProofReceipt` are referenced
only by `bin/orbit-firewall-retained-proof` and their own tests — nothing in the
gateway, CLI, SDK, or e2e app consumes them.

`orbitLoopAcceptanceVenue()` (`bin/orbit-loop-contract.php:903-949`) routes `bin/**`
and `**/tests/**` to `automated`, but any `packages/core/src/**` change to
`retained-incus`. Placing the rig in core therefore means every future tweak to the
scenario constants, the UFW parser, or the identity rules requires a full Beast
retained-incus proof run. That is the opposite of the goal's "reusable … rig …
without rebuilding bespoke proof code" and of the promised faster proof flow, and it
puts harness-only code inside a published package (`hardimpactdev/orbit-core`,
"Shared Orbit core contracts and helpers").

`ManagedUfwComment` is the one new core class that genuinely belongs there — gateway
and CLI both consume it. Suggested split: keep `ManagedUfwComment` in core, move the
four proof classes next to the script under `bin/`.

Compounding it: the rig is undocumented. `bin/orbit-firewall-retained-proof` appears
in no `HARNESS.md`, `AGENT_FAST_PATH.md`, or `apps/docs/content/` text; the only
reference to the path anywhere is inside `RetainedFirewallProofIdentity::CHECKOUT_PATHS`.
`firewall-doctor.md` maps the new core tests but never says the rig exists or how to
run it, so the next firewall candidate has no way to find it.

## POLISH

- P1. `PublicSshDenyInstaller::script()`'s three `?? self::WireGuardAllowReason` /
  `?? self::PublicDenyReason` fallbacks are unreachable — both constants are
  non-empty, so `managedComment()` never returns null on those calls. Baseline output
  is byte-identical to before, which is correct; the dead branches just obscure that.
- P2. `RetainedFirewallProofScenario::seedUfwCommands()` hardcodes a copy of
  `PublicSshDenyInstaller::WireGuardAllowReason`
  ("Orbit node security baseline permits SSH only through WireGuard."). Reference the
  producer instead of duplicating the string.
- P3. `acquire_firewall_proof_topology()` runs `e2e-incus --sync --id=<target>` from a
  raw `$state['target']` with an `''` fallback *before* building the identity. It
  fails closed only because `E2EIncusCommand` rejects an empty id. The `--cleanup`
  path validates first; make acquire symmetric by calling `identity_from_state()`
  before any `--sync`.
- P4. `--cleanup` runs `e2e-incus --stop --id=<target>`, and after commit `eac1f3921`
  that target is the operator's shared retained dev topology (`dev-501dc2`), not a
  proof-owned fixture. `assertOwnedTarget()` only proves it matches the state file.
  `HARNESS.md` §Retained Incus Acceptance says to reuse the retained topology, so
  releasing it after a firewall proof can pull the fixture out from under other loops.
  Consider dropping the `--stop` or limiting cleanup to removing the seeded UFW rules.
- P5. The proof leaves collateral state on the shared dev node: the
  `protected unrelated rule` allow, the `deny 8080/tcp`, and the wg-orbit baseline
  allow are seeded and never removed — only the managed rule is. Re-runs stay
  deterministic because ufw skips identical rules, but the fixture accumulates
  proof-only rules.
- P6. `run_command()` reads stdout to EOF before touching stderr, so a command that
  fills the stderr pipe buffer deadlocks; `stream_set_timeout()` does not bound a
  blocking `stream_get_contents()` on a pipe, so the 180s/1800s timeouts are not
  actually enforced. It also returns `'stdout' => $stdout.$stderr`, feeding merged
  stderr into `decode_json_object()`'s first-`{`/last-`}` extraction.
- P7. `bin/orbit-firewall-retained-proof` is the only root `bin/` script that requires
  `packages/core/vendor/autoload.php`; without core's dev install it dies with a raw
  PHP fatal instead of a named failure.
- P8. `MetricsRoleBaseline.php:738` still carries a byte-identical copy of the
  `ubuntu` / `ubuntu_` predicate. Out of this loop's scope (non-firewall), but it is
  the last duplicate of the now-normative rule.

## What holds

- `ManagedUfwComment::from()` is a correct value-only producer: non-empty reason wins,
  then `orbit:<name>`, else null. Gateway (`FirewallRuleShapeCanonicalizer`) and CLI
  (`LocalFirewallRuleAction::commentArguments`) both delegate to it — one producer,
  two consumers. Ports never enter the identity.
- Making `managedCommentIdentifiesObservedRule()` return false on a null identity is a
  strict safety improvement: the old code produced the literal `orbit:` for an empty
  name and could claim an observed rule commented `orbit:`.
- Public SSH baseline comments are unchanged (non-empty reasons win in all three
  calls), so `PublicSshDenyInstaller::script()` output is exact.
- Both `constrainUbuntu()` call sites (`FirewallRuleIntent:227`, `FirewallRuleQuery:120`)
  wrap it in a closure, so the internal `orWhereRaw` cannot leak out of the AND chain.
- Authorization and active-role eligibility are untouched; every changed site kept its
  `isActive()` and `NodeRoleAssignments` checks.
- The remove-stage assertions are real: a leftover managed comment or a deleted
  protected rule both fail.
- `local_firewall_proof_candidate()` is fail-closed on HEAD mismatch and on a dirty
  tree (`--untracked-files=all`); `.orbit/evidence/**` is gitignored, so retries are
  not blocked by the receipts the rig writes.
- No secrets in the diff. The only match for a credential pattern is the pre-existing
  test helper `firewall_rule_signed_operation_token`.

## Blast radius

Gateway firewall target resolution, listing, and probing; Doctor firewall-family
resolution and adopt policy; CLI local UFW comment construction; the public SSH
baseline script; plus one new root proof executable and four new `packages/core`
classes.

Production behavior changes are confined to two places, both narrow: the managed
comment now yields no identity (instead of `orbit:`) when reason and name are both
empty — strictly safer — and the Ubuntu predicate is now shared, behaviourally
identical to the three ad-hoc PHP copies it replaced. Everything else is a move.
The unresolved production risk is D1, and the unresolved proof risk is D2–D4: the
receipt asserts more than the rig can actually establish.

candidate=eac1f39212a60ebcefb62caa08686305b0ee5d53
