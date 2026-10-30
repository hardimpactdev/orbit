# Instance app steps

The Node worker runs as its configured Node user and uses existing passwordless
sudo. Production release steps run `sudo -n -u orbit-app-<ProjectID>`. Development setup and teardown run as the admitted Node account that owns the checkout. Both use direct sudo with a fixed program; the worker cannot select another account or directory through a public step input.
There is no socket, agent service, catalogue, UID selector or Gateway relay.

The caller supplies safe admitted metadata: Instance/Project, release name,
step name, command digest and complete timeout. The current command is read
from the existing Gateway step-list API inside the action, checked against the
admitted digest, and passed privately on stdin. It is never a workflow argument,
resource, report or result. The app environment comes from the canonical app
home's existing `.env`; no worker environment or credential reaches the child.

The app process verifies all UID/GID values, opens real app-owned home/releases
components without following links, and refuses group/world-writable paths.
For development checkouts, the existing managed `0775` layout is accepted only for the admitted account’s primary group; world write and foreign group write refuse. Production release directories keep the stricter no-group-write rule. It parses dotenv as data, refuses loader/Bash environment overrides, and runs
fixed Bash `--noprofile --norc -p -eu` from a sealed script descriptor with a
fresh process group. Timeout and cancellation kill that group. A Linux child
subreaper also kills adopted descendants which created another session. Output
has bounded lines, work and retained bytes, checks the deadline while draining,
and redacts environment values and structured credentials before returning.
Incomplete lines are discarded. Private input and raw errors never enter output.

The direct workflow runs every Project setup and teardown list, including lifecycle parent requests. Current commands come from the existing Project read APIs; every command and timeout must match admission. The fixed `@orbit/ci-caches` setup action is workflow-owned; teardown refuses it. Only proven command failures allow a lifecycle parent to remove a failed creation. Unknown local outcomes retain an unconfirmed classification.

A running list is an uncertain interrupted attempt: native replay projects an unconfirmed failure without executing a command again. The checkout directory lock is shared with the existing native checkout/reset/removal primitives and stays held through script cleanup. Managed Vite+ scope selection uses `/opt/orbit/vite-plus` first, then the first installed home scope.

The Gateway keeps admission and bounded state/result projection. This cutover removes the old PHP step executor, command budget, program hydrator, receipt commands and corresponding execution tests. The old polled behavior refuses this kind. The direct writer is the default; selecting the deleted polled path refuses. Rollback is a revert PR.

The workflow requires exact `orbit-node-<admittedNodeId>` placement and a matching Node label. Only an intent actually admitted to Node 209 selects its existing `swamp209-migration-a` worker; other Nodes have no fallback. Worker readiness and a real dev or disposable target are required. When released code is needed, merge the complete kind first, then prove Gateway start-binding, first-read, sudo action and result projection within minutes of auto-release. Revert the cutover immediately on failure. No separate candidate Gateway is required. Existing Node 209 workers cannot run a foreign Node's local action, and their sudo proof is not a development target. No role, enrollment, credential or production changes are included. The package sandbox remains unchanged and has no sudo.

Parent native workflows reuse `runProjectStepList` from `instance-deploy/run_list.ts` inside the same run. Pass the admitted `instance_id`, `project_id`, `list`, `execution` target and ordered step names, digests and full timeouts. Its `read` port returns the current canonical list plus cancellation state; its `program(step, execute)` port delivers the script only inside a private callback. The helper runs each command with the existing fixed sudo action and returns a bounded lifecycle result or `{cancelled:true}`. It starts and projects no Gateway Operation. Dev checkout targets retain the admitted configured Node account and ownership; this helper neither provisions a production app account on a dev Node nor grants one checkout access.
