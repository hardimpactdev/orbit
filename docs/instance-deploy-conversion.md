# Instance deployment execution

Swamp executes `instance.deploy` and `instance.rollback`. Orbit admits the request, owns deploy-step configuration, and records deployment history and Operation receipts. Automatic deployment policy and production smoke recovery continue to use their existing Gateway admission endpoints.

Production deployment prepares one Operation-owned release, synchronizes its environment, runs before-activation steps, selects the release, refreshes PHP when configured, restarts opted-in running Processes, runs after-activation steps, and prunes old releases. A failure before selection keeps the previous release. A failure after selection reports the new selected release. Cleanup failure leaves the deployment successful and records a warning.

A development default resolves the Project branch, checks out the target commit in place, and runs the Project's development deploy steps in order. A best-effort step failure records its exit code and warning, then continues. A required failure leaves the checkout at the attempted commit and keeps the previous successful seed. Successful completion advances the seed to the proven checkout commit. Other development Instances refuse deployment.

Rollback validates the exact retained release again, selects it, refreshes PHP when configured, and restarts opted-in running Processes. It runs no deploy scripts and changes no database data. Smoke recovery keeps its caller-bound failure, retained target and selected-release guards. Operator pauses, smoke holds, disabled automation and blocked commits are checked again before automatic effects.

The Services runner discovers claimable kinds through read-only calls. It dispatches only `{kind, worker}`. The placed Instance workflow claims through that same authenticated Node and saves its Operation assignment before effects. A retry never claims another Operation; it verifies terminal completion through the read API or heartbeats its saved assignment. A lost lease stops that invocation.

Each Node effect has a stable key. A saved successful receipt is reused after restart. Release preparation uses one deterministic release name per Operation. An arbitrary deploy script with an uncertain outcome is never run a second time: execution ends interrupted and keeps the evidence for an operator. A transport failure alone never proves that a script did not run. An `operation.execution_uncertain` reply leaves execution open for the original target receipt; it never becomes a fabricated failed run result. A lost completion reply replays the saved completion without Node work.

The Gateway allows only the family's named agent commands and exact inputs bound to admitted intent and proven prior receipts. It adds Git credentials, environment contents and redaction values in memory immediately before transport. These values never enter Swamp resources or Operation input, results, steps or responses. Every required step must match a successful keyed Node receipt before a successful completion can update deployment history.

Deploy Swamp PR #77 before the Gateway release in Orbit PR #1590. The Gateway release requires kit I, kit B/D, the Process/Schedule children, the Node PHP preparation child and `route.converge`, in that order. It also requires kit C for production environment refresh and lifecycle composition for production cloning. Its registry check refuses a release with missing contracts. Drain the PHP deploy workers before migration; the cutover migration ends legacy deploy/rollback Operations and their history as `deployment.interrupted`, releasing held targets without Node effects. Completion uses proven activation and previous-selection receipts even when the Node is unreachable. First deploy a designated development Instance and retain its Operation ID, steps and result. Then observe a normal automatic production deployment of Hauzer (71) or Mealou (72). Forcing a production deployment requires approval from the orchestrator.

Project setup and teardown lists use `instance.run_steps`. The Gateway binds the list digest and repository-root checkout, hides stored programs behind protected references, and proves each result. Swamp preserves the difference between confirmed command failure, busy refusal, deadline and unknown transport outcome. Lifecycle parents own rollback decisions. Retained Gateway callers admit after committing and use the bounded `AwaitOperation` reader.

Deployment children own environment synchronization, Vite+ readiness, Process/Schedule installation, PHP packages and Process restarts. The family uses the shared B child and I receipt clients. The Gateway gates unavailable children and list-result capabilities before admission. Explicit production environment synchronization remains gated until C supplies the exact restarted Process-ID list; it never falls back to PHP execution. URL-only Route children and production PHP/web-root preparation derive their targets from Gateway-stamped parent metadata and admitted target sets.

The live proof uses a new isolated Project with its own `default` development Instance on Beast Node 9, a harmless fixture repository and synthetic data. Send the new Project and Instance IDs to the orchestrator before running it. Preserve all existing defaults. No public Route, DNS, access change, production copy or forced production deploy is part of that proof.

## Reusable environment child

`instance.sync_environment` runs preparation, the main environment write,
managed testing-file merge, additional Route URL writes and the final receipt.
Gateway descriptors use `instance-environment-agent`; they contain protected
references, not values or encoded environment files. The child never restarts
Processes or refreshes PHP itself. Those remain separate parent stages.

URL-only web-root and rollback keys may omit the main and testing writes. A
proven refusal stops later phases. A lost reply leaves the child unfinished so a
later invocation can adopt the original target receipt. Cancellation stops new
phases without claiming that an uncertain write succeeded. Existing synchronous
Gateway callers keep their native path until their owning cutover.
