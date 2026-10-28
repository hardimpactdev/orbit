# Preserve manifest range styles in dependency batches

Approved, 2026-10-28, with security floors raised to the fixed version.

## Goal

Keep each repository's existing npm and Composer range styles. Updating
TypeScript from `^5.9.3` to 7.0.2 must produce `^7.0.2`, while an exact pin stays
exact. Security updates raise the range floor to the fixed version, even when the old
range already admits it. Routine and major updates already admitted by a declaration keep it unchanged.
Lockfiles must still contain the exact admitted versions.

The immediate website#17 correction restores six caret declarations and their
npm lockfile root metadata. It also removes the withdrawn upgrade-notes paragraph.
Its hand-written PR body stays unchanged.

## Shape

Make a separate small orbit-swamp fix PR, independent of the approved PR-text
work in #47. The shared maintenance helpers own range transformation; both
batch implementations use that rule. Do not change routine-update policy.

Use the shared [manifest range policy](website-packages.md#manifest-range-styles).
Preserve section membership, unrelated declarations and package-manager
configuration. Record unsupported targets before changing the checkout.

Keep exact candidate constraints inside the disposable planning checkout.
Composer application writes the preserved range and passes the exact target via
its existing `--with` constraints. JavaScript application uses exact constraints
for the joint solve, then restores the intended declarations and uses the native
package manager to synchronize lockfile manifest metadata without install scripts.
Assert that this synchronization leaves every resolved package version unchanged;
a mismatch fails the batch. Validate npm, Bun and Vite+ paths through their actual
supported package-manager commands. Expo SDK compatibility rules still apply;
conflicting range requirements block rather than silently switch styles.

Keep the existing fresh-target, no-downgrade, release-age and advisory checks.
An exact solve is not permission to commit exact manifest declarations. Update
the batch reference and affected extension documentation with that distinction.

## Trust boundaries

The original repository manifest defines range style. Detection supplies exact
eligible targets, not replacement range policy. Package managers remain the
solvers and lockfile writers. The Gateway remains the only task VM provisioner
and GitHub publisher. No Gateway contract or factory change is expected; if one
becomes necessary, propose paired PRs before implementation. Do not touch live
Swamp checkouts, services, groups, approvals or secrets.

## Failure states

Unsupported declarations are blocked during planning. A stale target, unresolved
joint solve, unexpected version movement during lock synchronization or remaining
advisory fails through the current batch/repair/assistance path. Never publish
mismatched manifests and lockfiles or suppress their native consistency checks.
Rerunning a completed exact-target update must preserve the restored range.

## Test plan

Run only affected Deno tests, with at most four workers. Cover caret, tilde and
exact declarations; within-range security updates; supported compound forms;
unsupported forms; dependency sections; mixed batches; and idempotent reruns.
Assert preserved declarations and exact locked targets through Composer and JS
batch execution, including npm root lock metadata and Bun workspaces. Exercise
planning failures and a resolver that moves a version during synchronization.
Use native package-manager fixture checks to confirm lockfile consistency.
Full suites run in CI. Keep the PR below about 800 changed lines, excluding
fixtures and lockfiles.
