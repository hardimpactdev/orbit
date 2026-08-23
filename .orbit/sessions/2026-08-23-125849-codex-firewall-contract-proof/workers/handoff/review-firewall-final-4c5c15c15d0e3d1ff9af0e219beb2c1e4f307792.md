# Final fresh diff-first review: corrected firewall contract and retained proof

verdict: FIX

HUMAN_JUDGMENT: required — D2 is a proof-contract call, not a mechanical fix.
Deciding whether candidate identity must bind the whole synced tree (or record
that remote HEAD was unobservable) changes the identity cost of every future
retained-proof rig, and the sync mechanism that makes remote HEAD unobservable
lives outside this diff.

Reviewed `3bbf0742044904654a1a9b6ab7602dc2b7434983..4c5c15c15d0e3d1ff9af0e219beb2c1e4f307792`
(20 files, +1369/-36) plus only the focused surrounding code. Did not rerun Pest,
Mago, `composer quality-check`, or the retained proof. Inspected the quality
receipt, the retained proof receipt, and the implementation handoff as evidence.

## First-review defects: closure status

1. Ubuntu eligibility parity — **NOT closed** (over-corrected, see D1).
2. Rig fails immediately on `firewall:allow` failure — **closed**.
   `firewall_proof_assert_allow_succeeded()` throws before the doctor step runs,
   and doctor is read-only (`--family=firewall_rule`, no `--fix`) and must report
   `healthy === true`.
3. Rig no longer pre-seeds the allow it proves — **closed**. The seed plants a
   stale managed rule at `10.9.9.9/32`, so the proof must show reconciliation to
   exactly one managed rule at `192.168.1.0/24`
   (`firewall_proof_assert_single_managed_source`, duplicate-count aware), the
   unrelated `10.6.0.0/24` same-port rule surviving both allow and remove, and
   parser-faithful managed-allow-before-deny ordering by UFW index.
4. Candidate identity — **partly closed** (see D2).
5. Core abstraction boundary — **closed**. `packages/core` gains only the
   25-line value-only `ManagedUfwComment` (two real consumers: CLI enactment and
   gateway canonicalizer). Proof-only logic sits in `bin/`, and only the
   `firewall_proof_*` helpers plus `FIREWALL_PROOF_*` constants enter the gateway
   Pest process; the generic globals (`decode_json_object`, `write_json_file`,
   `load_e2e_environment`, …) stay in the executable and cannot collide.

## DEFECT

### D1. SQL and PHP Ubuntu eligibility still diverge — the fix over-corrected

`FirewallTargetPlatform::constrainUbuntu()` now guards the whole string with
`whereRaw('lower(platform) = platform')`, but `isUbuntu()` only case-checks the
`ubuntu` / `ubuntu_` prefix and ignores the rest of the value. Any platform whose
prefix is lowercase but whose suffix carries an uppercase character is PHP-eligible
and SQL-ineligible. Verified against sqlite with the exact emitted predicate:

| platform | `isUbuntu()` | `constrainUbuntu()` |
| --- | --- | --- |
| `ubuntu` | true | true |
| `ubuntu_24-04` | true | true |
| `ubuntu_24-04-LTS` | **true** | **false** |
| `ubuntu_Noble` | **true** | **false** |
| `Ubuntu_24-04` | false | false |
| `ubuntu-24-04` | false | false |

This shape is representable by the product, not hypothetical:
`PlatformDetector::linuxIdentifier()` composes `{$id}_{$version}` where
`normalizeId()` lowercases but `normalizeVersion()` deliberately does not, so a
`VERSION_ID` of `24.04 LTS` yields `ubuntu_24-04-LTS`. `platform` is also written
straight from operator/agent-supplied input at
`NodeBootstrapReservation.php:170,181` and from observed values at
`NodesProbe.php:1550`, with no lowercase normalization at the write site.

Failure scenario: a node registered as `ubuntu_24-04-LTS`.
`DoctorNodeFamilyResolver::categoriesForNode()` and `FirewallRuleProbe`
(PHP) treat it as a firewall target, and `DoctorAdoptPolicy::canAdoptFirewallRules()`
permits `doctor --family=firewall_rule --adopt` to write rules onto it — while
`FirewallRuleIntent::resolveTargetNode()` and `FirewallRuleQuery::eligibleNodeQuery()`
(SQL) reject it with "The selected node is not a firewall target." Doctor adopts and
reports on a node the mutation surface refuses to serve: the same split-brain the
change set exists to eliminate, in the opposite direction.

Fix: scope the SQL case check to the prefix so the two sides state one rule —
e.g. `platform = 'ubuntu' OR platform GLOB 'ubuntu_*'` (SQLite `GLOB` is
case-sensitive and treats `_` literally, so it needs no `escape` and no
whole-string `lower()` guard) — or make `isUbuntu()` require the whole value
lowercase. Either direction is fine; they must be the same rule.

The new parity dataset in `FirewallTargetPlatformTest.php` cannot catch this: every
uppercase case it carries (`Ubuntu_24-04`, `UBUNTU_24-04`, `Ubuntu`, `UBUNTU`) puts
the uppercase inside the prefix. Add a lowercase-prefix/uppercase-suffix case.

### D2. The remote-HEAD leg of candidate identity can never fire, and the receipt does not say so

`firewall_proof_bind()` enforces `remote_head === candidate` only when
`observe_remote_head()` returned a SHA; a null result is silently accepted. That
null is not an edge case — it is the only reachable outcome. `observe_remote_head()`
probes `/home/orbit/orbit-run` and `/home/orbit/orbit`; the first is an overlay whose
lowerdir is the second, and the second is the extraction of the sync tarball, which
`E2ECurrentCheckout::buildArchive()` builds from `git ls-files` with `./.git` in
`archiveExcludePatterns()`. There is no `.git` on the guest, so `git rev-parse HEAD`
fails at both paths and the check is dead code on every run.

So candidate identity actually rests on: clean local HEAD equal to the candidate, a
forced `--sync`, and a 14-file digest. That digest does not cover every source tree
the proof exercises — `FirewallRuleMutationController`, `NodeRoleAssignments`, the
`apps/cli` firewall command classes, and `packages/sdk` are all executed by
allow/list/doctor/remove and all outside `firewall_proof_checkout_paths()`.

Neither the state file nor the receipt records `remote_head`, so
`.orbit/evidence/firewall-retained-proof/4c5c15c1….json` reads identically whether
HEAD matched or was never observed. A reader cannot tell which binding held.

Compounding it, the helper test named *"does not skip candidate equality when remote
git metadata is absent"* asserts only digest fail-closed behavior — the code does
skip candidate equality in exactly that case. In a proof-integrity file, that name
asserts the opposite of the behavior.

Fix (any one closes it): digest the full synced tree rather than a 14-file
whitelist; or fail closed when remote HEAD is unobservable; or, at minimum, record
`remote_head` (including `null`) in the state and receipt and rename the test to what
it proves.

## POLISH

### P1. The UFW parser is blind to every `(v6)` rule, so cleanup leaves a seeded deny behind

`firewall_proof_parse_ufw_line()` matches `^\[\s*(\d+)\]\s+(\S+)\s+(ALLOW|DENY)\s+…`.
In `ufw status numbered` the `(v6)` marker is a separate token in the To column
(`[ 4] 8080/tcp (v6)   DENY IN   Anywhere (v6)`), so `(\S+)` captures `8080/tcp` and
the next token is `(v6)`, not `ALLOW|DENY` — the line never matches and is dropped.
The `str_replace('(v6)', '', $matches[2])` on the next line is dead code and shows the
handling was intended.

Consequence today: `sudo ufw deny 8080/tcp` in `firewall_proof_seed_commands()`
creates a v4 and a v6 entry; `firewall_proof_seed_cleanup_indexes()` only ever sees
the v4 one, so `--cleanup` permanently leaves `8080/tcp (v6) DENY IN Anywhere (v6)`
on the shared retained `app-dev-1`. It does not invalidate this proof — every
assertion targets v4-source-scoped rules, and descending-index deletion stays correct
regardless of unparsed lines — but it contradicts the documented cleanup contract and
would silently weaken any future v6 or `Anywhere` assertion.

### P2. `--cleanup` leaves more than the docs claim

`firewall-doctor.md` says `--cleanup` "removes only this rig's seeded UFW rules and
local proof state." It also leaves the seeded
`ufw allow in on wg-orbit` rule and the `ufw --force enable` state change (both
deliberate — removing them risks locking out the instance), plus P1's v6 deny. Either
narrow the doc sentence or clean what is safely cleanable.

### P3. Early failures produce no receipt

The docs say the rig "retains the topology and receipt on success or failure," but the
catch block writes a receipt only when `$identity` is already bound. A failure in
`acquire_firewall_proof_topology()` — the sync, the `--start`, or the ownership assert
— exits 1 with a stderr line and no receipt at all.

### P4. Dead proof-rig helpers

`firewall_proof_refuse_shared_topology_stop()` has no caller in the rig; the test
"refuses to stop the shared retained topology" only proves that a function that throws
throws. The rig's real guarantee is that it never invokes `--stop`, which nothing
asserts. `firewall_proof_assert_owned_target()` has no caller at all.

### P5. Undocumented flags

`--dry-run` and `--receipt=` exist in the executable but appear in neither
`firewall-doctor.md` nor `prepared-topologies.md`, which document only `--candidate`
and `--cleanup`.

### P6. Dead null-coalesce in `PublicSshDenyInstaller`

`managedComment(...) ?? self::WireGuardAllowReason` (and the two deny variants) can
never take the fallback — all three reason constants are non-empty, so
`ManagedUfwComment::from()` always returns the reason. Harmless, but it reads as a
real fallback path.

### P7. `MetricsRoleBaseline::isUbuntuPlatform()` was left behind

The diff consolidates three copies into `FirewallTargetPlatform::isUbuntu()` but
`MetricsRoleBaseline.php:736` still carries a fourth. Different family, so no firewall
contract impact — worth folding in when that surface is next touched.

### P8. `doctor_report()` triple envelope fallback

Three candidate paths (`data.data.doctor`, `data.doctor`, `success.data.doctor`) mean
the rig does not pin the doctor JSON envelope it asserts against. It fails closed when
none match, so this is strength, not correctness.

## Boundary challenges

- **State before sync/mutation** — clean. `acquire_firewall_proof_topology()`
  validates persisted state (candidate, owned `dev-[a-f0-9]{6}` id, exact
  `orbit-e2e-<id>-<role>` instance names, host `beast`, digest shape) before calling
  `--sync`; the manifest branch asserts ownership before syncing.
- **Prior state cannot produce a false pass** — clean.
  `bind_remote_firewall_proof_identity()` re-runs unconditionally after acquisition,
  so a reused state file never short-circuits the digest comparison.
- **Seed accumulation** — clean for v4. Cleanup runs at scenario start, and UFW
  de-duplicates identical rules. See P1 for the v6 residue.
- **Cleanup never stops the shared topology** — clean. No `--stop` path exists.
- **Remote execution** — clean. Symfony `Process` with explicit timeouts (1800s for
  e2e-incus, 180s for `incus exec`), separate stdout/stderr pipes, no shell pipe to
  deadlock on; the helper test proves stream separation.
- **Autoload failures** — clean and explicit. Root autoload missing throws before
  anything else and exits 2; the `apps/cli` fallback is guarded by `class_exists`
  and throws if `Process` still is not there.
- **Discoverability and catalog** — clean. The rig is documented in
  `firewall-doctor.md` and `prepared-topologies.md`; catalog additions match the
  new docs test-mapping rows for `firewall:allow`. The doctor doc's mapping rows are
  outside the catalog's command-scoped generation, consistent with the pre-existing
  `FirewallRuleProbeTest` row. CLI invocations in the rig match the real signatures
  (`firewall:allow {name} --node --port --from --protocol --json`, `firewall:list
  --node --json`, `firewall:remove {name} --node --force --json`), and omitting
  `--reason` is what makes `orbit:private-api` the expected managed identity.
- **~1,000-line rig proportionate** — acceptable, not a material regression. It does
  re-implement env loading, JSON extraction, and manifest reading that `apps/e2e`
  already owns, but the `bin/` placement is a deliberate lane boundary (agents must
  never enter `composer test:e2e*`), the helper/executable split keeps generic globals
  out of the test process, and every branch that can produce a verdict is covered by
  `FirewallRetainedProofHelperTest`. Simplification is optional.
- **Secrets** — none. The only literals are private CIDRs (`192.168.1.0/24`,
  `10.6.0.0/24`, `10.9.9.9/32`). `.env.e2e` is read at runtime and is gitignored.
  Note that `run_e2e_incus()` failure messages embed full child stdout+stderr into
  the receipt's `observed` field; the receipt lands under `.orbit/` which
  `.gitignore:9` excludes, so it stays local.
- **Behavior change worth naming** — `LocalFirewallRuleAction::commentArguments()`
  previously used `$shape->reason ?? …`, so an empty-string reason produced
  `['comment', '']`. It now falls back to `orbit:<name>`, matching what the gateway
  canonicalizer and probe already expected. That is a convergence fix, correctly
  documented in the firewall-allow test-mapping row.

## Evidence

- `.orbit/quality-gates/quality-check-2026-08-23T100434Z-ec8d03340554.json` — exit 0,
  all 47 subgates 0, `commit=4c5c15c15…`, `dirty=false`. Candidate-bound.
- `.orbit/evidence/firewall-retained-proof/4c5c15c15…json` — `result=passed`,
  `host=beast`, `target=dev-501dc2`, owned instance triple, digest
  `ae830c07f68e…`. No `remote_head` field (D2).
- `.orbit/workers/handoff/impl-firewall-4c5c15c15….md` — honest; names
  `composer test:e2e*` not run and the shared topology retained.

## Blast radius

Production risk is confined to D1 and is bounded to firewall-target resolution for
nodes whose platform carries an uppercase character after a lowercase `ubuntu`/
`ubuntu_` prefix. It fails safe on the mutation surface (allow/list refuse) but
fails open on the Doctor surface (probe, family resolution, and `--adopt` all treat
the node as a firewall target), so Doctor can adopt and report firewall rules the
gateway will not serve. No currently registered node is known to carry that shape —
`PlatformDetector` yields `ubuntu_24-04` on stock Ubuntu — so this is a latent
contract split, not a live outage. Everything else in the diff is either
proof-rig-local (D2, P1–P5, P8) or inert (P6, P7). The `ManagedUfwComment`
extraction and the empty-reason convergence in `LocalFirewallRuleAction` are
behavior-narrowing in the safe direction and covered by new tests in all three
consuming suites.

candidate=4c5c15c15d0e3d1ff9af0e219beb2c1e4f307792
