import { z } from "npm:zod@4.4.3";
import { type Failure, StepListResult } from "./contract.ts";
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

async function steps(
  s: Session,
  phase: "setup" | "teardown",
  instanceId: number,
  digest: unknown,
  key: string = phase,
): Promise<Failure | null> {
  const result = StepListResult.parse(
    await s.child({
      key,
      kind: "instance.run_steps",
      node_id: s.nodeId,
      input: {
        instance_id: instanceId,
        list: phase,
        source: "project",
        configuration_digest: z.string().regex(/^[0-9a-f]{64}$/).parse(digest),
      },
    }),
  );
  if (result.outcome === "succeeded") return null;
  return {
    outcome: result.outcome,
    error_code: result.error_code ?? `instance.${phase}_step_failed`,
  };
}
function list(input: Receipt, field: string): Receipt[] {
  return z.array(z.record(z.string(), z.unknown())).parse(input[field]);
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
    await child(s, `withdraw-extra-${routeId}`, "route.withdraw", s.nodeId, {
      route_id: routeId,
    });
  }
  await run(s, "removal-accept");
  for (const member of list(s.input, "members")) {
    const id = number(member, "instance_id");
    await run(s, `removal-${id}-source-prepare`);
    if (member.route_id !== null) {
      await child(s, `withdraw-${id}`, "route.withdraw", s.nodeId, {
        route_id: member.route_id,
      });
    }
    await child(
      s,
      `withdraw-instance-${id}`,
      "route.withdraw_instance",
      s.nodeId,
      { instance_id: id },
    );
    await run(s, `removal-${id}-route-clear`);
    await run(s, `removal-${id}-source-finalize`);
    await run(s, `removal-${id}-runtime-intent`);
    await child(
      s,
      `remove-processes-${id}`,
      "process.instance_runtime",
      s.nodeId,
      { instance_id: id, action: "remove" },
    );
    await child(
      s,
      `remove-schedules-${id}`,
      "schedule.instance_runtime",
      s.nodeId,
      { instance_id: id, action: "remove" },
    );
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
    await run(s, `removal-${id}-row-delete`);
  }
  return await finish(s);
}

export async function lifecycle(s: Session): Promise<Completion> {
  try {
    const execute = {
      "instance.rename": rename,
      "instance.destroy": destroy,
    }[s.kind];
    if (!execute) throw new Error("Wrong instance lifecycle kind");
    return { status: "succeeded", result: await execute(s), error_code: null };
  } catch (error) {
    if (!(error instanceof Refused)) throw error;
    return {
      status: error.code === "operation.cancelled" ? "cancelled" : "failed",
      result: error.result,
      error_code: error.code,
    };
  }
}
