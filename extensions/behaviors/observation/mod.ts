import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../automatic-deploy/gateway.ts";
import { type Context, Globals } from "../operation-runner/mod.ts";
import { collect, CollectionDisabled, Plan, Progress } from "./collection.ts";

import { Args, ReadReceipt, Receipt } from "./contract.ts";

export class ObservationError extends Error {
  constructor(readonly code: string) {
    super(code);
  }
}
export class ObservationApi {
  constructor(private http: Http) {}
  async post(path: string, body: unknown = {}): Promise<unknown> {
    const response = await this.http(
      "POST",
      `/api/v1/observation/${path}`,
      body,
    );
    const result = z.object({ data: z.unknown() }).safeParse(response.body);
    if (response.status < 200 || response.status >= 300 || !result.success) {
      const error = z.object({
        error: z.object({ code: z.string().regex(/^[a-z0-9_.-]+$/) }),
      }).safeParse(response.body);
      throw new ObservationError(
        error.success ? error.data.error.code : "observation.unavailable",
      );
    }
    return result.data.data;
  }
  async read(path: string): Promise<void> {
    const receipt = ReadReceipt.parse(await this.post(path));
    if (receipt.status === "disabled") throw new CollectionDisabled();
  }
}

export function observation(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      60_000,
    ),
) {
  const routine = (names: string[]) => ({
    description: "Maintain bounded Gateway state through Services-only APIs",
    arguments: Args,
    execute: async (args: z.infer<typeof Args>, ctx: Context) => {
      const api = new ObservationApi(makeHttp(ctx));
      const handles = [];
      let failed = false;
      for (const name of names) {
        const key = `${name}-${args.runId}`;
        if (await ctx.readResource(key)) continue;
        try {
          const result = Receipt.parse(
            await api.post(`maintenance/${name}`, { run_id: args.runId }),
          );
          handles.push(await ctx.writeResource("routine", key, result));
        } catch {
          failed = true;
        }
      }
      if (failed) {
        throw new ObservationError("observation.maintenance_incomplete");
      }
      return { dataHandles: handles };
    },
  });
  return {
    type: "@nckrtl/observation",
    version: "2026.10.28.1",
    globalArguments: Globals,
    resources: {
      progress: {
        description: "Progress of the current bounded Doctor collection",
        schema: Progress,
        lifetime: "7d",
        garbageCollection: 10,
      },
      routine: {
        description:
          "Counts and status from one Gateway state maintenance receipt",
        schema: Receipt,
        lifetime: "7d",
        garbageCollection: 10,
      },
    },
    methods: {
      collect: {
        description:
          "Observe Nodes, inspect typed resource groups, then collect signals",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const api = new ObservationApi(makeHttp(ctx));
          const handles: { name: string }[] = [];
          const result = await collect(args.runId, {
            plan: async (id) =>
              Plan.parse(await api.post(`collections/${id}/plan`)),
            observe: (id, node) => api.read(`collections/${id}/nodes/${node}`),
            inspect: (id, probe) =>
              api.read(`collections/${id}/probes/${probe}`),
            signals: (id) => api.read(`collections/${id}/signals`),
          }, {
            read: async () => {
              const saved = await ctx.readResource("collection");
              return saved ? Progress.parse(saved) : null;
            },
            write: async (value) => {
              handles.splice(
                0,
                handles.length,
                await ctx.writeResource("progress", "collection", value),
              );
            },
          });
          return {
            dataHandles: handles,
            complete: result.complete,
            collection: result.plan.collection,
          };
        },
      },
      health: routine(["health"]),
      maintain: routine(["activities", "operations", "metrics"]),
      retain: routine(["batches", "failed"]),
    },
  };
}
export const behavior = { ...observation(), type: "@nckrtl/observation" };
