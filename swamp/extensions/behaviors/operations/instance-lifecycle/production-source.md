# Production source steps

`runLocalProductionHome` runs admitted production source steps on the Node
worker with local system commands. It supports account and initial source
preparation, branch resolution, profile and clone-candidate inspection, release
layout validation, clearing the selected release, Caddy read access, and home
removal. It does not invoke orbit-agent.

Every input includes the recorded `project_id` and its exact
`orbit-app-<project_id>` account. Home paths stay under `/home/<account>`. The
parent supplies the recorded repository, Instance ID and release paths. Git
credentials remain inside the parent's private-material callback and reach the
fixed local program through stdin. The helper reuses the development source Git
configuration.

The helper keeps source markers in `/var/lib/orbit/app-instance-sources`.
Repeated initial preparation accepts only its own marker and source. Home
removal requires that marker and no running account process. The parent owns
publication withdrawal, runtime removal and the final Gateway projection.

Production URL writing and config-cache refresh are unavailable in this helper.
Callers compose their owning native steps. There is no agent fallback. Frozen
agent commands remain unchanged.

The focused tests use sandbox paths and real Git repositories. Account lookup
and account-changing commands use test boundaries; the tests never change host
accounts. Run
`deno test -A extensions/behaviors/operations/instance-lifecycle/production_home_system_test.ts extensions/behaviors/operations/instance-lifecycle/source_system_test.ts`.
