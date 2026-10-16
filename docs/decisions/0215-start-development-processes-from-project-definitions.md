---
title: "ADR 0215: Start development Processes from Project definitions"
sidebarTitle: "0215 Development Process definitions"
description: "In progress. Project Process definitions accept the development environment and a preset. The action that makes production copies also makes development copies at the end of each successful development setup, starts them, and Instance removal removes them."
---

# ADR 0215: Start development Processes from Project definitions

A Project Process definition can list `development` as well as `production`, and a development definition can name a preset such as `vp-dev`. At the end of each successful setup of a development Instance, Orbit creates the copies that the Instance is missing and starts them. The action that makes production copies today makes these copies too. Instance removal removes them, as it removes every Instance Process. A new development Instance is then ready to use without a manual `process:create`.

## Status

In progress.

Principle: [One way, one name](/mission#principles). A Project declares the Processes of its Instances in one concept, the Process definition, and one action copies it for both environments. It also fits the boundary that each Project defines its own way of working: application commands, such as a database migration, stay in the Project's setup steps. No principle exception is proposed.

This record changes the reference pages only when it is built. The implementation group's first subtask writes them, as for [ADR 0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role), so `main` never describes unbuilt behavior.

## Context

A new development Instance of Mealou (Project 19, Instance 472 on `beast`) was not usable:

1. It returned a Laravel 500 error. The setup steps installed dependencies and built assets, but nothing created the SQLite file or ran migrations. A Project setup step, `prepare-database`, fixed this: it touches `database/database.sqlite` and runs `php artisan migrate --seed --force`.
2. No Vite dev server ran. [Project definitions](/reference/processes-and-schedules#project-definitions) accept only the `production` environment, and only [production cloning](/reference/instance-cloning) copies them. Every development Process in the fleet was added by hand. One of them runs the outdated command `vp run dev -- --host=0.0.0.0` and is stopped. An operator added Process 182 (`vite`, preset `vp-dev`) to Instance 472.

These facts constrain the design:

- The definition request already allows two `environments` values, and `DefinitionEnvironment` has only `production`.
- `InstantiateProjectRuntimeDefinitionsAction` makes production copies. It refuses a development Instance with `instance.runtime_definitions_target_invalid`, keeps definitions through `isForProduction`, and links each copy to its definition in the existing column `processes.source_definition_id`.
- The [`vp-dev` preset](/reference/assigned-vite-ports#the-vp-dev-preset) holds no value of one Instance. Its command reads `${ORBIT_DEV_SERVER_PORT}` from the Instance's Vite environment file, and the Gateway derives the working directory and environment file from the Instance. A definition therefore needs only the preset name.
- `instance:create` runs setup after activation. `instance:register` runs it only with `--setup`. A [task workspace](/reference/tasks#task-workspace) runs setup inside its baseline check, and a routed workspace completes its Route only after that check starts.
- A [transfer](/reference/instance-transfer) keeps the Instance's Process records and moves them to the destination.
- Instance removal already removes every Process of the Instance.
- Production copies are independent of their definitions on purpose. [Definitions on the Project, copies on the Instance](/reference/processes-and-schedules#definitions-on-the-project-copies-on-the-instance) rejects live inheritance.
- [ADR 0209](https://github.com/nckrtl/orbit/pull/1250), in review, turns long operations into durable operations with named, resumable steps. Instance create moves to that model in a pull request of its own.

## Decision

The Gateway owns the definitions, the copies, and the point at which it makes them.

### Definitions

- `environments` accepts `development` and `production`, alone or together. The CLI flag is `--for=development`, `--for=production`, or `--for=development,production`.
- A Process definition `spec` takes either a runtime specification, as today, or `preset`. A preset spec holds `preset` and, where the preset allows them, `restart_policy` and `keep_alive`. It refuses `runtime`, `command`, `image`, `working_directory`, `environment`, `ports`, and `volumes`, as `process:create --preset` does.
- Every preset is accepted; `vp-dev` is the only preset. A preset definition must list only `development`; one that lists `production` is refused with HTTP 422.
- A Project has at most one definition for each preset, because an Instance has at most one Process for each preset.
- Schedule definitions keep accepting only `production`. A development Schedule definition is refused with HTTP 422. This record does not rule development Schedules out: a new ADR can add them when a Project needs one.
- A definition change still touches only the Project. It changes no existing copy, in either environment.

### One copy action for both environments

`InstantiateProjectRuntimeDefinitionsAction` takes an environment parameter, and its caller passes the environment of the Instance's placement: `production` on `app-prod`, `development` on `app-dev`. An environment filter replaces `isForProduction`, and `assertProductionTarget` and `instance.runtime_definitions_target_invalid` are removed. Both environments link a copy to its definition through `source_definition_id`, and the Process JSON field `source_definition` exposes the name of that definition, or null for a Process made by hand.

The two environments differ only in these rules:

| Rule | Production | Development |
| --- | --- | --- |
| Definitions | Process and Schedule definitions that list `production` | Process definitions that list `development` |
| When | Once, at production preparation | At the end of every successful setup run |
| Missing copies | The captured set, installed once | Each definition without a linked copy |
| A name that the Instance already uses | Preparation stops with `process.name_taken` | The definition is `skipped` |
| Initial state | Installed stopped | Installed and started |

### When Orbit makes development copies

The action runs as the last stage of a successful setup run of a development Instance: `instance:create`, `instance:register --setup`, and `instance:setup`. `instance:register` without `--setup` gets no copies; the first `instance:setup` makes them. Transfer and clone make no development copies.

Core exposes the stage as one action. The Tasks extension calls it for a routed task workspace after its baseline setup passes and its Route is complete. Core never names Tasks, as [ADR 0210](/decisions/0210-give-each-extension-its-own-code-boundary) requires. An unrouted workspace gets no copies, because nothing can reach them.

When Instance create becomes a durable operation under ADR 0209, the stage becomes a named, resumable step of that operation. It is safe to run again, because it skips every definition that already has a linked copy.

The stage reads the Project's development definitions at that moment. It handles them by name. Each definition has one outcome:

| Situation | Outcome | Result |
| --- | --- | --- |
| The Instance has no Process with the name and none with the preset | Orbit creates the copy through the Process create path with start, so the Vite port, Route domain, and paths resolve on that Instance | `created` |
| A Process with the name exists, or another Process already uses the preset | Orbit leaves that Process unchanged | `skipped`, with the reason and the existing Process ID |
| The copy fails to install or start | The copy keeps its record with `status: failed`, `failed_step`, and `error_code` | `failed` |

Each setup run skips a definition that already has a linked copy. So a rerun of `instance:setup` fills gaps and never rewrites or restarts a copy. Orbit never changes or removes a Process that this stage did not create.

A `skipped` or `failed` outcome does not fail setup, and the Instance stays. A failed copy can be retried with `process:start`. The stage runs inside the setup request. A copy that has no request time left to start is `failed` with `command.deadline_exceeded`. A setup step that fails still fails setup as today, and then this stage does not run.

### Lifecycle of a copy

A development copy is an ordinary Instance Process. Its desired state is `running`. It sleeps and wakes with its Instance under [hibernation](/reference/app-dev-runtime-hibernation), and `keep_alive` comes from the definition. `process:update`, `process:stop`, and `process:destroy` work on it as on any Process.

A destroyed copy comes back on the next setup run while its definition exists. This is accepted: the definition states what every development Instance runs. To keep one Instance without the Process, stop the copy with `process:stop`. The stopped copy stays linked, so setup skips it and does not start it. To remove the Process everywhere, remove the definition.

Instance removal runs teardown and then removes every Process of the Instance, copies included.

### What this replaces

The hand-made development Processes that duplicate a definition are replaced once. When the implementation is deployed, the operator records each Project's development definitions, then links or removes each hand-made copy, such as Process 182 (`vite`) on Instance 472. Stale Processes, such as the stopped `vp run dev -- --host=0.0.0.0` Process, are removed. The pull request lists each Process it handles and how.

### Surface

- CLI: `process:create NAME --project=ID --for=development --preset=vp-dev`. `--preset` with `--project` needs `--for=development`. `process:update` with `--project` takes the same options.
- API and MCP: `environments` gains `development`, and `spec` gains `preset`. The MCP tools take their schemas from the API.
- Process JSON gains `source_definition`, the name of the linked definition or null. `process:list` shows it.
- The results of `instance:create`, `instance:setup`, and `instance:register --setup` gain `processes`, one item per development definition with `name`, `outcome`, `process_id`, and, for `skipped` and `failed`, `reason` or `error_code`. The CLI prints each item that is not `created` as a warning.
- Documentation: the [instance setup reference](/reference/instance-setup) gains a Laravel and SQLite recipe for the database setup step. The processes reference loses its line "Runtime definitions are copied only to production Instances." under "Managed environment as `Environment=` directives".

## Rejected alternatives

- A separate development Process list on the Project: it duplicates the definition surface, and a Process for both environments would need two declarations.
- A second copy path beside `InstantiateProjectRuntimeDefinitionsAction`: two mechanisms would avoid duplicates in two ways.
- Setup steps that run `orbit process:create`: a step receives no Instance ID or domain, needs the CLI and a Gateway access grant on the Node or task VM, runs while setup holds the Instance's locks, and hides the intent from the Gateway and Doctor.
- Plain specs without presets: the stored command goes stale, and the preset's preparation, readiness check on wake, and Caddy link are lost.
- Copies at activation: an Instance whose setup failed or never ran would get copies that cannot start, because their dependencies are missing.
- A failed copy fails setup: on `instance:create` a single broken Vite would remove the new Instance.
- Live inheritance or a sync command: one definition edit would restart Vite on every Instance, or overwrite copies changed by hand. `process:update` and `process:destroy` already change one copy.
- Fail on a name conflict, as production preparation does: one old hand-made Process would block every `instance:setup`.
- An `adopted` outcome for an identical hand-made Process: it exists only to absorb today's hand-made Processes, which the implementation replaces once.
- Make a copy only once per definition and Instance, so a destroyed copy stays gone: it needs a record of destroyed copies, and `process:stop` already keeps a Process off on one Instance.
- Default setup steps by Project type: Orbit would start to choose application commands, against the generic engine boundary.

## Consequences

- A new development Instance starts with its Vite server and other declared Processes after setup, on every creation path.
- One action and one column hold the copy rules of both environments.
- A fixed definition reaches an existing Instance only after its copy is removed and setup runs again.
- The deploy needs a one-time step that links or removes the hand-made Processes.
- Implementation adds the enum value, the preset spec, the environment parameter of the copy action, the `source_definition` field, the setup stage, the Tasks call, and the `processes` result.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: [0210](/decisions/0210-give-each-extension-its-own-code-boundary) (the Tasks call stays in the extension); ADR 0209 in [#1250](https://github.com/nckrtl/orbit/pull/1250) (the stage becomes a step of the create operation)
- Detail: sections that the implementation group writes: "Development copies" in the processes reference, and "Development Processes" in the instance setup reference
- Verify: Gateway feature tests for definition validation, each setup path, the three outcomes, reruns, task workspaces, and removal; CLI tests for `--for=development --preset`; an Incus run that creates a Mealou-like Laravel Instance and reaches its Vite asset through the Route.
