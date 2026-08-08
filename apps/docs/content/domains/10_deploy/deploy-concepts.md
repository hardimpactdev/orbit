# Deploy Concepts

This document defines deploy-command-domain vocabulary and invariants. It
supports the deploy command contracts and the [app doctor](../5_app/instance-doctor.md);
it does not override the [Architecture](../../architecture.md).

## Domain and ownership

These terms define the deploy command domain and what it owns.

- **Deploy command domain:** The `deploy:*` command prefix. It manages
  production instance deployment policy, deployment runs, run history, and captured
  deployment output, but it does not create a separate state family.
- **Production app deployment:** Operator workflow for one concrete instance of
  a production app that executes configured deployment steps on the instance's owning node through
  gateway-authenticated Agent push.
- **Deployment policy:** Instance-owned gateway state that defines the
  ordered deployment steps for one concrete production instance.
- **Deployment pipeline:** Ordered deployment step list for one concrete
  production instance. It is not global deployment configuration.

## Steps

These terms describe the units of work that make up a deployment pipeline.

- **Deployment step definition:** Record assigned by the gateway and owned by
  one instance, containing a title, shell command, order, timeout, and optional
  retention metadata.
- **Deployment step command:** Shell script executed during `deploy:run` from
  the instance source path tracked by the gateway, on the instance's owning node. For
  PHP apps, steps that invoke `php`, `composer`, or `artisan` run on the host
  PHP toolchain matched to the deploying instance's own PHP version. All steps execute on the host
  node through a typed internal command carrying `/bin/sh` plus a structured
  argument vector. Step commands may be single-line commands or multiline
  scripts. Deployment has no Orbit-managed SSH fallback.
- **Deployment step order:** Positive integer ordering within a production
  instance's deployment pipeline. Insertions and removals reorder neighboring steps
  to keep the pipeline stable and ascending.
- **Deployment step timeout:** Maximum runtime in seconds for one deployment
  step before Orbit marks that step failed.
- **Retention metadata:** Optional deploy-step metadata for steps that create
  or prune versioned releases. It belongs only to the declaring step; Orbit does
  not have global app deployment retention policy or release state.
  Release-aware steps may move the active `live_path` within the instance-owned
  `releases_path`, but they may not make releases a standalone deploy-family
  state model.

## Runs and logs

These terms describe the runtime side of deployments — how runs are tracked and how their output is stored.

- **Deployment run:** Durable history record created by the gateway and owned
  by one instance, written by `deploy:run` before the first configured step executes.
- **Deployment run context:** Variable map generated once before the first step
  executes. It includes reusable values such as `release`, `app_path`,
  `release_path`, `app_user`, and related app/node metadata. Step commands may
  reference context values with `{{ key }}` placeholders or `ORBIT_DEPLOY_*`
  environment variables.
  `release_path` and `live_path` are path helpers inside the instance-owned release
  boundary. Runtime bind mounts and any live, document-root, storage, or
  database symlink targets must resolve inside the app source or release
  boundary before the production app runtime container is rendered.
- **Deployment run status:** Run lifecycle value: `running`, `completed`,
  `failed`, or `cancelled`.
- **Deployment step execution:** One step's execution within a deployment run,
  including captured stdout, stderr, exit code, timing, and status.
- **Detached deployment run:** Deployment run started with `--detach`; the
  command returns the durable gateway operation identity after execution has
  been handed off, without opening the operations WebSocket subscription.
- **Deployment run history:** Durable instance-owned gateway history of deployment
  runs. Read commands use stored history and do not inspect live node state.
- **Deployment log:** Stored per-step deployment output for a previous run. It
  is captured gateway history, not live streaming output, process manager log
  output, or a node filesystem read.
- **Latest deployment status:** Gateway state owned by the instance that records the
  newest deployment outcome. Instance doctor uses it when evaluating production app
  health.

## Health and boundaries

These terms define what the deploy family owns and what belongs to other families.

- **Deployment health:** Production app health signal derived from deployment
  pipeline validity and latest deployment status. It belongs to
  `doctor --family=instance`, not to a deploy doctor family.
- **Deploy-domain boundaries:** Deploy commands own deployment policy writes,
  deployment run execution, deployment history reads, and captured-output reads
  for concrete production instances. They do not own a state family, create app records,
  manage development apps, create process definitions or schedules, inspect live
  node state during reads, model releases as standalone state, or prove
  production app health after a deployment run.
- **Cross-family invocation:** Deploy steps may invoke documented commands from
  other families as their step command, such as `process:restart [name]`
  after artifact rotation. Lifecycle semantics still belong to the invoked
  family; the deploy family only records the step's exit code and captured
  output as run history.
