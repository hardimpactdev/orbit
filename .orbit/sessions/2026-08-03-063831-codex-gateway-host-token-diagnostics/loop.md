# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-gateway-host-token-diagnostics`
- Branch: `codex-gateway-host-token-diagnostics`

## Goal

1. CLI operation-token verification preserves recognized gateway safe denial
   reasons in live CLI JSON failure codes; unknown/malformed/transport outcomes
   remain generic `invalid_token`.
2. Gateway entrypoint keeps a bind-mounted config root owned by the real host
   Orbit install uid:gid across container/daemon restarts, never the image
   `orbit` uid.
3. Node-owned systemd/launchd process units render only `PATH`/`HOME` (no
   surrogate app Vite/TLS variables), and Doctor marks
   `process.restart_policy_mismatch` plus `process.runtime_environment_mismatch`
   restorable via the existing exact unit re-render path.
4. Close review remediations: document inherited token denial codes on
   `internal:database-query-local`, cover launchd env parity tests, and preserve
   literal backslashes/ampersands when rewriting gateway `.env` values.
5. Post-merge gap: systemd process probe must use exact Environment= multiset
   comparison so extra stale lines (e.g. APP_URL/VITE_*) report
   `process.runtime_environment_mismatch` instead of false healthy.
6. Live ingress gap: site certificate install must use sudo tee+chown when
   existing cert/key files are non-writable even if the desired owner is the
   current orbit user (root-owned leftovers).
7. Live agent gap: agent ACL repair must complete when `install.json` is absent
   while still fail-closing on required `config.json` and CLI binary; fleet-update
   CLI install must write owner install metadata after linked binary verify.
8. Post-deploy Doctor contradiction: `node.security.home_perms` must accept owner
   `0700`-equivalent home plus narrowly managed Agent traversal ACL
   (`u:agent:--x` + mask) so ACL baseline repair and home_perms restore do not
   fight (impossible repair loop with `node.role_baseline_mismatch`).

## Scope

- Owned:
  - CLI token denial-reason propagation
  - Gateway host config-root ownership
  - Node-owned process runtime environment + Doctor restore
  - Fable remediations (token codes docs, launchd env, entrypoint env rewrite)
  - ProcessesProbe exact Environment multiset match
  - Site certificate non-writable install fallback
  - Agent ACL optional install.json + fleet-update install metadata write
    (`LocalAgentAclEnsure`, `LocalFleetUpdateInstallCliAction`, focused tests)
  - ACL-aware `LocalNodeSecurityPostureProbe` home_perms + node-doctor/security docs
- Constraints:
  - install.json optional for ACL; config.json + `/home/orbit/.local/bin/orbit` required
  - install metadata format matches existing `InstallMetadataStore` schema
  - home_perms accepts only managed `u:agent:--x` (+ mask); rejects broader ACLs
  - no live node mutation / candidate publish / merge / push in this reopen
- Out of scope:
  - broader agent bootstrap redesign
  - inventing a second install-metadata format
  - live caddy container mismatch / live Doctor restore on nodes

## Proof

- Verification:
  - focused: passed
    - `bin/orbit-cli-pest --compact tests/Unit/Services/Nodes/LocalNodeSecurityPostureProbeTest.php tests/Feature/InternalNodeSecurityPostureProbeCommandTest.php tests/Unit/Services/Nodes/LocalAgentAclEnsureTest.php` → 18 passed
    - mago format/lint/analyze on probe + ManagedHomeAclAssessor → clean
  - broader: passed - `composer quality-check` on exact clean tip `07896e875705d1a2f58af43b9e26ae5ce6e9331d`; exit_code=0; receipt `.orbit/quality-gates/quality-check-2026-08-03T041522Z-c51b68467766.json`
  - runtime: passed - retained-incus topology `dev-87e9f9` synced to exact tip; LocalAgentAclEnsure required exits 0; getfacl `/home/orbit` is owner rwx + only `u:agent:--x`/`mask::--x`; exact-tip probe `home_perms=true`; added `u:ubuntu:--x` → `home_perms=false`; restored managed ACL → `home_perms=true`; sshd/sysctl false on unbaked dev fixture excluded; evidence `.orbit/evidence/retained-incus-dev-87e9f9-home-perms-agent-acl-07896e875.md`
- Blast radius: complete - evidence=repository-wide consumer/docs search for node.security.home_perms and LocalNodeSecurityPostureProbe remains valid; delta confined to ACL-aware home hardening, ManagedHomeAclAssessor, focused tests, and product docs/PRODUCT_DECISIONS; result=no additional consumers require updates beyond documented home_perms exception
- Review: passed - human-judgment=not-required - independent general reviewer terminal PASS on exact tip 07896e875705d1a2f58af43b9e26ae5ce6e9331d
- Reviewed feature tip: 07896e875705d1a2f58af43b9e26ae5ce6e9331d
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 07896e875705d1a2f58af43b9e26ae5ce6e9331d
- Accepted main tip: ea31c0e6e48027cc97b312a2dd6661ed51421924
- Feature tip under proof: 07896e875705d1a2f58af43b9e26ae5ce6e9331d

## Review findings / remediation

- Blocking (72d9c45d4):
  1. Success paths in `InternalFleetUpdateInstallCliCommandTest` wrote fake 9.9.9 metadata to runner real/default install.json when `ORBIT_INSTALL_METADATA_PATH` was not overridden → **fixed**: file-level beforeEach/afterEach isolates every test to a dedicated temp metadata path with restore + cleanup.
  2. Missing explicit coverage that failed install does not create/alter metadata → **fixed**: artifact hash-mismatch path asserts seeded metadata unchanged and absent file not created; payload hash-mismatch asserts no metadata file.
- Non-blocking then blocking (4b90ddf5d):
  3. `versionFromOutput` first used loose/first triple, then wrong `^Orbit x.y.z$` fake contract → **fixed** on tip ea31c0e6e: parse real VersionCommand human row `Version       x.y.z` (optional update suffix); fixture is three-line `--version --local` table; noise test proves earlier `1.2.3` does not win over Version line `9.9.9`.
- Terminal review (ea31c0e6e): PASS · BLAST_RADIUS complete · HUMAN_JUDGMENT not-required.
- Post-deploy FIX (after ea31c0e6e on main):
  4. `node.security.home_perms` required exact mode 0700 while Agent baseline applies `u:agent:--x` on `/home/orbit` (stat becomes 0710 with ACL mask) → restore chmod 0700 cleared ACL effective posture and reopened role baseline → **fixed** on tip 07896e8757: ACL-aware probe accepts owner rwx + only managed `u:agent:--x`/`mask::--x`; docs + PRODUCT_DECISIONS aligned; tests cover accept/reject.

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
