---
title: "Instance cloning"
description: "Clone a candidate's committed source and stored configuration into a production Instance through one native Swamp workflow."
covers:
  - apps/gateway/app/Actions/Instances/StartInstanceCloneAction.php
  - apps/gateway/app/Domain/Instances/{InstanceCloneProjection,NativeInstanceProvisioningIntent}.php
  - apps/gateway/app/Http/{Controllers/Api/InstanceClonesController.php,Requests/Instances/CloneInstanceRequest.php}
  - apps/cli/app/Commands/Instances/CloneInstanceCommand.php
  - packages/php-sdk/src/Requests/Instances/CloneInstanceRequest.php
---

# Instance cloning

Cloning turns an active candidate into a new production Instance on an `app-prod` Node. The Gateway reserves the target, copies the candidate's stored environment values and captures public intent. One native Swamp workflow, `operation-instance-clone`, runs on Node 209 and reaches the Nodes through official `@swamp/ssh` and `@swamp/cfgmgmt` actions. The candidate keeps running. The [chart](/cli/instance/clone) shows each step.

```bash
orbit instance:clone <candidate> <node> <name> --preview-name=NAME [--branch=BRANCH] [--sqlite-source-path=PATH]
```

The CLI calls `POST /api/v1/instances/{candidate}/clone`. The caller needs access to both Nodes. An `Idempotency-Key` returns the same admission after a lost response. The request accepts no Project, commit, Unix user or filesystem path other than the SQLite source.

| Input | Meaning |
| --- | --- |
| `node_id` | Active Linux Node with an active `app-prod` role. A web Project also needs the Node's TLD. |
| `name` | New production Instance name. |
| `preview_name` | Combined with the Node TLD into the private preview domain, such as `shop.com.prod.orbit`. |
| `branch` | Repository branch for the target; defaults to the candidate's recorded branch. A missing branch is refused. |
| `sqlite_source_path` | Absolute path to one SQLite database on the candidate that seeds the clone's own database. |

## Candidate rules

The candidate must be active, have a recorded source profile, and keep its recorded branch checked out. Native read steps verify the repository identity, branch, commit, clean working tree and absence of submodules before the clone writes anything. Git hooks and filesystem monitors are disabled. An origin that contains credentials is rejected on the Node before its value can enter native history.

A candidate in a container is refused with `operation.kind_unavailable`: the clone reads a checkout. See [the default Instance](/reference/development-containers#the-default-instance).

## Destination checks

The Gateway refuses before it reserves anything:

| Error code | When |
| --- | --- |
| `instance.node_inactive` | The Node is inactive or not Linux. |
| `instance.node_not_app_prod` | The Node has no active `app-prod` role. |
| `instance.production_placement_conflict` | The Project already has a production Instance on the Node. |
| `route.tld_required` | A web Project's Node has no TLD. |
| `route.domain_conflict` | Another Route owns the preview domain. |
| `operation.kind_unavailable` | A web Project's Node is in an active Cluster, the Node (or the candidate's Node) is a task VM, or the candidate has an attached Database connection. |

Development [`instance:create`](/cli/instance/create) uses the same Route child, but answers a task VM Node with `instance.task_vm_node`. Before its branch is recorded, [Conn branch choices](/reference/conn#threads-in-an-orbit-project) follow the branch selected for its create.

One account and home serve one Project per Node. A candidate with an attached Database connection needs its own native composition. Seed SQLite with `sqlite_source_path` instead.

## What the clone gets

The clone runs as `orbit-app-<project>` in `/home/orbit-app-<project>`.

### Source

Swamp fetches committed source from the admitted Project repository into `releases/initial`. Working files, dependency directories and logs from the candidate are not copied. When the candidate and target use the same branch, their commits must match.

The release's application `.env` is a link to the home `.env`, as in every deployed release. A tracked, unchanged `.env` gives way to the link. Root writes the release-layout marker that [deployments](/reference/deployments) require under `/var/lib/orbit/app-instance-sources/<instance>`, with mode `0600`.

### Environment

The target receives independently encrypted copies of the candidate's stored values. `DB_*` values and WebSocket app credentials are not copied. The Gateway forces production mode: `APP_ENV=production` and `APP_DEBUG=false`.

The Gateway publishes the rendered file as a named vault execution copy. `@swamp/cfgmgmt/file` writes it as the account's home `.env` with mode `0640` and group `orbit-env`.

Before the write, the run creates that group when the Node lacks it and gives it traverse on the home with `setfacl -m g:orbit-env:--x`, as Caddy has traverse on a release. The Node agent's unit holds the group and binds the home back read-only, so it can report the file's key names, never values. Private values never enter workflow inputs or public results. The shared native VP jobs then prepare the checkout's pinned Vite+ runtime as the account. A project without VP requirements skips them.

### Database

With `sqlite_source_path`, the Gateway attaches a new SQLite connection at `<home>/database.sqlite` under `DB`. The rendered `.env` names it.

Swamp copies a consistent snapshot through the SQLite backup API and checks its digest. Development [`instance:create`](/cli/instance/create) runs the same three steps to copy the default Instance's SQLite database; a MySQL default database is copied with `mysqldump` instead. It installs the file once as the account's, with mode `0600`. Swamp removes the snapshot files on every Node, also after a failure.

### Processes and Schedules

The Project's production definitions become records at admission. The run installs them enabled but not started. The first deploy starts them.

### Preview Route

A web Project gets a private Route for the preview domain. The Route stays pending until the clone succeeds. The run makes a key and CSR on the Node, and the Gateway signs it. The run then installs the certificate and the Caddy site, and probes the site over TLS from 209.

Private DNS already answers the Node's TLD, so no DNS step runs. The preview site answers TLS at once. Its PHP-FPM pool comes with the first deploy, which converges the production PHP runtime for the profiled version.

### Repository access

When the run starts, the Gateway mints a scoped GitHub App read token for the repository and pushes it into the vault. The fetch step writes it as a temporary `0600` credential file owned by the account, and the next step removes it; the token never enters argv. Without an App installation the file is empty and Git reads anonymously.

## Result and retries

The last step, `Report the result`, prints the clone's public result and fails on its error code. On success the Gateway activates the preview Route, then the Instance, and records the commit, branch, PHP version and Vite+ runtime. The result lists key-only `warnings` for copied values that point at the candidate Node, its development TLD or a path outside the production home, and for development drivers such as `QUEUE_CONNECTION=sync`. Values never appear in warnings.

Failure or cancellation keeps the target reserved and records the error on the Instance and its pending Route. An identical retry reuses the target, its Route and its database connection. A changed candidate, Node, name, preview, branch or SQLite input returns `instance.clone_retry_conflict`. An identical request after completion returns the first successful admission. The candidate's source, Processes, environment and database remain unchanged.

## Next steps

The clone ends with an active Instance and no release. Review the warnings with `env:update` and `env:sync`, record deploy steps with `instance:deploy-step:create`, then run `instance:deploy`. The first deploy selects a release and starts the Processes and Schedules.

A task VM receives its workspace through [`task:start`](/cli/task/start). It cannot use `instance:create` for that workspace.
