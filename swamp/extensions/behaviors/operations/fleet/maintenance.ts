import { z } from "npm:zod@4.4.3";
import type { Globals } from "../_lib/direct.ts";

export type MaintenanceContext = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  readResource(name: string): Promise<Record<string, unknown> | null>;
  writeResource(
    spec: string,
    name: string,
    value: Record<string, unknown>,
  ): Promise<{ name: string }>;
};
import type { Http } from "../../automatic-deploy/gateway.ts";

const Terminal = new Set(["succeeded", "failed", "cancelled"]);
const Envelope = z.object({ data: z.unknown() });
const Started = z.object({ id: z.number().int().positive() });
const Status = z.object({ status: z.string() });
const Admission = z.object({
  key: z.string(),
  path: z.string(),
  body: z.record(z.string(), z.unknown()),
  id: z.number().int().positive().nullable(),
  attempt: z.number().int().nonnegative(),
  generation: z.string().optional(),
});
export const MaintenanceIntent = Admission;
export async function maintenance(
  args: { tick: string },
  ctx: MaintenanceContext,
  http: Http,
  at = new Date(),
) {
  const request = async (
    method: "GET" | "POST",
    path: string,
    body?: unknown,
    key?: string,
  ) => {
    const response = await http(method, path, body, key);
    if (response.status < 200 || response.status >= 300) {
      const code = z.object({ error: z.object({ code: z.string() }) })
        .safeParse(response.body);
      if (
        code.success &&
        [
          "operation.target_busy",
          "fleet.rollout_busy",
          "fleet.rollout_not_ready",
        ].includes(code.data.error.code)
      ) return null;
      throw new Error(
        code.success ? code.data.error.code : "fleet.admission_unknown",
      );
    }
    return Envelope.parse(response.body).data;
  };
  // A saved intent is posted with its original key after a lost admission reply.
  const admit = async (
    name: string,
    path: string,
    body: Record<string, unknown>,
    retry: boolean,
    generation?: string,
  ) => {
    const saved = await ctx.readResource(name);
    let intent = saved ? Admission.parse(saved) : null;
    if (intent?.id) {
      const observed = await request("GET", `/api/v1/operations/${intent.id}`);
      if (observed === null) return;
      const status = Status.parse(observed).status;
      // A generation runs once: a failed one waits for an operator rerun after a fix, or a new generation.
      if (
        !Terminal.has(status) ||
        (!retry && intent.generation === generation &&
          (status === "succeeded" || generation !== undefined))
      ) return;
      intent = null;
    }
    if (!intent) {
      const attempt = saved ? Admission.parse(saved).attempt + 1 : 0;
      intent = {
        path,
        body,
        key: `fleet:${name}:${args.tick}:${attempt}`,
        id: null,
        attempt,
        ...(generation === undefined ? {} : { generation }),
      };
      await ctx.writeResource("maintenance", name, intent);
    }
    const result = await request("POST", intent.path, intent.body, intent.key);
    if (result === null) return;
    await ctx.writeResource("maintenance", name, {
      ...intent,
      id: Started.parse(result).id,
    });
  };
  const errors: string[] = [];
  const independently = async (action: () => Promise<void>) => {
    try {
      await action();
    } catch (error) {
      const code = error instanceof Error ? error.message : "";
      errors.push(
        /^[a-z0-9_.-]+$/.test(code) ? code : "fleet.maintenance_unknown",
      );
    }
  };
  const local = new Intl.DateTimeFormat("en-GB", {
    timeZone: "Europe/Amsterdam",
    minute: "2-digit",
  }).format(at);
  const minute = Number(local);
  if (minute % 5 === 0) {
    await independently(async () => {
      await request("GET", "/api/v1/gateway/desired-fleet-state");
    });
  }
  await independently(async () => {
    const targets = z.array(z.object({
      node_id: z.number().int().positive(),
      configuration_digest: z.string().regex(/^[a-f0-9]{64}$/),
    })).parse(await request("GET", "/api/v1/fleet/peer-dns/plan"));
    for (const target of targets) {
      await admit(
        `dns-${target.node_id}`,
        `/api/v1/fleet/nodes/${target.node_id}/peer-dns`,
        {},
        false,
        // One admission per policy: the Gateway plans a peer only while its policy differs from the last applied one.
        target.configuration_digest,
      );
    }
  });
  await independently(async () => {
    const rollout = z.object({
      status: z.string(),
      rollout: z.number().optional(),
      targets: z.array(
        z.object({
          node_id: z.number(),
          attempts: z.number(),
          finished_at: z.string().nullable().optional(),
        }),
      ).default([]),
    }).parse(await request("GET", "/api/v1/fleet/rollout/plan"));
    if (
      !["running", "completed"].includes(rollout.status) || !rollout.rollout
    ) return;
    const targets = rollout.targets.filter((target) =>
      target.attempts === 0 || !target.finished_at ||
      new Date(target.finished_at).getTime() <= at.getTime() - 300_000
    );
    if (targets.length) {
      await admit(
        `rollout-${rollout.rollout}`,
        "/api/v1/fleet/rollout",
        {
          rollout_id: rollout.rollout,
          node_ids: targets.map((target) => target.node_id),
        },
        true,
      );
    }
  });
  return { status: errors.length ? "partial" : "recorded", errors };
}
