/** Project-specific task admission extends the Gateway behavior; the factory stays official. */
import { z } from "npm:zod@4";
import { cleanEnv } from "../../_lib/maintenance/environment.ts";
import { enqueue, type Http, ReceiptSchema, repository } from "./_lib/queue.ts";
import { PlanSchema } from "./_lib/tasks.ts";
import { project } from "./config.ts";

interface Context {
  logger?: {
    info: (message: string, values?: Record<string, unknown>) => void;
  };
  globalArgs: { gatewayUrl: string; caFile: string; timeoutSeconds: number };
  definition: { name: string };
  queryData: (predicate: string, select: string) => Promise<unknown[]>;
  writeResource: (
    spec: string,
    name: string,
    data: Record<string, unknown>,
  ) => Promise<{ name: string }>;
  signal?: AbortSignal;
}
/** Same Node-authenticated Gateway transport; no worker or server credentials. */
function http(ctx: Context): Http {
  return async (method, path, body) => {
    const child = new Deno.Command("curl", {
      args: [
        "-sS",
        "--max-time",
        String(ctx.globalArgs.timeoutSeconds),
        "--cacert",
        ctx.globalArgs.caFile,
        "-X",
        method,
        "-H",
        "Accept: application/json",
        "-w",
        "\n%{http_code}",
        ...(body
          ? ["-H", "Content-Type: application/json", "--data-binary", "@-"]
          : []),
        ctx.globalArgs.gatewayUrl + path,
      ],
      stdin: body ? "piped" : "null",
      stdout: "piped",
      stderr: "piped",
      signal: ctx.signal,
      clearEnv: true,
      env: cleanEnv((key) => Deno.env.get(key)),
    }).spawn();
    if (body) {
      const writer = child.stdin.getWriter();
      await writer.write(new TextEncoder().encode(JSON.stringify(body)));
      await writer.close();
    }
    const output = await child.output(),
      text = new TextDecoder().decode(output.stdout),
      cut = text.lastIndexOf("\n");
    if (!output.success || cut < 0) {
      throw new Error(
        "Horizon Gateway request failed; reconcile before retrying",
      );
    }
    return {
      status: Number(text.slice(cut + 1)),
      body: JSON.parse(text.slice(0, cut)),
    };
  };
}
/** Verify placement using existing receipts only. No enrollment, rotation or startup. */
export async function verifyWorker(
  input: { group: number; worker: string },
  definition: string,
  expectedRepo: string,
  read: Context["queryData"],
  gateway: Http,
): Promise<void> {
  const rows = await read(
    `modelName == ${JSON.stringify(definition)} && name == ${
      JSON.stringify("worker-" + input.group)
    }`,
    "attributes",
  );
  const session = rows[0] as {
    tokenName?: string;
    ready?: boolean;
    revoked?: boolean;
  } | undefined;
  if (
    session?.tokenName !== input.worker || !session.ready ||
    session.revoked
  ) {
    throw new Error(
      "Horizon detection requires the group's current enrolled worker",
    );
  }
  const group = await gateway(
    "GET",
    `/api/v1/task-groups/${input.group}`,
  );
  const data = group.body?.data as {
    project_id?: number;
    orchestration?: string;
    status?: string;
  } | undefined;
  if (
    group.status !== 200 || !data || data.orchestration !== "external" ||
    ["completed", "failed", "cancelled"].includes(data.status ?? "")
  ) throw new Error("Horizon detection requires an open external group");
  const response = await gateway(
    "GET",
    `/api/v1/projects/${data.project_id}`,
  );
  const config = response.body?.data as {
    repository_url?: string;
    review_and_merge?: boolean;
  } | undefined;
  if (
    response.status !== 200 ||
    repository(config?.repository_url) !== expectedRepo ||
    config?.review_and_merge !== false
  ) {
    throw new Error(
      "Horizon worker Project must match the package repository and wait for Nick",
    );
  }
}

const ArgsSchema = z.object({
  projectId: z.number().int().positive(),
  plan: PlanSchema,
});
export const extension = {
  type: "@nckrtl/orbit-gateway",
  resources: {
    horizonQueue: {
      description:
        "Horizon backlog task-group creation receipt; never starts a run",
      schema: ReceiptSchema,
      lifetime: "infinite",
      garbageCollection: 10,
    },
  },
  methods: [{
    horizon_worker: {
      description:
        "Verify the existing Horizon group's enrolled worker; never provisions or rotates it",
      arguments: z.object({
        group: z.number().int().positive(),
        worker: z.string().min(1),
      }),
      execute: async (
        input: { group: number; worker: string },
        ctx: Context,
      ) => {
        ctx.logger?.info(
          "Verifying Horizon group {group} worker {worker}",
          input,
        );
        await verifyWorker(
          input,
          ctx.definition.name,
          project.repository,
          ctx.queryData,
          http(ctx),
        );
        return { dataHandles: [] };
      },
    },
    horizon_tasks: {
      description:
        "Create one backlog parity task per Horizon candidate; wait for Nick",
      arguments: ArgsSchema,
      execute: async (input: z.infer<typeof ArgsSchema>, ctx: Context) => {
        const args = ArgsSchema.parse(input);
        ctx.logger?.info(
          "Queuing {count} Horizon parity tasks for Project {project}",
          { count: args.plan.tasks.length, project: args.projectId },
        );
        let handle: { name: string } | null = null;
        await enqueue(args.plan, args.projectId, project.repository, {
          http: http(ctx),
          read: async (key) => {
            const rows = await ctx.queryData(
              `modelName == ${JSON.stringify(ctx.definition.name)} && name == ${
                JSON.stringify("horizon-" + key)
              }`,
              "attributes",
            );
            return rows.length ? ReceiptSchema.parse(rows[0]) : null;
          },
          save: async (receipt) => {
            handle = await ctx.writeResource(
              "horizonQueue",
              "horizon-" + receipt.key,
              receipt,
            );
          },
        });
        ctx.logger?.info("Horizon backlog task admission finished");
        return { dataHandles: handle ? [handle] : [] };
      },
    },
  }],
};
