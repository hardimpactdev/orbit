candidate=bb0d79f1252524955801f1898caae7590a1b6e75

Closes review-1 FIX batch against 307545beed70bc94e87b1380cc0446e9068634d6, keeping the deadlock fix at 1850023b95246803c7ed692af92ce93f0d8ae99a.

Prior-defect closure:
1. Reuse now reads `.orbit/release-candidates/accepted` written by `promote-runtime --accepted` (`accepted_at` on that candidate.env). An unaccepted newer `latest` cannot shadow an older accepted candidate. Covered.
2. Candidate build locally tags Reverb to `ghcr.io/hardimpactdev/orbit-reverb:<VERSION>`, saves that single RepoTags entry, and uses the same ref in the topology-candidate manifest. It does not push the public version tag. Covered.
3. Workflow FrankenPHP check is pinned to `ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm@sha256:<digest>`. Candidate-tagged and version-tagged refs are rejected. Covered.
4. Fingerprints are derived from `$repo_root` (the effective candidate root) with per-pathspec `ls-tree` validation. Fixture coverage proves an owned-input edit moves the Reverb fingerprint and an unrelated file does not. Hardcoded git PATH removed. Covered.
Polish: localized `force_rebuild_images`, comma-separated usage, destination-mismatch `--force-rebuild=` hint, floating-input docs, `cmd_verify` inspects reused Reverb/FrankenPHP digests.

RED: 6 failed (42 assertions) on the new FIX cases (`--force-rebuild=` hint, accepted-not-latest reuse, archive RepoTags, fixture fingerprint, FrankenPHP tag rejection).
GREEN: `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/ReleaseCandidateHelperTest.php tests/Feature/Release/OrbitReleaseWorkflowTest.php` — 63 passed (830 assertions) in 16.08s.

Changed files:
- `bin/orbit-release-candidate`
- `.github/workflows/orbit-release.yml`
- `.agents/skills/release/SKILL.md`
- `PRODUCT_DECISIONS.md`
- `apps/docs/content/tech-stack.md`
- `apps/docs/content/domains/14_php/README.md`
- `apps/gateway/tests/Feature/E2ESupport/ReleaseCandidateHelperTest.php`
- `apps/gateway/tests/Feature/Release/OrbitReleaseWorkflowTest.php`

Proof receipt (`bin/orbit-feature-proof-receipt --json`):
```json
{
    "ok": true,
    "problem": null,
    "candidate": "bb0d79f1252524955801f1898caae7590a1b6e75",
    "dirty": false,
    "docs_only": false,
    "gate": "quality-check",
    "artifact": "/home/nckrtl/orbit/.worktrees/codex-fix-worktree-package-dependencies/.orbit/quality-gates/quality-check-2026-08-23T103351Z-70f5b1356656.json",
    "venue": "automated",
    "runtime": "not applicable"
}
```

Remaining risk: acceptance is still `promote-runtime --accepted` rather than a separate live-topology acceptance command; that matches the FIX brief. Worktree is clean.
