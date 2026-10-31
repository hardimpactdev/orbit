import { z } from "npm:zod@4.4.3";
import { ExecutorError } from "../../operation-runner/mod.ts";
import {
  canRemoveAfterRollbackTeardown,
  canRollbackSetup,
  type Failure,
  StepListResult,
} from "./contract.ts";
import type { Completion, Receipt, Session } from "./runtime.ts";
export type { Child, Completion, Receipt, Session } from "./runtime.ts";

export class Refused extends Error {
  constructor(readonly code: string, readonly result: Receipt = {}) {
    super(code);
  }
}

function number(input: Receipt, field: string): number {
  return z.number().int().positive().parse(input[field]);
}
async function run(s: Session, key: string): Promise<Receipt> {
  const result = await s.run(key);
  if (typeof result.error_code === "string") {
    throw new Refused(result.error_code, result);
  }
  return result;
}
async function child(
  s: Session,
  key: string,
  kind: string,
  nodeId: number,
  input: Receipt,
): Promise<Receipt> {
  const result = await s.child({ key, kind, node_id: nodeId, input });
  if (typeof result.error_code === "string") {
    throw new Refused(result.error_code, result);
  }
  return result;
}
async function finish(s: Session): Promise<Receipt> {
  return await run(s, "lifecycle-finish");
}

async function route(
  s: Session,
  instanceId: number,
  routeId: unknown,
  key = "route-converge",
): Promise<void> {
  if (routeId === null || routeId === undefined) return;
  await child(s, key, "route.converge", s.nodeId, {
    route_id: z.number().int().positive().parse(routeId),
    instance_id: instanceId,
  });
}
async function steps(
  s: Session,
  phase: "setup" | "teardown",
  instanceId: number,
  digest: unknown,
  key: string = phase,
): Promise<Failure | null> {
  const receipt = await s.child({
    key,
    kind: "instance.run_steps",
    node_id: s.nodeId,
    input: {
      instance_id: instanceId,
      list: phase,
      source: "project",
      configuration_digest: z.string().regex(/^[0-9a-f]{64}$/).parse(digest),
    },
  });
  if (receipt.error_code === "operation.cancelled") {
    throw new ExecutorError("operation.cancelled");
  }
  const result = StepListResult.parse(receipt);
  if (result.outcome === "succeeded") return null;
  return {
    outcome: result.outcome,
    error_code: result.error_code ?? `instance.${phase}_step_failed`,
  };
}
function list(input: Receipt, field: string): Receipt[] {
  return z.array(z.record(z.string(), z.unknown())).parse(input[field]);
}
async function removeFailedCreate(s: Session, id: number): Promise<void> {
  await child(s, "rollback-removal", "instance.destroy", s.nodeId, {
    instance_id: id,
    force: true,
  });
}

function mayCleanupRefusal(error: Refused): boolean {
  if (
    typeof error.result.outcome === "string" &&
    !["failed", "missing"].includes(error.result.outcome)
  ) return false;
  return ![
    "operation.cancelled",
    "instance.lifecycle_busy",
    "instance.source_busy",
    "operation.execution_uncertain",
    "operation.execution_busy",
    "operation.execution_failed",
    "operation.outcome_unknown",
    "operation.receipt_unavailable",
    "operation.child_unproven",
    "operation.lease_lost",
  ].includes(error.code);
}

async function rename(s: Session): Promise<Receipt> {
  await run(s, "rename-inspect");
  if (s.input.domain !== null) {
    await child(s, "route-update", "route.update", s.nodeId, {
      route_id: number(s.input, "route_id"),
      domain: s.input.domain,
    });
  }
  return await finish(s);
}

async function destroy(s: Session): Promise<Receipt> {
  await run(s, "removal-inspect");
  for (const member of list(s.input, "members")) {
    const id = number(member, "instance_id");
    if (member.run_teardown === true) {
      const failure = await steps(
        s,
        "teardown",
        id,
        member.teardown_digest,
        `teardown-${id}`,
      );
      if (failure) {
        throw new Refused(failure.error_code, {
          outcome: failure.outcome,
          instance_id: id,
        });
      }
    }
  }
  await run(s, "removal-revalidate");
  for (
    const routeId of z.array(z.number().int().positive()).parse(
      s.input.additional_route_ids ?? [],
    )
  ) {
    const projection = list(s.input, "additional_routes").find((route) =>
      route.route_id === routeId
    );
    if (!projection) throw new Error("Missing Route withdrawal placement");
    await child(
      s,
      `withdraw-extra-${routeId}`,
      "route.withdraw",
      number(projection, "node_id"),
      {
        route_id: routeId,
      },
    );
  }
  await run(s, "removal-accept");
  for (const member of list(s.input, "members")) {
    const id = number(member, "instance_id");
    await run(s, `removal-${id}-source-prepare`);
    if (member.environment === "production") {
      const routes = list(s.input, "production_routes");
      for (const route of routes) {
        if (z.array(z.number()).parse(route.targets).length === 1) {
          await child(
            s,
            `withdraw-production-${route.id}`,
            "route.withdraw",
            number(route, "node_id"),
            { route_id: route.id },
          );
        }
      }
      await run(s, `removal-${id}-route-detach`);
      for (const route of routes) {
        if (z.array(z.number()).parse(route.targets).length > 1) {
          for (
            const node of z.array(z.number().int().positive()).parse(
              route.nodes,
            )
          ) {
            await child(
              s,
              `publish-production-${route.id}-${node}`,
              "route.caddy_converge",
              node,
              { reason: `Instance ${id} Route ${route.id} removal` },
            );
          }
        }
      }
    } else {
      if (member.route_id !== null && member.route_cleared_at == null) {
        await child(
          s,
          `withdraw-${id}`,
          "route.withdraw",
          number(member, "route_node_id"),
          {
            route_id: member.route_id,
          },
        );
      }
      await child(
        s,
        `withdraw-instance-${id}`,
        "route.withdraw_instance",
        s.nodeId,
        { instance_id: id },
      );
    }
    await run(s, `removal-${id}-route-clear`);
    await run(s, `removal-${id}-source-finalize`);
    const runtime = await run(s, `removal-${id}-runtime-intent`);
    for (
      const processId of z.array(z.number().int().positive()).parse(
        runtime.process_ids,
      )
    ) {
      await child(
        s,
        `remove-process-${id}-${processId}`,
        "process.destroy",
        s.nodeId,
        { process_id: processId },
      );
    }
    for (
      const scheduleId of z.array(z.string().uuid()).parse(runtime.schedule_ids)
    ) {
      await child(
        s,
        `remove-schedule-${id}-${scheduleId}`,
        "schedule.destroy",
        s.nodeId,
        { id: scheduleId },
      );
    }
    await child(
      s,
      `cleanup-instance-${id}`,
      "route.cleanup_instance",
      s.nodeId,
      { instance_id: id },
    );
    await run(s, `removal-${id}-database-intent`);
    await child(
      s,
      `remove-databases-${id}`,
      "database.destroy_owned",
      s.nodeId,
      {
        instance_id: id,
      },
    );
    if (member.environment === "production") {
      await run(s, `removal-${id}-home-remove`);
    }
    await run(s, `removal-${id}-row-delete`);
  }
  return await finish(s);
}

async function create(s: Session): Promise<Receipt> {
  const id = number(s.input, "instance_id");
  const ownedActivation = s.input.initial_status !== "active";
  if (
    s.input.initial_status === "active" && s.input.first_setup_pending === false
  ) {
    await run(s, "source-inspect");
    return await finish(s);
  }
  try {
    if (ownedActivation) {
      await run(s, "source-prepare");
      await run(s, "source-inspect");
      await run(s, "source-resolve");
      await run(s, "source-profile");
      await run(s, "source-configuration");
      await child(
        s,
        "environment-sync",
        "instance.sync_environment",
        s.nodeId,
        { instance_id: id },
      );
      await child(
        s,
        "vp-environment",
        "instance.prepare_vp_environment",
        s.nodeId,
        { instance_id: id },
      );
      await route(s, id, s.input.route_id);
      await run(s, "instance-activate");
    } else {
      await run(s, "source-inspect");
    }
  } catch (error) {
    if (
      error instanceof Refused && ownedActivation && mayCleanupRefusal(error)
    ) {
      await removeFailedCreate(s, id);
    }
    throw error;
  }
  try {
    if (
      s.input.database_server_id !== null ||
      s.input.database_clone_source_id !== null
    ) {
      await run(s, "database-intent");
      const kind = s.input.database_clone_source_id !== null
        ? "database.clone"
        : "database.create";
      await child(s, "database", kind, s.nodeId, {
        instance_id: id,
      });
      await child(
        s,
        "database-environment-sync",
        "instance.sync_environment",
        s.nodeId,
        {
          instance_id: id,
        },
      );
    }
  } catch (error) {
    if (
      error instanceof Refused && ownedActivation && mayCleanupRefusal(error)
    ) {
      await removeFailedCreate(s, id);
    }
    throw error;
  }
  const failure = await steps(s, "setup", id, s.input.setup_digest);
  if (failure !== null) {
    if (canRollbackSetup(failure, ownedActivation)) {
      const teardown = await steps(
        s,
        "teardown",
        id,
        s.input.teardown_digest,
        "rollback-teardown",
      );
      if (teardown?.outcome === "busy") {
        throw new Refused(teardown.error_code, {
          outcome: teardown.outcome,
          instance_id: id,
        });
      }
      if (canRemoveAfterRollbackTeardown(teardown)) {
        await removeFailedCreate(s, id);
      }
    }
    throw new Refused(failure.error_code, {
      outcome: failure.outcome,
      instance_id: id,
    });
  }
  return await finish(s);
}

async function register(s: Session): Promise<Receipt> {
  for (
    const key of [
      "registration-relocate-prepare",
      "registration-relocate-cleanup",
    ]
  ) {
    await run(s, key);
  }
  for (const member of list(s.input, "members")) {
    const id = number(member, "instance_id");
    let receiptPrepared = false;
    try {
      if (member.completed !== true) {
        await run(s, `registration-${id}-receipt-prepare`);
        receiptPrepared = true;
        await run(s, `registration-${id}-profile`);
        await run(s, `registration-${id}-configuration`);
      }
      await route(s, id, member.route_id, `route-${id}`);
      if (member.completed !== true) {
        await run(s, `registration-${id}-activate`);
      }
    } catch (error) {
      if (
        receiptPrepared && error instanceof Refused && mayCleanupRefusal(error)
      ) {
        await run(s, `registration-${id}-receipt-restore`);
      }
      throw error;
    }
    await run(s, `registration-${id}-receipt-discard`);
  }
  if (s.input.run_setup === true) {
    const failure = await steps(
      s,
      "setup",
      number(s.input, "instance_id"),
      s.input.setup_digest,
    );
    if (failure) {
      throw new Refused(failure.error_code, { outcome: failure.outcome });
    }
  }
  return await finish(s);
}

async function clone(s: Session): Promise<Receipt> {
  if (s.input.initial_completed === true) return await finish(s);
  for (
    const key of [
      "clone-user",
      "clone-source-prepare",
      "clone-source-resolve",
      "clone-source-profile",
      "clone-caddy-access",
      "clone-environment",
    ]
  ) {
    await run(s, key);
  }
  if (s.input.sqlite_source_path !== null) {
    const prepared = await run(s, "clone-sqlite-intent");
    if (z.boolean().parse(prepared.seed_required)) {
      await child(s, "clone-sqlite", "database.sqlite_seed", s.nodeId, {});
    }
    await run(s, "clone-sqlite-attach");
  }
  await child(
    s,
    "clone-environment-sync",
    "instance.sync_environment",
    s.nodeId,
    {
      instance_id: number(s.input, "instance_id"),
    },
  );
  await run(s, "clone-definitions");
  await child(
    s,
    "clone-processes",
    "process.install_instance_definitions",
    s.nodeId,
    {
      instance_id: number(s.input, "instance_id"),
    },
  );
  await child(
    s,
    "clone-schedules",
    "schedule.install_instance_definitions",
    s.nodeId,
    {
      instance_id: number(s.input, "instance_id"),
    },
  );
  await route(s, number(s.input, "instance_id"), s.input.route_id);
  return await finish(s);
}

async function transfer(s: Session): Promise<Receipt> {
  if (s.input.initial_completed === true) return await finish(s);
  if (s.input.initial_cutover !== true) {
    let stopped = false;
    try {
      await child(
        s,
        "transfer-stop-source",
        "process.instance_runtime",
        s.nodeId,
        {
          instance_id: number(s.input, "instance_id"),
          action: "transfer_pause",
        },
      );
      stopped = true;
      await run(s, "transfer-capture");
      await run(s, "transfer-sqlite-intent");
      await child(
        s,
        "transfer-destination",
        "instance.transfer_destination",
        number(s.input, "destination_node_id"),
        {
          instance_id: number(s.input, "instance_id"),
          transfer_id: s.input.transfer_id,
        },
      );
      await run(s, "transfer-cutover");
    } catch (caught) {
      const error =
        caught instanceof ExecutorError && caught.code === "operation.cancelled"
          ? new Refused(caught.code)
          : caught;
      // Uncertain transport outcomes escape. A confirmed refusal may roll back only before the atomic cutover.
      if (
        stopped && error instanceof Refused &&
        error.result.rollback_pending !== true &&
        (mayCleanupRefusal(error) || error.code === "operation.cancelled")
      ) {
        await child(
          s,
          "transfer-rollback",
          "instance.transfer_rollback",
          s.nodeId,
          {
            instance_id: number(s.input, "instance_id"),
            transfer_id: s.input.transfer_id,
          },
        );
      }
      throw error;
    }
  }
  await child(
    s,
    "transfer-activate-destination",
    "instance.transfer_activate",
    number(s.input, "destination_node_id"),
    {
      instance_id: number(s.input, "instance_id"),
      transfer_id: s.input.transfer_id,
    },
  );
  await run(s, "transfer-destination-activated");
  await child(s, "transfer-retire-source", "route.retire_source", s.nodeId, {
    transfer_id: s.input.transfer_id,
  });
  await child(
    s,
    "transfer-retire-runtime",
    "process.instance_runtime",
    s.nodeId,
    {
      instance_id: number(s.input, "instance_id"),
      action: "transfer_retire_source",
    },
  );
  await run(s, "transfer-cleanup-source");
  await run(s, "transfer-finish");
  return await finish(s);
}

async function transferRollback(s: Session): Promise<Receipt> {
  const recorded = await run(s, "rollback-record");
  await child(
    s,
    "transfer-restore-source",
    "process.instance_runtime",
    s.nodeId,
    {
      instance_id: number(s.input, "instance_id"),
      action: "transfer_restore",
    },
  );
  if (z.boolean().parse(recorded.seed_abandon_required)) {
    await child(
      s,
      "transfer-abandon-sqlite",
      "database.sqlite_seed_abandon",
      number(recorded, "seed_abandon_node_id"),
      {},
    );
  }
  await child(
    s,
    "transfer-discard-destination",
    "instance.transfer_discard",
    number(recorded, "destination_node_id"),
    {},
  );
  await run(s, "rollback-state");
  return await run(s, "compensation-proof");
}

/** Swamp selects the sequence; Gateway descriptors enforce each effect's admitted inputs and prerequisites. */
export async function lifecycle(s: Session): Promise<Completion> {
  try {
    const execute = {
      "instance.create": create,
      "instance.rename": rename,
      "instance.clone": clone,
      "instance.register": register,
      "instance.destroy": destroy,
      "instance.transfer": transfer,
      "instance.transfer_rollback": transferRollback,
    }[s.kind];
    if (!execute) throw new Error("Wrong instance lifecycle kind");
    return { status: "succeeded", result: await execute(s), error_code: null };
  } catch (error) {
    if (
      error instanceof ExecutorError && error.code === "operation.cancelled"
    ) {
      return { status: "cancelled", result: {}, error_code: error.code };
    }
    if (!(error instanceof Refused)) throw error;
    return {
      status: error.code === "operation.cancelled" ? "cancelled" : "failed",
      result: error.result,
      error_code: error.code,
    };
  }
}
