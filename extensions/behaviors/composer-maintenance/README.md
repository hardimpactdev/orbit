# Composer maintenance

## Project batches

Scheduled project maintenance uses `plan` and `batch`. `plan` takes the normal
checkout/path/policy arguments plus detection `candidates`. It resolves eligible
versions together in a disposable tracked checkout and returns `targets` and
`blocked` reasons. The source checkout stays unchanged. `batch` takes the same
arguments plus the complete immutable `targets` array and applies them in one
resolver operation per path. Each target contains `path`, `manager`, `package`,
exact stable `version`, `purpose`, `advisories` and `advisoryUrls`.

These methods enforce release age for security and major updates alike,
including resolver-changed transitive packages. They never add an age exception.
Application checks the fresh lock and current advisories before changing files,
refuses stale downgrades and permits exact-target retries after repair. Swamp's
project scheduler owns batch admission; these methods create no groups or PRs.

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

`exceptions` supplies schema 1 release-age records from project config (default
`{ "schema": 1, "exceptions": [] }`). No method reads or writes
`maintainer-exceptions.json`. Security methods emit an `exceptions` resource
before adding a package-manager exclusion. This receipt is returned even after a
completed command failure when `captureFailure` is true. The caller persists it
in the private project extension. These resources have an infinite lifetime.

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
  `body`, the complete `text`, and structured `changes`. The table has Package,
  From, To and Change columns, followed by site or package effects. It audits
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
Gateway, an LLM, or a host-side orchestration layer. The
[group worker workflow](../../../docs/task-worker-maintenance.md) supplies
placement and enrollment. The official factory implementation stays unchanged.

The clean environment drops `NPM_TOKEN` and `COMPOSER_AUTH`. Private registries
need explicit credential handling in a later change.

## Private distribution

Keep this directory and `extensions/behaviors/_lib/maintenance/` together in the
repository. The manifest resolves `behaviors` and `include` paths from
`extensions/behaviors/`; it includes the shared helpers needed in the archive.
`README.md` and `LICENSE` resolve from the manifest directory. See Swamp's
[extension publishing guide](https://swamp-club.com/manual/tutorials/publish-an-extension).
`visibility: private` targets the `nckrtl` collective. Nothing is published by
this change. A later authorized release can run
`swamp extension push extensions/behaviors/composer-maintenance/manifest.yaml`
from the repository root. Other repos can then pull
`@nckrtl/composer-maintenance`. For local development, work from this repository
so relative imports can reach the shared helpers.

## Tests and sources

CI uses the organization's private `self-hosted, hardimpact` runners. It checks
the existing PHP 8.5 with JSON and uses `denoland/setup-deno@v2` for Deno 2.9.8.
Deno installs into the runner-owned tool cache without sudo. A missing PHP
runtime or JSON extension fails immediately.

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

## Workflow failure receipts

`captureFailure: true` turns a completed maintenance error into a `failure`
resource with `exitCode` and `message`. It writes no success resource. The
caller must inspect that receipt before advancing. The default still throws.
Abort and infrastructure errors always throw, so a lost worker never looks like
a command that completed and can be repaired.

Summary accepts optional `impact` with the repair receipt's JSON `report`,
`repairTree` and `checkedTree`. The
[batch PR text contract](../../../docs/website-packages.md#pull-request-text)
defines entries, coverage and publication failures. Without a repair, only
manifest and lock changes may say `No code changes needed.`

Batch range rules and lock synchronization follow the shared
[manifest range policy](../../../docs/website-packages.md#manifest-range-styles).
