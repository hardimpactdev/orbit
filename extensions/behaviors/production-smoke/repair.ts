import { callWorkerModel } from "../_lib/worker_model.ts";
import { queryWorkerData } from "../_lib/worker_query.ts";
import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../automatic-deploy/gateway.ts";
import { projectConfigurations } from "../_lib/project_config.ts";
import { Job, Observation, Receipt, same, State } from "./contract.ts";
import { execution } from "./failure.ts";
import { type Context, withinBudget } from "./mod.ts";

export const Repair = z.object({
  request_key: z.string(),
  instance_id: z.number().int().positive(),
  project_id: z.number().int().positive(),
  failure_key: z.string(),
  group_id: z.number().int().positive(),
  task_id: z.number().int().positive(),
}).strict();
const Lookup = z.object({
  request_key: z.string(),
  found: z.boolean(),
  repair: Repair.nullable(),
}).strict();
export const RepairState = z.object({
  status: z.enum([
    "not_failed",
    "planned",
    "pending",
    "started",
    "completed",
    "assistance_required",
  ]),
  reason: z.string().nullable(),
  intent: z.object({
    key: z.string(),
    instance: z.number().int().positive(),
    project: z.number().int().positive(),
    config: z.string(),
    deployment_id: z.number().int().positive(),
    smoke_key: z.string(),
    request: z.object({ failure_key: z.string() }).strict(),
  }).nullable(),
  repair: Repair.nullable(),
  factory_attempted: z.boolean(),
});
const Factory = z.object({ workItem: z.string(), status: z.string() });
const Group = z.object({
  id: z.number().int().positive(),
  project_id: z.number().int().positive(),
  orchestration: z.literal("external"),
  status: z.string(),
  tasks: z.array(
    z.object({
      id: z.number().int().positive(),
      parent_id: z.number().int().positive(),
    }),
  ),
});
export function repairMethod(
  makeHttp: ((ctx: Context) => Http) | undefined,
  effects: boolean,
  budget: number,
) {
  return {
    description:
      "Create or reconcile one verified-failure repair through the normal task factory",
    arguments: z.object({
      apply: z.boolean().default(false),
      deploymentId: z.number().int().positive().optional(),
    }),
    execute: (
      input: { apply?: boolean; deploymentId?: number },
      context: Context,
    ) =>
      withinBudget(context, budget, async (ctx) => {
        let state = RepairState.parse({
          status: "not_failed",
          reason: null,
          intent: null,
          repair: null,
          factory_attempted: false,
        });
        const save = async (
          status: z.infer<typeof RepairState>["status"],
          reason: string | null = null,
        ) => {
          ctx.signal?.throwIfAborted();
          state.status = status;
          state.reason = reason;
          if (state.intent) {
            await ctx.writeResource(
              "repair",
              `repair-${state.intent.deployment_id}`,
              state,
            );
          }
          return {
            dataHandles: [await ctx.writeResource("repair", "repair", state)],
          };
        };
        try {
          const [project] = await projectConfigurations(
            [],
            [{
              projectId: ctx.globalArgs.projectId,
              type: ctx.globalArgs.projectType,
            }],
            ctx,
            ctx.globalArgs.projectId,
          );
          const config = project.productionSmoke;
          if (!config) {
            return await save(
              "not_failed",
              "Project is not opted in; saved incidents remain.",
            );
          }
          const fingerprint = JSON.stringify({
            projectId: project.projectId,
            repository: project.repository,
            ...config,
          });
          const pending = (await queryWorkerData(
            ctx,
            `modelName == "production-smoke-${project.projectId}" && name.startsWith("repair-") && attributes.status != "started" && attributes.status != "completed"`,
            "attributes",
          ))
            .map((row) => RepairState.safeParse(row)).filter((row) =>
              row.success
            ).map((row) => row.data).sort((a, b) =>
              (a.intent?.deployment_id ?? 0) - (b.intent?.deployment_id ?? 0)
            );
          const current = await ctx.readResource("state");
          const deployment = input.deploymentId ??
            pending[0]?.intent?.deployment_id ?? (current
              ? State.parse(current).job?.request.deployment_id
              : undefined);
          if (!deployment) {
            return await save("not_failed", "No failed smoke intent.");
          }
          const saved = await ctx.readResource(`repair-${deployment}`);
          if (saved) {
            state = RepairState.parse(saved);
          }
          const storedJob = await ctx.readResource(`job-${deployment}`);
          const job = storedJob ? Job.parse(storedJob) : null;
          if (
            (state.intent && state.intent.config !== fingerprint) ||
            (job && job.config !== fingerprint)
          ) {
            throw new Error("Configuration changed; original repair retained.");
          }
          const http = makeHttp?.(ctx) ??
            gatewayHttp(
              ctx.globalArgs.gatewayUrl,
              ctx.globalArgs.caFile,
              ctx.signal,
            );
          const call = async <T>(
            method: "GET" | "POST",
            path: string,
            schema: z.ZodType<T>,
            body?: unknown,
            key?: string,
          ) => {
            ctx.signal?.throwIfAborted();
            const response = await http(method, path, body, key);
            if (![200, 201].includes(response.status)) {
              throw new Error(
                `Repair Gateway HTTP ${response.status}: ${
                  JSON.stringify(response.body)
                }`,
              );
            }
            return schema.parse(
              z.object({ data: z.unknown() }).parse(response.body).data,
            );
          };
          const root = `/api/v1/instances/${config.instance}/smoke`;
          const observed = await call("GET", root + "/context", Observation);
          if (
            observed.instance_id !== config.instance ||
            observed.project_id !== project.projectId ||
            observed.repository_url !== project.repository ||
            !observed.production || !observed.base_urls.includes(config.baseUrl)
          ) throw new Error("Repair ownership changed.");
          const verify = async () => {
            if (!job) {
              return false;
            }
            const receipt = await call(
              "GET",
              root + `/receipt?request_key=${encodeURIComponent(job.key)}`,
              Receipt,
            );
            return receipt.operation?.target_id === config.instance &&
              execution(receipt, job.request, job.key, true) &&
              (job.operation_id === null ||
                receipt.operation.id === job.operation_id);
          };
          if (!state.intent) {
            if (!await verify()) {
              return await save(
                "not_failed",
                "No verified executed failure; no repair.",
              );
            }
            const failure_key =
              `smoke-failure.${config.instance}.${deployment}`;
            state.intent = {
              key: failure_key + ".repair",
              instance: config.instance,
              project: project.projectId,
              config: fingerprint,
              deployment_id: deployment,
              smoke_key: job!.key,
              request: { failure_key },
            };
          }
          const intent = state.intent;
          if (
            intent.instance !== config.instance ||
            intent.project !== project.projectId ||
            intent.deployment_id !== deployment ||
            intent.key !==
              `smoke-failure.${config.instance}.${deployment}.repair` ||
            intent.request.failure_key + ".repair" !== intent.key
          ) throw new Error("Repair intent identity changed.");
          if (!effects || !input.apply) {
            return await save("planned", "Live repair effects are disabled.");
          }
          await save("pending", "Repair admission intent saved.");
          const lookup = await call(
            "GET",
            root +
              `/repair/receipt?request_key=${encodeURIComponent(intent.key)}`,
            Lookup,
          );
          if (
            lookup.request_key !== intent.key ||
            lookup.found !== (lookup.repair !== null)
          ) throw new Error("Repair lookup identity changed.");
          if (!lookup.repair && !await verify()) {
            throw new Error(
              "Original executed failure is unavailable; retain its key.",
            );
          }
          const repair = lookup.repair ??
            await call(
              "POST",
              root + "/repair",
              Repair,
              intent.request,
              intent.key,
            );
          if (
            repair.request_key !== intent.key ||
            repair.failure_key !== intent.request.failure_key ||
            repair.instance_id !== intent.instance ||
            repair.project_id !== intent.project ||
            (state.repair && !same(state.repair, repair))
          ) throw new Error("Repair receipt identity changed.");
          state.repair = repair;
          await save(
            "pending",
            "Repair group recorded before factory handoff.",
          );
          const group = await call(
            "GET",
            `/api/v1/task-groups/${repair.group_id}`,
            Group,
          );
          if (
            group.id !== repair.group_id ||
            group.project_id !== intent.project || !group.tasks.some((task) =>
              task.id === repair.task_id && task.parent_id === group.id
            )
          ) {
            throw new Error("Repair group or child lineage changed.");
          }
          if (
            ["completed", "cancelled", "failed"].includes(group.status)
          ) {
            return await save(
              "completed",
              "Existing repair ended; no replacement or restart.",
            );
          }
          const workItem = `tg-${repair.group_id}`;
          const readFactory = async () => {
            const rows = await queryWorkerData(
              ctx,
              `modelName == "orbit-groups" && name == "state-${workItem}"`,
              "attributes",
            );
            if (rows.length > 1) {
              throw new Error(
                "Repair factory state is ambiguous.",
              );
            }
            const found = rows.length ? Factory.parse(rows[0]) : null;
            if (found && found.workItem !== workItem) {
              throw new Error(
                "Repair factory work item changed.",
              );
            }
            return found;
          };
          if (!await readFactory()) {
            state.factory_attempted = true;
            await save("pending", "Normal task factory handoff intent saved.");
            ctx.signal?.throwIfAborted();
            const result = await callWorkerModel(ctx, {
              modelType: "@swamp/software-factory",
              name: "orbit-groups",
              method: "start",
              arguments: { workItem },
            });
            if (!result.ok) {
              throw new Error(
                "Task factory start is unconfirmed; read its state before retrying.",
              );
            }
          }
          if (!await readFactory()) {
            throw new Error(
              "Task factory start lacks durable state.",
            );
          }
          return await save("started");
        } catch (error) {
          return await save(
            "assistance_required",
            error instanceof Error
              ? error.message
              : "Unreadable repair evidence.",
          );
        }
      }),
  };
}
