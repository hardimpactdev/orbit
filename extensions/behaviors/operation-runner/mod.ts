import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../automatic-deploy/gateway.ts";

export const Globals = z.object({
  gatewayUrl: z.string().url().default("https://gateway.orbit"),
  caFile: z.string().default(
    "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt",
  ),
});
export const Operation = z.object({
  id: z.number().int().positive(),
  kind: z.string(),
  node_id: z.number().int().positive().nullable(),
  input: z.preprocess(
    (value) => Array.isArray(value) && value.length === 0 ? {} : value,
    z.record(z.string(), z.unknown()),
  ),
  parent_operation_id: z.number().int().positive().nullable().optional(),
  child_key: z.string().nullable().optional(),
  waiting_for: z.array(
    z.object({ key: z.string(), id: z.number().int().positive() }),
  ).default([]),
  status: z.string(),
  result: z.preprocess(
    (value) => Array.isArray(value) && value.length === 0 ? {} : value,
    z.record(z.string(), z.unknown()).nullable(),
  ).optional(),
  cancel_requested: z.boolean(),
  steps: z.array(z.object({ name: z.string(), status: z.string() })).default(
    [],
  ),
  execution_context: z.object({
    descriptors: z.array(z.object({
      key: z.string(),
      step: z.string(),
      transport: z.string(),
      name: z.string(),
      input: z.preprocess(
        (value) => Array.isArray(value) && value.length === 0 ? {} : value,
        z.record(z.string(), z.unknown()),
      ),
      resumable: z.boolean(),
      required: z.boolean(),
    })),
    result: z.preprocess(
      (value) => Array.isArray(value) && value.length === 0 ? {} : value,
      z.record(z.string(), z.unknown()),
    ).optional(),
  }).default({ descriptors: [] }),
  step_results: z.preprocess(
    (v) => Array.isArray(v) && v.length === 0 ? {} : v,
    z.record(z.string(), z.unknown()),
  ),
});
export const ChildReceipt = z.object({
  id: z.number().int().positive(),
  key: z.string(),
  kind: z.string(),
  node_id: z.number().int().positive().nullable(),
  status: z.enum([
    "queued",
    "running",
    "waiting",
    "succeeded",
    "failed",
    "cancelled",
  ]),
  error_code: z.string().nullable(),
  result: z.preprocess(
    (value) => Array.isArray(value) && value.length === 0 ? {} : value,
    z.record(z.string(), z.unknown()).nullable(),
  ),
});
export const EffectReceipt = z.object({
  effect_key: z.string(),
  state: z.enum(["succeeded", "failed", "running", "unknown"]),
  exit_code: z.number().int().nullable(),
  result: z.preprocess(
    (value) => Array.isArray(value) && value.length === 0 ? {} : value,
    z.record(z.string(), z.unknown()).nullable(),
  ),
  finished_at: z.string().nullable(),
});
export type ClaimedOperation = z.infer<typeof Operation>;
export type ChildOperationReceipt = z.infer<typeof ChildReceipt>;

export type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  readResource(name: string): Promise<Record<string, unknown> | null>;
  writeResource(
    spec: string,
    name: string,
    value: Record<string, unknown>,
  ): Promise<{ name: string }>;
};
export class ExecutorError extends Error {
  constructor(readonly code: string) {
    super(code);
  }
}
export class Executor {
  constructor(private http: Http) {}
  async operation(id: number): Promise<unknown> {
    return await this.request("GET", `${id}`, undefined, "/api/v1/operations/");
  }
  async get(path: string): Promise<unknown> {
    return await this.request("GET", path);
  }
  private async request(
    method: "GET" | "POST",
    path: string,
    body?: unknown,
    prefix = "/api/v1/operation-executor/",
  ): Promise<unknown> {
    const response = await this.http(
      method,
      `${prefix}${path}`,
      body,
    );
    const envelope = z.object({ data: z.unknown() }).safeParse(response.body);
    if (response.status < 200 || response.status >= 300 || !envelope.success) {
      const error = z.object({ error: z.object({ code: z.string() }) })
        .safeParse(response.body);
      throw new ExecutorError(
        error.success ? error.data.error.code : "operation.outcome_unknown",
      );
    }
    return envelope.data.data;
  }
  post(path: string, body: unknown): Promise<unknown> {
    return this.request("POST", path, body);
  }
  async claim(
    kind: string,
    worker: string,
    operationId?: number,
  ): Promise<ClaimedOperation | null> {
    try {
      const value = await this.post("claim", {
        kind,
        worker,
        ...(operationId === undefined ? {} : { operation_id: operationId }),
      });
      return value === null ? null : Operation.parse(value);
    } catch (error) {
      if (
        error instanceof ExecutorError &&
        [
          "operation.executor_invalid",
          "operation.kind_unavailable",
          "operation.child_unproven",
        ].includes(error.code)
      ) {
        return null;
      }
      throw error;
    }
  }
  async child(
    parentId: number,
    worker: string,
    key: string,
  ): Promise<ClaimedOperation> {
    return Operation.parse(
      await this.post(`${parentId}/children`, { worker, key }),
    );
  }
  async children(parentId: number): Promise<ChildOperationReceipt[]> {
    return z.array(ChildReceipt).parse(
      await this.request("GET", `${parentId}/children`),
    );
  }
  async receipt(
    operationId: number,
    key: string,
  ): Promise<z.infer<typeof EffectReceipt>> {
    return EffectReceipt.parse(
      await this.request(
        "GET",
        `${operationId}/receipts/${encodeURIComponent(key)}`,
      ),
    );
  }
  async wait(
    parentId: number,
    worker: string,
    keys: string[],
  ): Promise<ClaimedOperation> {
    const operation = Operation.parse(
      await this.post(`${parentId}/wait`, { worker, keys }),
    );
    if (operation.id !== parentId || operation.status !== "waiting") {
      throw new ExecutorError("operation.outcome_unknown");
    }
    return operation;
  }
}
/** Family-local discovery; no filesystem, claim, nested workflow or polling. */
export function operationDiscovery(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      30_000,
    ),
) {
  const Kind = z.string().regex(/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/);
  return {
    type: "@nckrtl/operation-discovery",
    version: "2026.10.30.1",
    globalArguments: Globals,
    resources: {
      dispatch: {
        description:
          "Latest claimable kinds for one independently scheduled family",
        schema: z.object({
          work: z.array(z.object({ kind: Kind, worker: z.string() })),
        }),
        lifetime: "7d",
        garbageCollection: 1,
      },
    },
    methods: {
      discover: {
        description:
          "Read only this family's claimable kinds; execution stays in its workflow",
        arguments: z.object({
          family: z.string().min(1),
          kinds: z.array(Kind).nonempty(),
          worker: z.string().min(1),
        }),
        execute: async (
          args: { family: string; kinds: string[]; worker: string },
          ctx: Context,
        ) => {
          if (new Set(args.kinds).size !== args.kinds.length) {
            throw new Error("Duplicate family kind");
          }
          const available = z.object({
            kinds: z.array(
              z.object({
                kind: Kind,
                claimable: z.number().int().nonnegative(),
                oldest_at: z.iso.datetime({ offset: true }).nullable(),
              }),
            ),
          }).parse(await new Executor(makeHttp(ctx)).get("claimable"));
          if (
            new Set(available.kinds.map((entry) => entry.kind)).size !==
              available.kinds.length
          ) throw new Error("Duplicate claimable kind");
          const claimable = new Set(
            available.kinds.filter((entry) => entry.claimable > 0).map((
              entry,
            ) => entry.kind),
          );
          const work = args.kinds.filter((kind) => claimable.has(kind)).map((
            kind,
          ) => ({ kind, worker: `${args.worker}:${kind}` }));
          const handle = await ctx.writeResource("dispatch", args.family, {
            work,
          });
          return { dataHandles: [handle], work };
        },
      },
    },
  };
}
export const behavior = {
  ...operationDiscovery(),
  type: "@nckrtl/operation-discovery",
};
