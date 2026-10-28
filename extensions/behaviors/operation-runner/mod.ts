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
  node_id: z.number().int().positive(),
  input: z.record(z.string(), z.unknown()),
  status: z.string(),
  cancel_requested: z.boolean(),
  steps: z.array(z.object({ name: z.string(), status: z.string() })).default(
    [],
  ),
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
  async post(path: string, body: unknown): Promise<unknown> {
    const response = await this.http(
      "POST",
      `/api/v1/operation-executor/${path}`,
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
      claim: {
        description: "Claim receipts for one runner workflow",
        schema: z.object({
          claims: z.array(
            z.object({
              workflow: z.string(),
              operation_id: z.number().int().positive(),
              worker: z.string(),
            }),
          ),
        }),
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      claim: {
        description:
          "Claim one Operation per registered kind with a stable workflow identity",
        arguments: z.object({ worker: z.string().min(1) }),
        execute: async (args: { worker: string }, ctx: Context) => {
          const executor = new Executor(makeHttp(ctx));
          const claims = [];
          for (const registration of await discover()) {
            for (const kind of registration.kinds) {
              const value = await executor.post("claim", {
                kind,
                worker: `${args.worker}:${kind}`,
              });
              if (value !== null) {
                claims.push({
                  workflow: registration.workflow,
                  operation_id: Operation.parse(value).id,
                  worker: `${args.worker}:${kind}`,
                });
              }
            }
          }
          const handle = await ctx.writeResource(
            "claim",
            `claims-${args.worker}`,
            { claims },
          );
          return { dataHandles: [handle], claims };
        },
      },
    },
  };
}
export const behavior = {
  ...operationRunner(),
  type: "@nckrtl/operation-runner",
};
