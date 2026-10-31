# Local CLI convergence

`convergeCli` in `operations/fleet/cli_converge_local.ts` installs the public
plan captured by `CliConvergePlan::input(Node)`. The owning root adds its
admitted agent digest and runs the action on that Node's placed worker. This
helper makes one fixed `cli.install` call, with native uncertainty propagated.
It adds no Gateway request, child Operation, receipt, retry or dispatch path.

The action preserves selected skips, planning failures, update-busy and missing
release mappings. Success contains only the installed version and configuration
outcome. An unexpected installed version or unknown refusal throws. A foreign
CLI returns `cli.foreign_binary`; the owning root projection records the same
foreign state through `NodeCliState`, without adopting or overwriting it.

Callers may inject `invoke`, `signal` and `beforeEffect` to reuse their existing
cancellation boundary. After the mutation starts, a late abort does not erase
its outcome. The existing polled wrapper shares the install-error mapping; its
execution and Gateway projection remain unchanged until its owning cutover.
