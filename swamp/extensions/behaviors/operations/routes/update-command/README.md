# Route mutation results

`@nckrtl/route-update-result` reports the two results the remaining Route
mutation children read: `phase`, which is ready only when the phase's single
child succeeded, and `firewall`, the pinned tuple child's proven result.

Route create, update, target set and removal no longer use it. They follow their
charts in `docs/cli/route/` and read only their step plans; the Gateway records
their outcome from Swamp's run data. The mutation children stay for their other
callers: Instance transfer, Instance destroy and the task-cancel withdrawal.
