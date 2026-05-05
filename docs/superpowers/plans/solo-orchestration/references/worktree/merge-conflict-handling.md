# Worktree Merge Conflict Handling

Used by the worktree merge sub-procedure when `git merge --ff-only` fails or
the diff fails scope verification.

The reconciler never edits a branch and never uses non-fast-forward merges,
`git stash`, `git reset --hard`, `git checkout --`, or broad `git restore`.
Rebase resolution belongs to the implementer inside its worktree.

## `main` advanced after `WORKER_DONE`

Abort the merge, leave the worktree intact, and on the todo post:

```text
RECONCILE_DONE status=NEEDS_DIRECTION reason=branch-behind-main
```

`send_input` to the implementer process for the todo:

```text
Reconciler: branch <branch> is behind main and cannot fast-forward. Inside your worktree run:
  git fetch origin
  git rebase main
Resolve any conflicts, rerun your focused gate, post a fresh WORKER_DONE, and stay open.
```

## Branch has no commits beyond `main`

When the branch is already at `main` (worker posted `WORKER_DONE` and reviewer
posted `REVIEW_APPROVED` before any commit landed), abort the merge, leave
the worktree intact, and on the todo post:

```text
RECONCILE_DONE status=NEEDS_DIRECTION reason=worker-uncommitted
```

`send_input` to the implementer process for the todo:

```text
Reconciler: branch <branch> has no commits beyond main; your worktree at <path> still holds uncommitted edits. Commit your owned-scope changes inside the worktree:
  cd "<path>"
  git add <owned paths>
  git commit -m "<focused message>"
Then post a fresh WORKER_DONE and stay open.
```

## Scope mismatch

When the diff includes paths outside the todo's `Owned Files Or Domains`,
abort the merge and on the todo post:

```text
RECONCILE_DONE status=NEEDS_DIRECTION reason=scope-drift
```

Do not auto-revert.

## Missing worktree or branch

On the todo post:

```text
RECONCILE_DONE status=FAILED reason=missing-worktree-or-branch
```

## Anything else

Abort the merge and on the todo post:

```text
RECONCILE_DONE status=NEEDS_DIRECTION reason=<short reason>
```

The next cycle escalates to the user.
