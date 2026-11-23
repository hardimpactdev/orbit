---
title: "Instance cloning"
description: "Clone an active development or production candidate's committed source and stored environment into a new production Instance."
covers:
  - apps/gateway/app/Actions/Instances/StartInstanceCloneAction.php
  - apps/gateway/app/Domain/Instances/{InstanceCloneProjection,NativeInstanceProvisioningIntent}.php
  - apps/gateway/app/Http/{Controllers/Api/InstanceClonesController.php,Requests/Instances/CloneInstanceRequest.php}
  - apps/cli/app/Commands/Instances/CloneInstanceCommand.php
  - packages/php-sdk/src/Requests/Instances/CloneInstanceRequest.php
---

# Instance cloning

`instance:clone` creates a new production Instance on an `app-prod` Node from an active candidate (an existing checkout Instance or a running container `default` Instance with a recorded seed). The candidate stays untouched and continues running. See [`instance:clone`](/cli/instance/clone) for the step flow.

```bash
orbit instance:clone <candidate> <node> <name> --preview-name=NAME [--branch=BRANCH] [--sqlite-source-path=PATH] [--idempotency-key=KEY] [--json]
```

The CLI calls `POST /api/v1/instances/{candidate}/clone` and requires access grants to both Nodes. Passing an `Idempotency-Key` returns the same admission after a lost response.

| Input | Meaning |
| --- | --- |
| `node_id` | Active Linux Node with an active `app-prod` role (web Projects also require a Node TLD). |
| `name` | New production Instance name (lowercase DNS label up to 63 characters). |
| `preview_name` | Combined with the Node TLD to form the private preview domain (for example, `shop.prod.orbit`). |
| `branch` | Repository branch for the clone; defaults to the candidate's recorded branch. |
| `sqlite_source_path` | Optional absolute path to a SQLite file on a host-checkout candidate to seed the clone's database. |

## Candidate rules

The candidate must be active, have a recorded source profile, and have its recorded branch checked out with a clean working tree and no submodules. For a container `default`, wake it first with [`instance:wake`](/cli/instance/wake) so Orbit can verify its `HEAD` matches the recorded seed commit.

Non-default container Instances, SQLite snapshots from containers, candidates with attached Database connections, and Projects with [`nested_fleet`](/reference/projects#nested-fleet-setting) enabled are refused with `operation.kind_unavailable`.

## Destination checks

Orbit validates candidate and destination rules before reserving any record:

| Error code | HTTP | Cause |
| --- | --- | --- |
| `instance.clone_candidate_inactive` | 409 | Candidate or its Node is inactive or non-Linux, or a container default is not running. |
| `instance.source_profile_missing` | 409 | Candidate lacks a recorded `source_is_laravel` profile. |
| `instance.clone_candidate_branch_invalid` | 409 | Candidate has no valid checked-out branch. |
| `instance.name_invalid` | 409 | Instance name is not a valid DNS label. |
| `instance.node_inactive` | 409 | Destination Node is inactive or not Linux. |
| `instance.node_not_app_prod` | 409 | Destination Node has no active `app-prod` role. |
| `instance.production_placement_conflict` | 409 | The Project already has a production Instance on that Node. |
| `instance.removal_conflict` | 409 | Target Instance is currently being removed. |
| `instance.clone_database_slug_taken` | 409 | Derived SQLite connection slug is already owned by another Instance. |
| `route.tld_required` | 409 | Destination Node has no TLD for a web-serving Project. |
| `route.domain_conflict` | 409 | Another Route already owns the preview domain. |
| `operation.kind_unavailable` | 409 / 503 | Unsupported placement (active Cluster on a web Project, non-default container, nested-fleet Project, or candidate with attached databases). |

## What the clone gets

Each production Instance gets its own Unix account `orbit-app-<project_id>` and home directory `/home/orbit-app-<project_id>` (one production Instance per Project per Node).

### Source

Orbit fetches committed source from the Project repository into `releases/initial` using a scoped GitHub App read token (or anonymous access for public repos). Working files, dependencies, and logs from the candidate are not copied. When candidate and clone use the same branch, their commits must match. Orbit links the release's `.env` to `<home>/.env` and writes the release-layout marker under `/var/lib/orbit/app-instance-sources/<instance>`.

### Environment

The clone receives independently encrypted copies of the candidate's stored environment values, excluding `DB_*` keys and WebSocket app credentials. Orbit sets `APP_ENV=production` and `APP_DEBUG=false`, renders `<home>/.env` with mode `0640` and group `orbit-env` so the Node agent can observe key names without reading values, and prepares any pinned Vite+ runtime required by the checkout.

### Database

When `sqlite_source_path` is provided on a host-checkout candidate, Orbit takes a consistent snapshot via the SQLite backup API, verifies its digest, installs it at `<home>/database.sqlite` (mode `0600`), attaches it under `DB`, and removes temporary snapshot files on both Nodes.

### Processes and Schedules

Orbit copies the Project's production Process and Schedule definitions at admission and installs them enabled but stopped. The first deployment starts them.

### Preview Route

Web-serving Projects receive a private preview Route using `<preview_name>.<node-tld>`. Orbit generates the Node key and CSR, signs it with the Gateway CA, publishes Caddy, and verifies TLS before activating the Route. The production PHP-FPM pool is created on the first deployment.

## Result and retries

On success, Orbit activates the preview Route and Instance and returns key-only `warnings` in the Operation result (flagging copied keys that reference the candidate Node, development TLD, host paths outside the production home, or development drivers like `QUEUE_CONNECTION=sync`). Values never appear in warnings.

If a clone fails, Orbit keeps the reserved target and records the error on the Instance and its pending Route; cancellation records the error on the Instance while leaving the pending Route unchanged. Repeat the identical request to retry (`instance.clone_retry_conflict` if any input changed); repeating after completion returns the original admission.

## Next steps

A newly cloned Instance is active with no live release yet. Review any environment warnings with `env:update` and `env:sync`, add deploy steps with `instance:deploy-step:create`, and run [`instance:deploy`](/reference/deployments) to build the first release and start Processes and Schedules.
