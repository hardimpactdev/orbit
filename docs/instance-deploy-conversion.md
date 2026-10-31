# Instance deployment execution

## Native development deployment

Default development Instances use `operation-instance-deploy-direct` on the admitted Node target and matching Node label. One native run fetches, checks out, prepares Vite+ when required, runs the ordered Project development-deploy commands, and verifies the resulting origin, branch and commit. The Gateway owns admission, fresh record validation and bounded history projection. It has no development step runner or child plan.

The admitted environment selects the account: development uses the configured Node user; production uses `orbit-app-<ProjectID>` in its release. Caller input selects neither. The development root freezes the delivered agent digest and refuses an installed digest mismatch. Its final source inspection runs as the Node account, without sudo. Application steps use the proven sudo runner with cleared environment and full timeouts.

Source preparation and scripts consume private callbacks only during delivery. Repeated or retained callbacks cannot repeat or start later effects. A delivery failure preserves a confirmed local result; an uncertain action cannot become success through another callback. Public repositories carry a null Git credential. Private bytes stay outside native inputs, resources and public results.

Required failures stop the list. Best-effort failures add warnings and allow later steps. Verified success advances the seed; failed or cancelled work keeps the prior successful seed. A terminal replay does nothing. A running invocation with no terminal proof refuses reexecution rather than repeating source or scripts.

Vite+ projects only public pins and checking, active or failed reference state through the same parent endpoint. Pending default Route publication must be settled by the independent repair owner before dev deployment admission. This root creates no Route, Process, Schedule or prerequisite child Operations.

## Retained production execution

Swamp executes `instance.deploy` and `instance.rollback`. Orbit admits the request, owns deploy-step configuration, and records deployment history and Operation receipts. Automatic deployment policy and production smoke recovery continue to use their existing Gateway admission endpoints.

Production deployment prepares one Operation-owned release, synchronizes its environment, runs before-activation steps, selects the release, refreshes PHP when configured, restarts opted-in running Processes, runs after-activation steps, and prunes old releases. A failure before selection keeps the previous release. A failure after selection reports the new selected release. Cleanup failure leaves the deployment successful and records a warning.

Other development Instances refuse deployment. Development rollback continues to refuse `deployment_config.unavailable`; rollback is production-only.

Rollback validates the exact retained release again, selects it, refreshes PHP when configured, and restarts opted-in running Processes. It runs no deploy scripts and changes no database data. Smoke recovery keeps its caller-bound failure, retained target and selected-release guards. Operator pauses, smoke holds, disabled automation and blocked commits are checked again before automatic effects.

The Services runner discovers claimable kinds through read-only calls. It dispatches only `{kind, worker}`. The placed Instance workflow claims through that same authenticated Node and saves its Operation assignment before effects. A retry never claims another Operation; it verifies terminal completion through the read API or heartbeats its saved assignment. A lost lease stops that invocation.

Each Node effect has a stable key. A saved successful receipt is reused after restart. Release preparation uses one deterministic release name per Operation. An arbitrary deploy script with an uncertain outcome is never run a second time: execution ends interrupted and keeps the evidence for an operator. A transport failure alone never proves that a script did not run. An `operation.execution_uncertain` reply leaves execution open for the original target receipt; it never becomes a fabricated failed run result. A lost completion reply replays the saved completion without Node work.

The Gateway allows only the family's named agent commands and exact inputs bound to admitted intent and proven prior receipts. It adds Git credentials, environment contents and redaction values in memory immediately before transport. These values never enter Swamp resources or Operation input, results, steps or responses. Every required step must match a successful keyed Node receipt before a successful completion can update deployment history.

The production release still requires the owning Process, Schedule, PHP, environment and Route contracts. Those callers stay in place until the production root replaces them. Executor-Node self-recovery exceptions keep their existing admitted selection.

## Development release gate

Before releasing the development writer, fence development deployment admission and let every pre-cutover development Operation and its children reach terminal through their current owner. An empty read without an admission fence does not prove a drain. Never adopt an old Operation into the direct writer or replay an uncertain effect. The Gateway change removes the development execution contract, so unresolved legacy work blocks its release.

After normal Swamp source acceptance, verify the exact workflow UUID and existing Gateway principal's run/read grant before the Gateway writer releases. Run the released writer on a disposable Beast development Instance and revert the pair on failure. This source fixture does not prove worker readiness, effective grants or a live canary.

Project setup and teardown lists use `instance.run_steps`. The Gateway binds the list digest and repository-root checkout, hides stored programs behind protected references, and proves each result. Swamp preserves the difference between confirmed command failure, busy refusal, deadline and unknown transport outcome. Lifecycle parents own rollback decisions. Retained Gateway callers admit after committing and use the bounded `AwaitOperation` reader.

Deployment children own environment synchronization, Vite+ readiness, Process/Schedule installation, PHP packages and Process restarts. The parent admits and waits for a child before starting its receipt step. A pending child releases the parent worker with no running parent step; after the child settles, the parent records the bound child receipt before advancing. This also applies to Vite+ manager prerequisites and rollback runtime children. The family uses the shared B child and I receipt clients. The Gateway gates unavailable children and list-result capabilities before admission. Explicit production environment synchronization remains gated until C supplies the exact restarted Process-ID list; it never falls back to PHP execution. URL-only Route children and production PHP/web-root preparation derive their targets from Gateway-stamped parent metadata and admitted target sets.

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

The deploy sudo runner accepts an optional admitted Vite+ runtime. It runs the
script through the exact Node and package-manager pins. Runtime paths must be
owned by the admitted runtime account and free of writable or symlink components.
This runner grants no filesystem access. A production app that cannot traverse
the runtime store refuses before its script starts. Setup and teardown keep their
existing account and directory contract.
