# JavaScript maintenance

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

`@nckrtl/js-maintenance` runs npm, Bun, Vite+, and Expo maintenance in a
supplied checkout. The checkout needs no maintenance package, PHP, or Composer
for JavaScript work. This extension does not commit, publish, run project
checks, or open PRs.

`exceptions` supplies schema 1 release-age records from project config (default
`{ "schema": 1, "exceptions": [] }`). No method reads or writes
`maintainer-exceptions.json`. Security methods emit an `exceptions` resource
before adding a package-manager exclusion. This receipt is returned even after a
completed command failure when `captureFailure` is true. The caller persists it
in the private project extension. These resources have an infinite lifetime.

## Inputs and resources

All four methods take `cwd`, an existing checkout directory, and `paths`, a list
of relative application directories (default `["."]`). Paths must stay inside
the checkout and must not repeat, including through symlinks. All selected paths
are validated before an update starts. `routine`, `detect`, and `package`
require `package.json` in each path. `summary` also accepts Composer-only paths.

`policy` is a map from selected path to `{ "minimumReleaseAgeDays": 7 }`. It is
only an assertion against the repository's existing release-age rule. It never
creates a rule or sets its age. A Bun security exception requires the asserted
age to match `ceil(install.minimumReleaseAge / 86400)`. With no existing Bun age
rule, no policy or exception is written. npm's one-install bypass does not need
an assertion. Mixed summaries read Composer policy but do not change it.

Each successful method writes a primary schema 1 resource of the same name
(`detect` writes `detection`). Resources live for 30 days and retain 20
versions. Failures write no success resource. Invalid arguments exit 64; invalid
paths or reports exit 70; missing tools exit 127. Update failures preserve the
command exit code. A security update that leaves findings exits 1. An empty
summary exits 1 with `No dependency changes to summarize.`

Example method arguments:

```json
{
  "cwd": "/work/project",
  "paths": [".", "mobile"],
  "policy": { ".": { "minimumReleaseAgeDays": 7 } }
}
```

## Managers and routine updates

Manager selection follows `packageManager` or `devEngines.packageManager` (`npm`
or `bun`), then the single lockfile when neither field is present. npm requires
`package-lock.json`; Bun requires `bun.lock`. Conflicting declarations, missing
locks, and foreign locks fail before any update. Bun lock JSON may have trailing
commas. No method changes the manager declaration.

When `vite-plus` is a direct dependency, commands use that path's
`./node_modules/.bin/vp`. A missing `node_modules` triggers
`npm ci --ignore-scripts` or `bun install --frozen-lockfile --ignore-scripts`
first. Bootstrap output goes to stderr. An existing `node_modules` without an
executable `vp` fails; a global `vp` is never used. Without Vite+, commands use
npm or Bun directly.

`routine` runs `vp update`, `npm update`, or `bun update` per path, within the
existing declared ranges. It stops at the first failure. It does not run project
checks. All subprocesses use the same clean environment allowlist as Composer
maintenance. Parent agent markers and npm configuration overrides are removed.
The clean environment drops `NPM_TOKEN` and `COMPOSER_AUTH`. Private registries
need explicit credential handling in a later change. Only an Expo npm security
install gets the explicit temporary release-age override.

## Detection

`detect` reads npm outdated JSON or Bun's table. Vite+ runs
`vp outdated --format
json`; Bun may still return a table. Comparisons use
Current and Latest, remove the `*` age marker, and skip non-direct rows such as
a Vite+ alias target. Missing Current values come from the lockfile, never
Wanted. A new minor of `0.x` counts as a major.

Audits use `vp pm audit --json`, `npm audit --json`, or `bun pm audit --json`.
npm `via` chains resolve to the underlying advisory objects. Bun advisories walk
`bun.lock` dependencies, optional dependencies, and peers to their controlling
direct dependencies, stopping at another direct dependency. Unresolvable reports
fail. IDs are real advisory IDs; a package name is never substituted for an ID.
Aliases are reported under their declared names. Each candidate includes `path`.

An Expo SDK major produces one `expo` candidate with `align: expo-install-fix`.
The coupled set is `expo`, `react`, `react-dom`, `react-native`, `expo-*`,
`@expo/*`, `react-native-*`, `@react-native/*`, and `@react-native-community/*`.
Other coupled majors are omitted. A coupled security finding stays on its own
package.

## One-package updates

`package` also takes `path` (default `.`), `package`, `version` (`x.y.z`),
`purpose` (`major` or `security`), and `advisoryIds` (required for security;
empty for major). The target must be one existing direct registry dependency in
exactly one of `dependencies`, `devDependencies`, or `optionalDependencies`.
Aliased, floating, missing, and injected targets fail before a package command
runs.

Majors install `^x.y.z`; security installs the exact version and retains its
manifest section. Security validates the supplied advisory IDs before changing
policy or installing, then audits again. An already fixed exact target can be
retried. No method silences findings, uses `audit fix`, combines unrelated
packages, or changes the repository's release-age policy.

Plain Bun security installs add `--exact` so Bun saves an exact version. This
differs from maintainer and enforces the security pin required here.

For npm, the selected security install passes `--min-release-age=0` (after `--`
when Vite+ delegates). It never writes `.npmrc`. For Bun, an existing
`[install].minimumReleaseAge` permits adding only the selected package to
`minimumReleaseAgeExcludes`. The dated record goes into
`maintainer-exceptions.json`, preserving existing fields and other ecosystems'
records. `detect` reports `stale_exceptions` once the recorded age window
passes; it never removes an exclusion itself. Release-age exclusion lists must
be on one line, as in maintainer.

An Expo target runs `npx expo install expo@x.y.z` then `npx expo install --fix`.
An independent coupled major is rejected. Coupled security targets use their own
package install. Expo npm security gets `npm_config_min_release_age=0` for those
two commands only; later audits get a fresh clean environment.

## PR summaries

`summary` takes an optional `base` commit ref. Without it, the base is the merge
base of `HEAD` and `origin/HEAD`. It compares base and working-tree lockfiles,
including uncommitted changes, for every selected path. It covers JavaScript
and, when present, Composer in one summary. Composer commands are needed only
for a changed Composer path.

The resource contains `title`, `body`, `text` (title, blank line, body), and
`changes`. It uses Composer maintenance's PR description format. Only changed
direct dependencies, additions, removals, and cleared advisories appear. A
nested lock update can clear an advisory while the controlling direct version
stays the same. An advisory still present under another direct name or alias is
not fixed. Only HTTPS advisory URLs become links. Non-root paths appear as
`expo (mobile)` in the table and action/security sections. Rows for each path
are sorted together by package name across both ecosystems.

One update uses `Update <package> to <version>` with `(security fix)` when it
clears an advisory. Additions and removals use `Add` and `Remove`. Several rows
use `Maintenance update for <month> <year>` in UTC. The body includes the change
table and includes `Action needed` and `Security fixes` only when applicable.

## Verification

The Deno tests port the JavaScript cases and fixtures from
`hardimpactdev/maintainer` PRs #1–#5. Only external manager binaries are
stubbed; filesystem access, Git history, schemas, environment cleaning, parsing,
graph walks, policy edits, and resource writes run normally. PHP is used only to
run the upstream test binary fixture. It is not an extension runtime dependency.
Tests use canonical temporary paths so Linux and macOS agree.

```sh
deno test --allow-all extensions/behaviors/js-maintenance/mod_test.ts \
  extensions/behaviors/composer-maintenance/mod_test.ts tests/lib_test.ts
```

Run sequentially; do not pass `--parallel`. CI runs these affected tests on the
organization’s private `self-hosted, hardimpact` runners. This extension has no
schedules and changes no live workflows.

CI checks the runner’s existing PHP 8.5 with JSON and uses
`denoland/setup-deno@v2` for Deno 2.9.8. Deno installs into the runner-owned
tool cache without sudo. A missing PHP runtime or JSON extension fails
immediately. Node, Bun, and Composer are binary fixtures in these tests; CI does
not install them.

## Workflow failure receipts

`captureFailure: true` turns a completed maintenance error into a `failure`
resource with `exitCode` and `message`. It writes no success resource. The
caller must inspect that receipt before advancing. The default still throws.
Abort and infrastructure errors always throw, so a lost worker never looks like
a command that completed and can be repaired.

`snapshot` takes only `cwd`. It writes `snapshot` with the Git HEAD and the tree
of all tracked and untracked, non-ignored files. It uses a temporary index and
leaves HEAD and the real index intact. Composer-only checkouts are supported.
Gateway handoff still owns commits.

Batch range rules and lock synchronization follow the shared
[manifest range policy](../../../docs/website-packages.md#manifest-range-styles).
