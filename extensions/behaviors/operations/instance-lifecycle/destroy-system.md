# Instance source removal policy

Execution follows the binding
[Swamp shape guide](../../../../docs/swamp-shape.md). This page preserves source
and data safeguards; it does not define a TypeScript runner, private callback or
local completion journal.

Before teardown, runtime or database effects, capture the admitted account,
checkout identity, Git layout, origin, branch, commit and complete
linked-worktree inventory. Normal removal requires clean source and a commit
reachable from a current origin branch or tag. A clean unpushed commit still
refuses removal.

Force may skip dirty-content and publication checks. It never skips source
identity, origin, layout, branch or ownership checks. Recheck the source fence
after teardown and before database effects, and again before final source
removal. A checkout captured as absent must remain absent; a replacement
directory refuses removal.

Linked-worktree removal touches only its recorded administration entry. Preserve
sibling worktrees and branch references. Stop Schedules before removing
Processes, preserve named volumes by default, and complete Route withdrawal
before database removal. A cancellation, foreign descriptor or backend refusal
stops the parent before source finalization.

Represent actions as native placed steps with native guards, failure conditions
and resume. Resolve any repository credential from a vault reference; never put
it in argv, logs or outputs. This policy grants no permission to delete a
production resource.
