# Local development source removal

`destroy_system.ts` performs source inspection and removal in the placed Swamp
method. It runs as the admitted Node account. It uses Deno filesystem
operations, fixed `/usr/bin/git` commands and `/proc` reads. It does not invoke
orbit-agent, a shell, Gateway executor endpoints or another workflow.

Before destructive work, the parent captures the actual account, checkout inode,
Git layout, origin, branch, commit and complete linked-worktree inventory.
Normal removal also checks that the source is clean and the commit is reachable
from a current origin branch or tag. A clean unpushed commit refuses removal.
The parent must run this publication check before runtime, teardown or database
effects. After teardown, `verifyDestroySource` repeats the local source fence
before the database phase. Finalization repeats it before deleting source. Force
skips dirty-content and publication checks. It never skips source identity,
origin, layout, branch or ownership checks.

The local `.orbit-removals` journal binds one removal and member to its admitted
digest. Private files are written without replacing a different journal. Source
finalization stops the account's remaining checkout processes, moves the
captured directory into quarantine on the same filesystem, writes its local
completion marker, then removes quarantine. This filesystem journal is for
native crash recovery. The Gateway receives no step receipt and does not
validate the effects again.

Linked worktrees move and remove only their recorded administration entry.
Recovery checks the common repository, worktree directory and administration
inodes, plus both Git pointer files. Sibling worktrees and branch refs stay in
place. A recorded absent checkout succeeds only while that path remains absent.
A directory created there after capture refuses removal.

Publication uses a private temporary bare repository. Only the fetch process's
environment can contain the existing GitHub read token. Arguments, stderr and
native output never contain it. Repository hooks, credential helpers and
filesystem monitors are disabled. Private Git material is supplied only inside
the parent's approved private-material callback.

`destroy_system_test.ts` exercises real temporary repositories, unpushed refs,
dirty and replaced sources, symlink refusal, worktree isolation, interrupted
quarantine cleanup, process termination and absent-source replay. These tests do
not prove fleet placement or a live Instance canary.

`destroy_runtime_system.ts` delegates to canonical `scheduleSystem` and
`processSystem`; Instance destruction does not implement systemd, Docker or Vite
removal again. The parent stops Schedules, including active services, before
removing Processes. Each owner checks its captured identities and returns its
bounded refusal. An obsolete Schedule script that needs its full protected
command specification refuses under the Schedule owner's rules; the parent never
substitutes marker-only deletion. Volumes are preserved by default. The
canonical Schedule and Process test suites cover these effects.

`destroy_database.ts` consumes one aggregate `direct_destroy.database` intent
for the entire admitted removal set. It exports secret-free server and SQLite
work with exact per-step Node placement. Each placed step composes the DB-owned
`destroyOwnedStep`; its awaited private callback selects only
`material.database_material`. The Gateway must select passwords for that
participant Node through the declared shared private path. No combined password
map enters a native resource. A foreign descriptor, a cancellation or a backend
refusal stops the parent before source finalization.

`runDestroyLocalStep` exports source preparation, runtime shutdown, teardown,
source verification and finalization for composition in an owning native root.
Database fanout is a separate placed phase after `verify_sources` and before
`finalize_sources`. The parent must complete Route withdrawal before starting
that database phase. These lower actions do not register a standalone kind or
prove a complete parent workflow or live canary.
