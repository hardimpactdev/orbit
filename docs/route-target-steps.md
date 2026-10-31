# Native Route target steps

`operation-route-target-set` runs preparation, certificate signing, publication, association cutover and rollback in one native root. Every effect is placed on its admitted Node worker. Certificate signing runs on control worker 209. The root alone posts its final result.

`extensions/behaviors/operations/routes/target-set.ts` exports `readTargetParticipant(gateway, args, signal?, {recovery?})`, `routeTargetTransition(gateway, checkpoint)` and `routeTargetActions(workloads?, network?, certificates?)`. The local actions expose `prepare`, `lan`, `firewall`, `verify`, `synchronizeEnvironment` and `cleanup`. Instance transfer composes the same steps with its own truthful parent kind and run. Its Gateway projector owns its association or placement transition.

A running domain checkpoint changes stored intent. Canonical publication methods then fetch a fresh caller-scoped render inside the Node publication lock. No workflow uses a pre-rendered publication snapshot or a child Operation.

Forward reads refuse cancellation. Restoration explicitly uses `recovery: true`, which permits cleanup while the parent is unfinished and cancellation is recorded. Operation, kind, root run and placed Node checks remain mandatory. Terminal parents refuse every effect.

The Gateway records the configured Node user. PHP and certificate programs resolve the actual local account before writes. Account homes, groups and private keys never come from a caller or a guessed path.

Fixed Caddy, PHP, firewall and certificate programs use `routes/local-program.ts::runLocalProgram(program, input, signal?)`. DNS uses `routes/dns.ts::runPrivateDns(input)`. Endpoint probes use `routes/verification.ts::verifyLocal(input)`. These helpers use the shared Node command transport, which selects the placed worker's local process or its pinned SSH proxy. Private JSON stays on stdin on both paths. A program already started completes its bounded transaction; cancellation is checked before the next effect.
