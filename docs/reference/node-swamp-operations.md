---
title: "Node Operations in Swamp"
description: "Execution contracts for Node lifecycle Operations and their cross-family children."
---

# Node Operations in Swamp

The Gateway validates Node changes and records their intent. Swamp runs the steps. Each remote run targets its Operation's `node_id` and uses a declared command, bound input, and a recorded result. Reads remain in Orbit.

## Footprint children

`node.converge` takes `{force_artifacts: bool, first_visit: bool}`. Its target is the managed Linux Node. The Gateway records the expected artifact digests and typed payloads before Swamp starts effects. A retry uses that snapshot. Matching artifacts need no effect unless owned drift or a recorded failed agent pin requires repair.

The footprint starts these children only for artifacts that need work:

| Artifact | Child kind | Input | Target |
| --- | --- | --- | --- |
| Caddy | `route.caddy_converge` | `{reason}` | The Node whose Caddyfile changes |
| Private DNS | `route.private_dns_publish` | `{reason}` | The Node serving private DNS |
| Route residue | `route.offline_residue_clean` | `{removed_node_id: null}` | The Node holding orphaned residue after source deletion |
| AI router | `ai_router.collector_converge` | `{}` | The collector Node |

Before private DNS publication, the footprint installs the content-addressed listener release and its service and socket units. It validates the release and units before replacing them. An active listener restarts against its unchanged catalog while its socket stays open. A fresh installation leaves both units inactive. The publication child writes the catalog atomically, then enables and starts the installed socket and verifies DNS answers. Failed listener setup prevents child admission.

The Gateway stores the complete child intents in the parent's `state.child_intents`, with each stable key, kind, Node ID, and input. Swamp submits `{worker, key}` to admit a child. The worker proves the current lease; it supplies no child payload. The Gateway takes the target and input from that stored intent, preserving null values and refusing an absent key. Swamp saves each child identity and reads its terminal result before advancing. 

A pending child makes Swamp post `{worker, keys}` to the parent wait endpoint. The Gateway releases its executor lease, keeps its target hold, and reclaims it after those exact stamped children settle. The workflow ends while waiting. Lease loss stops further writes. 

Swamp reads children through `GET /operation-executor/{parent}/children`; the Gateway stamps `parent_operation_id` and `child_key` at admission. The `children_settled` completion contract requires terminal children and verifies every failed child reported as skipped.

A Caddy render refusal stays skipped. A validation refusal fails the first Node of a rollout when the artifact digest changed. Validation refused while repairing live drift stays skipped. A failed cleanup of Route residue also stays skipped so fleet rollout can continue; its stored residue remains available for the next attempt.

The result uses two closed lists. `artifacts` has `{name, status, reason}`, where status is `applied`, `unchanged`, or `skipped` and reason may be null. `skipped` has `{child_id, key, kind, reason}` and contains only verified failed children. Fleet rollout consumes these same fields.

After a lost remote reply, Swamp reads `GET /operation-executor/{operation}/receipts/{step}`. A settled receipt supplies the result. A running receipt keeps the Operation open. Only an unknown receipt permits another invocation with the same effect key.

## Removal admission and Route evidence

New Node removals pass the departing Node's integer ID as `removed_node_id`. They never use the serving Node's ID as a marker. A null value is reserved for orphaned residue after source deletion whose source relation was deleted.

Removal admission runs the existing guards before changing intent. In particular, an attached Route still refuses removal with the existing Route guard. For admitted cleanup, capture Route IDs, target associations, and each serving Node's required `route_id`, `domain`, and `steps` before any deletion or role shedding can erase them. Mark the Node `removing`, then admit one cleanup child per serving Node. The child has the departing Node ID in its input and the serving Node ID in `Operation.node_id`.

Keep the departing Node's Route associations until every required cleanup child succeeds. A failed or unfinished cleanup keeps those associations for recovery. Finalization may delete the associations and Node only after the Gateway verifies the child receipts. Lane 8 derives its narrow command payloads from this stored intent; the child never accepts caller-selected domains, Route IDs, or steps.

## Bootstrap SSH

Before WireGuard exists, Node enrollment uses the family transport `bootstrap-ssh`. The Operation records the public host, port, login, and pinned host-key fingerprint. Run requests cannot replace those values. The transport permits only declared bootstrap command names and refuses an observed fingerprint mismatch. Bootstrap credentials resolve within the Gateway through the shared secret-reference contract.

The transport registration uses `OperationTransport::schema(Operation, array $run)` and `run(Operation, array $run, array $hydratedInput)`. Schema preparation performs no remote work. The Gateway validates the allowlist, exact bindings, schema, and ownership before dispatching a run.

## PHP package prerequisite

`node.prepare_php_packages` takes `{versions: [selected_php_version], profile: "app-prod"}` and targets the Node in `Operation.node_id`. Deploy, rollback, and Route convergence start it before the production runtime step. The Gateway captures exact recipe package pins before dispatch. Swamp runs `php.install-build` with that immutable input. PCOV and OpenTelemetry placement follow the Node's roles and the recipe. The installer keeps extra modules and uses `fpm: none`, so the dedicated runtime controls its own restart. The child installs packages only and does not configure a runtime.

## Access removal

Adding an access edge and removing an edge without published Metrics remain state changes. Removing a Gateway edge when Metrics may be published records `node.access_remove`. The Gateway records the removal intent. Swamp first asks the Gateway to delete that exact edge, then reloads Caddy. The edge stays deleted if stream revocation fails. Cancellation can stop before edge deletion; afterward the workflow must finish revocation. Swamp reloads Caddy on that Gateway under its publication lock to close existing Metrics streams. Retrying an already absent edge still performs revocation.

A caller that removes its own Gateway edge receives the Operation but cannot poll it after losing access. The CLI reports the admitted Operation and tells the operator to check revocation from another authorized Node.

## WireGuard keys and hub publication

`node.wireguard_key` keeps the existing Gateway, managed-peer, address, and key-collision guards. Admission stores the public key and the Operation together while holding the key-assignment lock through commit. A failed publication keeps that key as desired state. Submitting the same key again still repairs the hub projection.

The Operation targets the peer as its resource and the VPN hub as its execution Node. Swamp runs the family transport `node-wireguard`, which allows only `wireguard.project-hub`. The VPN credential is a Gateway-resolved reference. The transport holds the existing fleet publication lock while it reads current peers, renders the hub configuration, and publishes it through the agent. No rendered credential reaches Swamp or Operation storage.

Node enrollment and removal use the same effect as `node.wireguard_hub_converge`, with `{context_node_id, excluded_node_id: int|null}` and the hub in `Operation.node_id`. Exclusion may name only the context Node. The departing Node stays in Gateway state until its projection child succeeds.

## Integration role children

Node role workflows use `metrics.role_converge`, `analytics.role_converge`, and `websocket.role_converge` with `{}`. Their matching `role_remove` kinds take `{purge_data: bool, offline: bool}`. Each child executes on its serving Node. The integration child owns its runtime and publications. It never deletes the Node role assignment or its Settings.

Before transferring a claim, the parent retains this nonsecret evidence in `state.role_intent`:

```json
{
  "assignment_id": 123,
  "created": false,
  "role": "metrics",
  "source_node_id": 10,
  "target_node_id": 20,
  "role_configuration": {
    "cluster_id": null,
    "source_settings": {"scope_type": "node", "scope_id": 10},
    "target_settings": {"scope_type": "node", "scope_id": 20}
  }
}
```

Role-add admission takes the durable Node target hold before changing an assignment or Settings. It records the assignment claim and `role_intent`, including `created`, in the same transaction. Idempotent replay returns that original intent before it inspects the assignment again.

For add or ordinary removal, `target_node_id` and `target_settings` are null. The original assignment ID stays stable after transfer. `created` records whether this parent created the assignment; it is captured at admission and never inferred from a timestamp or status after restart. WebSocket convergence may roll back a new runtime after publication refusal, while an existing assignment keeps serving its apps. Integration handlers read their existing per-Node Settings through these scope references; no credential value is copied to Operation state. The parent retains the source Settings and Route associations until the removal children succeed.

Kit B stamps `parent_operation_id` and `child_key` on the child. A Gateway handler reads that parent Operation and its retained role intent. `GET /operation-executor/{parent}/children` returns only stamped children of that parent with their IDs, keys, kinds, Node IDs, statuses, error codes, and results. Swamp admits a stored child by posting `{worker, key}`.

`tool.manager_converge` prepares the `apt`, `vp`, or `composer` capability on the target Node, using the Tool family's admission adapter to build its own immutable input. Node workflows supply only the manager name and whether the task VM image already provides it. `fleet.cli_converge {}` delivers the CLI when a Node joins the rollout set. Its failure is a follow-up and does not fail the role. Fleet owns release selection and install proof.
