import { z } from "npm:zod@4.4.3";
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

export async function lifecycle(s: Session): Promise<Completion> {
  try {
    const execute = {
      "instance.rename": rename,
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
