import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../automatic-deploy/gateway.ts";

import { Args, type Context, Globals } from "./contract.ts";

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
  const admit = (routine: string) => ({
    description: "Admit one native maintenance or Horizon inspection Operation",
    arguments: Args,
    execute: async (_args: z.infer<typeof Args>, ctx: Context) => {
      const result = z.object({ id: z.number().int().positive() }).or(
        z.object({ status: z.literal("disabled") }),
      ).parse(
        await new ObservationApi(makeHttp(ctx)).post(`maintenance/${routine}`),
      );
      return "id" in result ? { operation_id: result.id } : result;
    },
  });
  return {
    type: "@nckrtl/observation",
    version: "2026.10.31.2",
    globalArguments: Globals,
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
      health: admit("health"),
      maintain: admit("maintenance"),
      retain: admit("retention"),
    },
  };
}
export const behavior = { ...observation(), type: "@nckrtl/observation" };
