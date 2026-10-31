import { z } from "npm:zod@4.4.3";
import { type Context } from "../../operation-runner/mod.ts";
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
export const MaintenanceReceipt = Admission;
export async function maintenance(
  args: { tick: string },
  ctx: Context,
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
          "fleet.window_closed",
          "fleet.recipe_not_ready",
          "fleet.rollout_not_ready",
          "node.recipe_docker_provider",
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
      if (
        !Terminal.has(status) ||
        (!retry && status === "succeeded" && intent.generation === generation)
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
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).format(at);
  const [hour, minute] = local.split(":").map(Number);
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
        `${target.configuration_digest}:${
          Math.floor(at.getTime() / 3_600_000)
        }`,
      );
    }
  });
  await independently(async () => {
    if (minute % 5 !== 0) return;
    const prPlan = z.object({
      status: z.string(),
      writer: z.string().default("polled"),
      recipe_revision: z.string().regex(/^[a-f0-9]{64}$/).optional(),
      serving_recipe_revision: z.string().regex(/^[a-f0-9]{64}$/).optional(),
      base_revision: z.string().regex(/^[a-f0-9]{40}$/).optional(),
      date: z.string().optional(),
      pulls: z.array(
        z.object({
          number: z.number(),
          variant: z.string(),
          head_sha: z.string(),
          checks: z.enum([
            "passed",
            "failed",
            "pending",
            "missing",
            "unreadable",
          ]).optional(),
          checks_revision: z.string().regex(/^[a-f0-9]{64}$/).optional(),
          policy_revision: z.string().regex(/^[a-f0-9]{64}$/).optional(),
        }),
      ).default([]),
    }).parse(await request("GET", "/api/v1/fleet/recipe-pr/plan"));
    if (
      prPlan.status === "ready" && prPlan.writer === "direct" &&
      prPlan.recipe_revision && prPlan.serving_recipe_revision &&
      prPlan.base_revision
    ) {
      if (hour >= 3 && minute % 5 === 0) {
        for (const variant of ["snapshot", "artifacts"]) {
          await request(
            "POST",
            "/api/v1/fleet/recipe-pr",
            {
              action: "open",
              variant,
              pull_number: 0,
              head_sha: "",
              date: prPlan.date,
            },
            `fleet:recipe-pr:open:${prPlan.date}:${variant}:${prPlan.serving_recipe_revision}`,
          );
        }
      }
      // The native behavior lock serializes GitHub writes; each action rechecks its admitted head.
      // The merge command checks retirement first, including on this cadence’s merge tick.
      const action =
        ["retire", "update", "merge"][Math.floor(at.getTime() / 60_000) % 3];
      for (const pull of prPlan.pulls) {
        if (!pull.checks || !pull.checks_revision || !pull.policy_revision) {
          throw new Error("fleet.recipe_pr_plan_incomplete");
        }
        if (
          action === "update" &&
          (pull.variant !== "snapshot" || pull.checks !== "failed")
        ) continue;
        if (
          action === "merge" && pull.variant === "snapshot" &&
          pull.checks !== "passed"
        ) continue;
        const evidence = action === "retire"
          ? [
            prPlan.recipe_revision,
            prPlan.pulls.map((candidate) =>
              `${candidate.number}:${candidate.head_sha}:${candidate.policy_revision}`
            ).sort(),
          ]
          : action === "update"
          ? [prPlan.base_revision, pull.checks_revision]
          : pull.variant === "artifacts"
          ? [prPlan.recipe_revision, pull.checks_revision]
          : [pull.checks_revision];
        const digest = Array.from(
          new Uint8Array(
            await crypto.subtle.digest(
              "SHA-256",
              new TextEncoder().encode(
                JSON.stringify([pull.head_sha, pull.policy_revision, evidence]),
              ),
            ),
          ),
          (byte) => byte.toString(16).padStart(2, "0"),
        ).join("");
        await request(
          "POST",
          "/api/v1/fleet/recipe-pr",
          {
            action,
            variant: pull.variant,
            pull_number: pull.number,
            head_sha: pull.head_sha,
            date: prPlan.date,
          },
          `fleet:recipe-pr:${prPlan.date}:${action}:${pull.number}:${digest}`,
        );
      }
    }
  });
  await independently(async () => {
    const recipe = z.object({
      status: z.string(),
      build_id: z.number().optional(),
      node_id: z.number().optional(),
      recipe: z.string().optional(),
    }).parse(await request("GET", "/api/v1/fleet/recipe/plan"));
    if (recipe.status === "ready" && recipe.node_id) {
      await admit(
        `recipe-${recipe.build_id}-${recipe.node_id}`,
        `/api/v1/fleet/nodes/${recipe.node_id}/recipe`,
        { automatic: true, build_id: recipe.build_id, recipe: recipe.recipe },
        false,
      );
    } else if (["complete", "interrupted"].includes(recipe.status)) {
      await request("POST", "/api/v1/fleet/recipe/state", {
        state: recipe.status,
        build_id: recipe.build_id,
      });
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
          window: z.boolean(),
          running: z.boolean(),
        }),
      ).default([]),
    }).parse(await request("GET", "/api/v1/fleet/rollout/plan"));
    // Pending Nodes precede catch-up retries; a busy or unreachable Node does not starve later ones.
    const target = rollout.targets.filter((t) =>
      t.window && !t.running && (t.attempts === 0 || !t.finished_at ||
        new Date(t.finished_at).getTime() <= at.getTime() - 300_000)
    ).sort((a, b) =>
      a.attempts - b.attempts
    )[0];
    if (target) {
      await admit(
        `rollout-${rollout.rollout}-${target.node_id}`,
        `/api/v1/fleet/nodes/${target.node_id}/rollout`,
        { rollout_id: rollout.rollout },
        true,
      );
    }
  });
  return { status: errors.length ? "partial" : "recorded", errors };
}
