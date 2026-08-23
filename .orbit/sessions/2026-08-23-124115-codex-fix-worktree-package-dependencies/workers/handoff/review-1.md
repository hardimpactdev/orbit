candidate=bb0d79f1252524955801f1898caae7590a1b6e75

CHECKOUT_PROOF: cwd=/home/nckrtl/orbit/.worktrees/codex-fix-worktree-package-dependencies; branch=codex/fix-worktree-package-dependencies; head=bb0d79f1252524955801f1898caae7590a1b6e75; main=3bbf0742044904654a1a9b6ab7602dc2b7434983; status=clean

# Corrected-tip general review: release image digest reuse

- Corrected candidate: `bb0d79f1252524955801f1898caae7590a1b6e75`
- Base: `3bbf0742044904654a1a9b6ab7602dc2b7434983`
- Prior reviewed tip: `307545beed70bc94e87b1380cc0446e9068634d6`
- Prior handoff: `.orbit/workers/handoff/review-1-307545beed70bc94e87b1380cc0446e9068634d6.md`
- Delta reviewed: `307545bee..bb0d79f12` (8 files, +453/-61), including the
  intermediate deadlock fix `1850023b95246803c7ed692af92ce93f0d8ae99a`.
- Same process and persona as the prior review; focused suites and
  `composer quality-check` were not repeated.

## Evidence consumed

- `.orbit/workers/handoff/impl-2-bb0d79f1252524955801f1898caae7590a1b6e75.md`
  (RED 6 failed/42 assertions on the new FIX cases; GREEN 63 passed/830
  assertions).
- `bin/orbit-feature-proof-receipt --json`: `ok=true`, `problem=null`,
  `candidate=bb0d79f12…`, `dirty=false`, `gate=quality-check`,
  `venue=automated`, `runtime="not applicable"`. Candidate matches HEAD.
- `.orbit/quality-gates/quality-check-2026-08-23T103351Z-70f5b1356656.json`:
  exit 0, 133s, commit `bb0d79f12…`, `dirty=false`, all 46 subgates 0. Subgate
  set is identical to the prior tip's artifact (no coverage was dropped to make
  the gate faster).

## Prior-defect closure

### DEFECT 1 (archive tag vs manifest ref) — CLOSED

- `bin/orbit-release-candidate:611-613` now derives
  `versioned_reverb_image="ghcr.io/hardimpactdev/orbit-reverb:${version}"`,
  `docker tag`s the candidate image to it, and saves *that* ref, so the archive
  carries exactly one `RepoTags` entry.
- The candidate manifest (`:629`) uses `${versioned_reverb_image}@${reverb_digest}`
  and `.agents/skills/release/SKILL.md:418` uses the identical string, so
  `LocalWebSocketRuntimeAction::validateImageArchive`'s `$tags === [$sourceImage]`
  check now holds in both the candidate and the github-release flow.
- The public tag is not pre-published: the only pushes in the script
  (`:528`, `:546`, `:567`) are candidate tags. `docker tag` is local-only.
- Proof is real, not incidental: the docker stub now emits a genuine tar whose
  `manifest.json` records the saved ref, and the new test
  `exports a websocket archive whose RepoTags match the versioned manifest image`
  untars it and asserts `RepoTags === ['ghcr.io/hardimpactdev/orbit-reverb:0.1.200']`,
  asserts the manifest role-image ref equals that same tag, and negatively
  asserts no `docker push` of the bare version tag (the regex correctly excludes
  the `-candidate-` push).

### DEFECT 2 (acceptance provenance) — CLOSED

- New `accepted_pointer` (`:10`) is written only by `cmd_promote_runtime`
  (`:866-872`), after the FrankenPHP destination-digest equality check passes,
  together with an `accepted_at=` key stamped into that candidate's
  `candidate.env`.
- `cmd_build:315` now reads the reuse source from `accepted`, not `latest`, and
  `previous_image_reusable:145-147` independently requires a non-empty
  `accepted_at` on the source state. Both gates must hold.
- The decisive test `does not reuse an unaccepted newer latest candidate when an
  older accepted candidate exists` accepts build 1, produces an unaccepted build
  2 as `latest` with a sentinel `reverb_digest`, and proves build 3 reuses build
  1's digest and records `reverb_source_build_id === $acceptedId`. Acceptance is
  established by actually invoking `promote-runtime --build-id … --accepted`
  (`release_candidate_accept`), not by hand-writing the pointer.
- The pre-existing "missing/malformed/force" test now also covers the
  no-acceptance-record case, since its first build runs with no `accepted`
  pointer and rebuilds.
- Idempotent: re-running `promote-runtime` strips and re-appends `accepted_at`,
  so no duplicate key accumulates.

### DEFECT 3 (FrankenPHP fail-open tag validation) — CLOSED

- `.github/workflows/orbit-release.yml:211-220` replaces the any-tag regex with
  a `str_starts_with` check against the literal prefix
  `ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm@sha256:` plus a
  64-hex digest check. A candidate tag cannot pass, because `-candidate-…`
  follows `bookworm` where the prefix requires `@sha256:`.
- `OrbitReleaseWorkflowTest` adds a dataset case rejecting both a candidate-tagged
  and a version-tagged FrankenPHP ref.
- `SKILL.md:428` still emits `${stable_frankenphp_image}@${frankenphp_digest}`,
  which satisfies the pinned prefix exactly, and the workflow still creates and
  digest-verifies `ghcr.io/hardimpactdev/orbit-frankenphp:<VERSION>`.
- `apps/docs/content/domains/14_php/README.md` now records *why* the family tag
  stays in the manifest ("PHP runtime selection matches that string exactly"),
  which matches the `PhpRuntimeCatalog` behaviour I traced in the prior review.

### DEFECT 4 (untestable fingerprint derivation) — CLOSED

- `source_root` is gone entirely; `fingerprint_owned_inputs:105-125` resolves
  from `$repo_root`, and the hardcoded
  `PATH="/usr/bin:/bin:/usr/local/bin"` is removed. That makes the fixture root
  the fingerprint root, so the harness can exercise the real derivation.
- The harness now backs it: the `git` stub implements `ls-tree` over the fixture
  tree with content hashes, and `release_candidate_write_owned_inputs` creates
  every fingerprinted path.
- The new test proves both directions on an isolated fixture repo — editing
  `README.md` leaves both fingerprints byte-identical and both images reused;
  editing `apps/reverb/composer.lock` moves only the Reverb fingerprint, rebuilds
  Reverb, and leaves FrankenPHP reused.
- The prior POLISH about silent pathspec drops is also fixed: each pathspec is
  now validated individually and an unmatched or failing one aborts the build.
- Removing the redundant `external:` `FROM`/`ARG` lines does not weaken the
  fingerprint — the Dockerfile blob hash from `ls-tree` already covers that text.

### Deadlock fix (`1850023b95`) — verified, cannot recur

The rewritten function contains no pipeline at all: each pathspec's `ls-tree`
goes straight to its own temp file and is `cat`-appended. The `git | sort |
while read` construct that could block on a full pipe is gone, the reasoning is
recorded in an in-code comment, and both temp files are cleaned up on the
failure paths (the prior version leaked `$listing`).

## Prior POLISH closure

Localized `force_rebuild_images` (`:258`); usage strings corrected to
`--force-rebuild=reverb[,frankenphp]`; the destination-mismatch failure now
names `--force-rebuild=` as the recovery (asserted in the mismatch test);
floating-external-input scope documented in the script help, `SKILL.md:50-58`,
and `PRODUCT_DECISIONS.md`; `cmd_verify` gained `verify_reused_digest`
(`:1015-1046`), which inspects reused Reverb and FrankenPHP digests and folds
failures into the existing non-zero exit.

## New-defect check on the corrections

- No new defect found. Specifically checked and cleared:
- The reverb archive tag is now shared across candidates of the same version
  where it used to be unique per candidate. Not a staleness risk:
  `LocalWebSocketRuntimeAction::ensureImage` has no local-tag short-circuit — it
  always downloads and `docker load`s the archive, then retags
  `orbit-reverb:current` — and `FleetVersionProbe::candidateRuntimeNeedsUpdate`
  keys on `build_id`, not the image tag.
- The new per-pathspec hard failure cannot block real builds: I confirmed all 26
  production pathspecs resolve to non-empty `git ls-tree -r HEAD` output at this
  tip.
- `docker tag`/`docker save` run after digest capture and validation, and the
  reused image is present locally because `alias_candidate_image` ends in
  `docker_cmd pull` (`:518`), which precedes the GHCR config teardown (`:572`).
- Fingerprints computed by the old code differ from the new ones for identical
  content, so the first build after this lands rebuilds rather than reusing.
  That is fail-safe.
- Coupling acceptance to `promote-runtime` (the impl's stated remaining risk)
  is sound in context: `SKILL.md` step 11 runs it only after live acceptance,
  and skipping it simply forces a rebuild.

## Findings (all non-blocking)

### POLISH 1 — `promote-runtime` state rewrite can truncate on a masked grep error

`bin/orbit-release-candidate:866-871`:
`grep -v '^accepted_at=' "$state_file" > "$rewritten_state" || true` followed by
an unconditional `mv`. `|| true` correctly absorbs grep's exit 1, but it also
absorbs exit 2, which would `mv` a file containing only `accepted_at=` over
`candidate.env` and destroy the identity `env`, `verify`, and manifest generation
depend on. Effectively unreachable — the script has already read `$state_file`
repeatedly by that point — but the blast radius is total. Smallest correction:
guard with `[ -s "$rewritten_state" ]` before the `mv`.

### POLISH 2 — `accepted_at` is undocumented state

`promote-runtime` writes `accepted_at=` into `candidate.env`, but the state
inventory at `SKILL.md:276-289` lists only the `accepted` pointer. Add
`accepted_at` to that inventory so state and docs agree.

### POLISH 3 — `SKILL.md` step 12 prose predates the new verify behaviour

The step-12 paragraph still describes `verify` as CLI hashes plus the gateway
digest; the reused Reverb/FrankenPHP digest inspection appears only in the
script's help text. One sentence closes the gap.

### POLISH 4 — the fingerprint pathspec guards are untested

Neither `fingerprint git ls-tree failed for:` nor
`fingerprint pathspec matched nothing:` has a case. Both are fail-closed (they
abort the build), and I verified every production pathspec resolves, so this is
a coverage gap rather than a risk.

### POLISH 5 — `SKILL.md` step 13 re-derives a value `env` now exports

Step 13 hardcodes `ghcr.io/hardimpactdev/orbit-reverb:${version}` while
`candidate.env` now exports `versioned_reverb_image`. Using the exported
variable removes exactly the drift that produced DEFECT 1.

## Blast radius

Prior inventory reused (`rg 'orbit-reverb:|orbit-frankenphp:|role_images|orbit-websocket='`,
425 matches / 98 files / 4966 files searched). The corrected delta changes one
consumer relationship from that inventory — the websocket archive/manifest
pairing — which I re-verified end to end through
`WebSocketRoleBaseline::ensureManifestRuntimeImage` ->
`LocalWebSocketRuntimeAction::ensureImage` -> `validateImageArchive`. The
FrankenPHP consumer reasoning (`PhpRuntimeCatalog`, `WorkloadNodeUpdater`) is
unchanged in behaviour and is now written down in `domains/14_php`. No affected
surface remains unresolved.

## Human judgment

All acceptance actions remain deterministic commands an agent can run and
inspect. No prepared experience requires human judgment about intent, UX, or
real-world behavior.

BLAST_RADIUS: complete
HUMAN_JUDGMENT: not-required
VERDICT: PASS
