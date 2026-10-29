import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../automatic-deploy/gateway.ts";

export const Globals = z.object({
  gatewayUrl: z.string().url().default("https://gateway.orbit"),
  caFile: z.string().default(
    "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt",
  ),
});
export const Registration = z.object({
  kinds: z.array(z.string().regex(/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/))
    .nonempty(),
  workflow: z.string().min(1),
});
export const Operation = z.object({
  id: z.number().int().positive(),
  kind: z.string(),
  node_id: z.number().int().positive().nullable(),
  input: z.record(z.string(), z.unknown()),
  status: z.string(),
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
      input: z.record(z.string(), z.unknown()),
      resumable: z.boolean(),
      required: z.boolean(),
    })),
    result: z.record(z.string(), z.unknown()).optional(),
  }).default({ descriptors: [] }),
  step_results: z.preprocess(
    (v) => Array.isArray(v) && v.length === 0 ? {} : v,
    z.record(z.string(), z.unknown()),
  ),
});
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
  async post(path: string, body: unknown): Promise<unknown> {
    return await this.request("POST", path, body);
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
}
export async function registrations(
  directory = "extensions/behaviors/operations",
) {
  const result: z.infer<typeof Registration>[] = [];
  const names: string[] = [];
  for await (const entry of Deno.readDir(directory)) {
    if (entry.isDirectory) names.push(entry.name);
  }
  for (const name of names.sort()) {
    result.push(
      Registration.parse(
        JSON.parse(
          await Deno.readTextFile(`${directory}/${name}/registration.json`),
        ),
      ),
    );
  }
  const kinds = result.flatMap((entry) => entry.kinds);
  if (new Set(kinds).size !== kinds.length) {
    throw new Error("Duplicate operation kind registration");
  }
  return result;
}
export function operationRunner(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      600_000,
    ),
  discover = registrations,
) {
  return {
    type: "@nckrtl/operation-runner",
    version: "2026.10.28.1",
    globalArguments: Globals,
    resources: {
      dispatch: {
        description:
          "Read-only discovery of registered kinds with claimable work",
        schema: z.object({
          work: z.array(z.object({
            workflow: z.string(),
            kind: z.string(),
            worker: z.string(),
          })),
        }),
        lifetime: "7d",
        garbageCollection: 1,
      },
    },
    methods: {
      discover: {
        description: "Read claimable kind counts without claiming an Operation",
        arguments: z.object({ worker: z.string().min(1) }),
        execute: async (args: { worker: string }, ctx: Context) => {
          const registered = await discover();
          const executor = new Executor(makeHttp(ctx));
          const available = z.object({
            kinds: z.array(z.object({
              kind: z.string().regex(/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/),
              claimable: z.number().int().nonnegative(),
              oldest_at: z.iso.datetime({ offset: true }).nullable(),
            })),
          }).parse(await executor.get("claimable"));
          if (
            new Set(available.kinds.map((entry) => entry.kind)).size !==
              available.kinds.length
          ) {
            throw new Error("Duplicate claimable kind");
          }
          const kinds = new Set(
            available.kinds.filter((entry) => entry.claimable > 0).map((
              entry,
            ) => entry.kind),
          );
          const work = registered.flatMap((registration) =>
            registration.kinds
              .filter((kind) => kinds.has(kind))
              .map((kind) => ({
                workflow: registration.workflow,
                kind,
                worker: `${args.worker}:${kind}`,
              }))
          );
          const handle = await ctx.writeResource("dispatch", "dispatch", {
            work,
          });
          return { dataHandles: [handle], work };
        },
      },
    },
  };
}
export const behavior = {
  ...operationRunner(),
  type: "@nckrtl/operation-runner",
};
