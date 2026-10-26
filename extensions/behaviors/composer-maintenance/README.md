# Composer maintenance

`@nckrtl/composer-maintenance` runs Composer maintenance in a supplied checkout.
It needs Composer on `PATH`, and Git for `summary`. It does not need
`hardimpactdev/maintainer` or `vendor/` to detect updates.

Each method takes `cwd` (the checkout root), `paths` (default `["."]`), and
`policy` (default `{}`). Paths are relative to the checkout. Absolute paths,
parent segments, duplicate directories, and symlinks outside it are rejected. A
configured path without `composer.json` is rejected. Config is never read from
`maintainer.json`. `path` selects one configured directory for `package`.

The `policy` input is only an assertion against `vet.json`; it never sets or
changes the release-age rule. It is a map from configured path to
`{ minimumReleaseAgeDays: 7 }`, using the project's existing number of days.
There is no default age. An entry must match that path's existing `vet.json`; a
missing file or missing age is left missing. If Vet has an age rule, supply its
value before a security exception can be added. This prevents config drift and
never creates a policy. The ordinary Composer commands continue to enforce the
repository's Vet rules.

For example, a security method input is:

```json
{
  "cwd": "/workspace/website",
  "paths": ["."],
  "policy": { ".": { "minimumReleaseAgeDays": 7 } },
  "path": ".",
  "package": "livewire/livewire",
  "version": "4.4.8",
  "purpose": "security",
  "advisoryIds": ["PKSA-xhv3-nr94-h8db"]
}
```

Pass the input file to
`swamp behavior run <name> package --input-file input.json`. The version and
advisory above illustrate the input shape; detect the current findings before
choosing a target.

## Methods

- `routine`: sequential `composer update --no-interaction` in every configured
  path. A failed command stops the method immediately.
- `detect`: `composer outdated --locked --major-only --format=json`, then
  `composer audit --locked --format=json`. The schema 1 output matches
  maintainer's Composer candidates, including `path`, both reasons on a package,
  advisory ids, and optional `stale_exceptions`. Nonzero audit finding codes 1–3
  are parsed; unusable results fail the method. No install is run.
- `package`: takes `path` (default `.`), `package`, an exact stable `version`
  (`x.y.z`), `purpose` (`major` or `security`), and `advisoryIds`. A security
  target requires advisory ids. A major target takes none. It must be one
  existing direct registry dependency in exactly one manifest section. Major
  updates write a caret range (`^version`), as maintainer does, so later routine
  runs can receive minor and patch releases. Security updates pin the exact
  version. Both use
  `composer require package:constraint --no-update
  --no-interaction` (plus
  `--dev` when needed), then run
  `composer update package
  --with-dependencies --no-interaction`. Transitive
  dependencies can move with the selected package.
- `summary`: compares direct manifest declarations and locked versions with
  `base`, or the merge base with `origin/HEAD` when omitted. It returns `title`,
  `body`, the complete `text`, and structured `changes`. Text matches maintainer
  PR #5: monthly routine title for multiple rows, optional Action needed and
  Security fixes sections, and a Package / From / To / note table. It audits
  current and base lockfiles; only cleared advisories count as fixes. The base
  audit uses a temporary directory removed after the command. No changes is a
  failure with `No dependency changes to summarize.`

For security, audit runs before any write and again after the update. Unknown
advisory ids fail before mutation. Any advisory still on the selected package
fails verification, including a newly reported one. An already exact, fixed
security target can be rerun. Advisories are never ignored or silenced.

A security exception adds only the target package to the existing Vet
`minimum-release-age-exclude` list. It preserves other settings and records the
UTC date and existing age in root `maintainer-exceptions.json` (schema 1), so
existing records remain useful after removing maintainer. It does not duplicate
or reset records. Detect reports due records while their exclusions still exist;
it does not remove them. Like maintainer, the removal date is the recorded date
plus the age window, not registry release time. This is conservative when the
release predates the update. No release-age policy is invented.

## Swamp data and workers

The behavior exports strict input and output schemas and writes schema 1
resources named `routine`, `detection`, `package`, and `summary`. Swamp versions
these resources on each execution. Consumers use CEL, for example
`data.latest("composer-maintenance", "detection").attributes.candidates`.
Failures throw with the original child exit code for updates; validation and
unusable results use 64 and 70. Missing executables use 127. A remaining
advisory or empty summary uses 1.

Commands use argument arrays and the supplied working directory. Composer
receives the same clean environment allowlist as `@nckrtl/composer`, without
parent agent markers. The 8 MiB stdout limit applies only to JSON reports;
update commands can produce longer output. Methods need only `writeResource` and
the cancellation signal from Swamp. They never call `context.runModel()`, a
Gateway, an LLM, or a host-side orchestration layer. Worker installation and
workflow wiring are separate work. Existing workflows, models, the official
`@swamp/software-factory`, and production serve are unchanged.

## Private distribution

Keep this self-contained directory under `extensions/behaviors/`, following
Swamp's
[manifest-relative extension layout](https://swamp-club.com/manual/tutorials/publish-an-extension).
`manifest.yaml` uses `paths.base: manifest` and `visibility: private` for the
`nckrtl` collective. `swamp auth whoami --json` confirmed the collective's Team
plan allows private extensions on 2026-10-26. Recommend private collective
publishing from this source after review; no separate repository is needed. This
PR does not publish. A later authorized release can use
`swamp extension push extensions/behaviors/composer-maintenance/manifest.yaml`.
Other repos can then pull `@nckrtl/composer-maintenance`; local development can
use `swamp extension source add` with this directory.

## Tests and sources

CI uses `ubuntu-latest` for now. This personal repository has no available
self-hosted runners. Org runner access must be arranged separately.

Run only this extension's tests (one worker):

```sh
~/.swamp/deno/deno test --allow-all extensions/behaviors/composer-maintenance/mod_test.ts
```

Tests exercise the real methods, file parsing, Git comparisons, and policy
writes. Only Composer is replaced by an external fixture binary. Cases and text
come from [maintainer](https://github.com/hardimpactdev/maintainer), PRs #1–#5,
and the website's
[dependency policy](https://github.com/nckrtl/website/blob/main/docs/dependency-policy.md).
See `fixtures/README.md` for provenance and the Composer case mapping.
