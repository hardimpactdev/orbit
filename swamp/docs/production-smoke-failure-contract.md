# Production smoke failure policy

Execution follows the binding [Swamp shape guide](swamp-shape.md). Preserve
these domain rules without the retired keyed-receipt, Gateway callback or
child-Operation protocol.

A verified failing smoke blocks the deployed commit before any guarded code
rollback. Keep main and application data unchanged. Migrations must remain
compatible with the previous release. This page authorizes no live rollback or
production data move.

Verify deployment, repository, public Route, workflow, run, attempt and target
identity against executed failing JUnit evidence. Require positive non-skipped
tests and an actual failure or error. A workflow conclusion alone is
insufficient. Missing, unreadable, pending, cancelled, ambiguous or mismatched
evidence requires a hold and assistance; it is never a verified failure or
permission to roll back.

The failed commit remains blocked even if selection moves, its rollback target
is pruned or recovery fails. A newer commit or an operator resume does not erase
that block. Do not pause or resume automation merely as a side effect of
recording the block.

A rollback target must be the exact retained release with prior successful smoke
evidence. Recheck the current selection and target identity immediately before
effects. A changed selection, absent baseline or pruned target refuses rollback
and requires assistance; never choose an arbitrary older release. Observe an
uncertain result before attempting another mutation.

Rollback and manual deployment preserve their pause semantics. Resuming
automation is an explicit operator action after recovery is verified. Do not
write an auto-revert commit or change application data.

Keep existing effect gates disabled until their owner explicitly authorizes a
proof or activation. Unknown smoke evidence preserves the selected release. This
policy creates no worker, enrollment or new access.
