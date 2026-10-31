import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../automatic-deploy/gateway.ts";

import { Args, type Context, Globals, Receipt } from "./contract.ts";

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
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
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
        description: "Admit one native Doctor and problem collection run",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const api = new ObservationApi(makeHttp(ctx));
          const result = z.object({ id: z.number().int().positive() }).or(
            z.object({ status: z.literal("disabled") }),
          ).parse(await api.post(`collections/${args.runId}/plan`));
          return "id" in result ? { operation_id: result.id } : result;
        },
      },
      health: routine(["health"]),
      maintain: routine(["activities", "metrics"]),
      retain: routine(["batches", "failed"]),
    },
  };
}
export const behavior = { ...observation(), type: "@nckrtl/observation" };
