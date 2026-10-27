import { ensureProjectGroup, findingReceipts, scheduleReceipt, ScheduleReceiptSchema, type ScheduleReceipt } from "./_lib/project_schedule.ts";
import { runMaintenance, reconcileWorkers, workerControl, type MaintenanceContext } from "./_lib/swamp_maintenance.ts";
import { revokeEndedWorker } from "./_lib/maintenance_worker.ts";
import { projectConfigurations, ProjectExtensionsSchema } from "./_lib/project_config.ts";
import { assertProjectSettings, DependencyProjectsSchema } from "./_lib/dependency_projects.ts";
import { admitPackage, PackageTargetSchema, type PackageTarget } from "./_lib/package_target.ts";
// @nckrtl/orbit-gateway — the stage work of the orbit-groups factory.
// `stage` ensures one Gateway task operation per driver key and never waits:
// see _lib/gateway_stage.ts. The Gateway authenticates this Node by its
// WireGuard address, so the transport only needs the Orbit root CA.
import { z } from "npm:zod@4";
import { type AssistanceRecord, type Http, type Job, runStage, STAGES, syncAssistance } from "./_lib/gateway_stage.ts";
import { type StageArgs } from "./_lib/gateway_stage.ts";

const GlobalArgsSchema = z.object({
  dependencyProjects: DependencyProjectsSchema.default([]),
  projectExtensions: ProjectExtensionsSchema.default([]),
  enrollmentVault: z.string().min(1).default("default"),
  workerUrl: z.string().default("wss://swamp.orbit"),
  serveUrl: z.string().default("wss://swamp.orbit"),
  serveTokenFile: z.string().default("/home/orbit/.config/swamp/serve-driver-token"),
  gatewayUrl: z.string().default("https://gateway.orbit"),
  caFile: z.string().default("/usr/local/share/ca-certificates/orbit-managed-root-ca.crt"),
  timeoutSeconds: z.number().int().default(60),
});
type GlobalArgs = z.infer<typeof GlobalArgsSchema>;

const JobSchema = z.object({
  key: z.string(),
  workItem: z.string(),
  stage: z.enum(STAGES),
  group: z.number(),
  subtask: z.number().nullable(),
  state: z.enum(["running", "succeeded", "failed"]),
  launches: z.number(),
  launchedNow: z.boolean(),
  request: z.object({ path: z.string(), body: z.record(z.string(), z.unknown()), idempotencyKey: z.string() }).nullable(),
  operationId: z.number().nullable(),
  operationStatus: z.string().nullable(),
  lastError: z.string().nullable(),
  inputIdentity: z.string().nullable(),
  nextReadAt: z.string().nullable(),
  artifacts: z.record(z.string(), z.record(z.string(), z.unknown())),
  evidence: z.record(z.string(), z.unknown()).nullable(),
  analysisContext: z.unknown().optional(),
  invalidated: z.boolean().optional(),
});

/** HTTP through curl, which trusts the Orbit root CA without Deno's unstable client flags. */
export function curlHttp(g: Pick<GlobalArgs, "gatewayUrl" | "caFile" | "timeoutSeconds">): Http {
  return async (method, path, body, idempotencyKey) => {
    const args = [
      "-sS", "--max-time", String(g.timeoutSeconds), "--cacert", g.caFile, "-X", method,
      "-H", "Accept: application/json", "-w", "\n%{http_code}",
    ];
    if (idempotencyKey) args.push("-H", `Idempotency-Key: ${idempotencyKey}`);
    if (body !== undefined) args.push("-H", "Content-Type: application/json", "--data-binary", "@-");
    args.push(`${g.gatewayUrl}${path}`);
    const child = new Deno.Command("curl", { args, stdin: body !== undefined ? "piped" : "null", stdout: "piped", stderr: "piped" }).spawn();
    if (body !== undefined) {
      const w = child.stdin.getWriter();
      await w.write(new TextEncoder().encode(JSON.stringify(body)));
      await w.close();
    }
    const out = await child.output();
    const text = new TextDecoder().decode(out.stdout);
    const cut = text.lastIndexOf("\n");
    const status = Number(text.slice(cut + 1)) || 0;
    if (!out.success || status === 0) {
      return { status: 0, body: { error: { code: "transport", message: new TextDecoder().decode(out.stderr).trim() } } };
    }
    let parsed: Record<string, unknown> | null = null;
    try {
      parsed = JSON.parse(text.slice(0, cut)) as Record<string, unknown>;
    } catch { /* empty or non-JSON body */ }
    return { status, body: parsed };
  };
}

export const behavior = {
  type: "@nckrtl/orbit-gateway",
  version: "2026.10.27.2",
  globalArguments: GlobalArgsSchema,
  resources: {
    schedule: {
      description: "Project schedule and package finding group-creation receipts",
      schema: ScheduleReceiptSchema, lifetime: "infinite", garbageCollection: 10,
    },
    projectWork: {
      description: "Scheduled work items for the existing factory drivers",
      schema: z.object({ routine: z.array(z.string()), detect: z.array(z.string()), package: z.array(z.string()), nextOffset: z.number().int().nonnegative(), nextQueueOffset: z.number().int().nonnegative() }),
      lifetime: "infinite", garbageCollection: 10,
    },
    worker: {
      description: "Public per-group Swamp enrollment and Gateway worker operation receipt",
      schema: z.object({ group: z.number().int().positive(), generation: z.number().int().positive(), tokenName: z.string(), operationId: z.number().int().positive().nullable(), nextReadAt: z.string().nullable().optional(), startedAt: z.string().optional(), ready: z.boolean(), revoked: z.boolean() }),
      lifetime: "infinite", garbageCollection: 10,
    },
    maintenance: {
      description: "Swamp workflow start intent and adopted run; no credentials",
      schema: z.object({ key: z.string(), inputs: z.record(z.string(), z.unknown()), runId: z.string().nullable(), createdAt: z.string() }),
      lifetime: "infinite", garbageCollection: 10,
    },
    packageTarget: { description: "Immutable single-package selection per admitted group", schema: PackageTargetSchema, lifetime: "infinite", garbageCollection: 10 },
    job: {
      description: "One Gateway task operation per driver key, with its stage products",
      schema: JobSchema,
      lifetime: "infinite",
      garbageCollection: 10,
    },
    assistance: {
      description: "The child or exact post-child group request owned by this work item",
      schema: z.object({
        workItem: z.string(),
        subtask: z.number().nullable(),
        key: z.string().nullable(),
        raisedAt: z.string().nullable(),
        skipped: z.string().nullable().optional(),
        groupRequest: z.object({ body: z.string(), stage: z.string(), cycle: z.number().int(), gateId: z.string(), era: z.string() }).nullable().optional(),
      }),
      lifetime: "infinite",
      garbageCollection: 10,
    },
  },
  methods: {
    schedule_project: {
      description: "Start or reconcile one project's scheduled routine or detection group",
      arguments: z.object({ projectId: z.number().int().positive(), mode: z.enum(["routine", "detect"]) }),
      execute: async (args: { projectId: number; mode: "routine" | "detect" }, context: StageContext) => {
        const configs = await projectConfigurations(context.globalArgs.dependencyProjects, context.globalArgs.projectExtensions, context, args.projectId);
        const config = configs[0];
        if (!config?.worker) throw new Error("scheduled maintenance requires a configured project worker");
        await assertProjectSettings(config, curlHttp(context.globalArgs));
        const store = scheduleStore(context);
        const desired = scheduleReceipt(config, args.mode);
        // Only one scheduled routine/detect workspace per project at a time.
        const other = (await scheduleRows(context)).find((r) => r.projectId === args.projectId && r.mode !== "package" && r.key !== desired.key && !r.completed);
        if (other) throw new Error(`scheduled project work ${other.key} must finish before a new run`);
        let receipt = await ensureProjectGroup(desired, store);
        if (!receipt.started) {
          await startFactory(context, args.mode === "detect" ? "dependency-detect" : "dependency-routine", receipt.workItem!);
          receipt = { ...receipt, started: true };
          await store.save(receipt);
        }
        return { dataHandles: [] };
      },
    },
    project_work: {
      description: "Read official factory state, fan detection into package tasks, and list scheduled driver work",
      arguments: z.object({}),
      execute: async (_args: Record<string, never>, context: StageContext) => projectWork(context),
    },
    detect: {
      description: "Read-only detection through the existing worker, check and no-change completion path",
      arguments: z.object({ workItem: z.string(), stage: z.enum(["provision", "script", "handoff", "finish"]), key: z.string(),
        script: z.unknown().optional(), handoff: z.unknown().optional() }),
      execute: (args: StageArgs, context: StageContext) => executeStage({ ...args, routine: true, detection: true }, context),
    },
    reconcile_workers: {
      description: "Revoke enrollment for ended groups, including external cancellation",
      arguments: z.object({}),
      execute: async (_args: Record<string, never>, context: StageContext) => {
        await reconcileWorkers(context, curlHttp(context.globalArgs));
        return { dataHandles: [] };
      },
    },
    admit_package: {
      description: "Admit one unused configured project group after its earlier packages merged and completed; never creates a group",
      arguments: PackageTargetSchema.strip(), // Swamp merges global args into method validation.
      execute: async (target: PackageTarget, context: StageContext & { runModel: (args: { definition: string; method: string; arguments: Record<string, unknown> }) => Promise<{ ok: boolean; error?: { message: string } }> }) => {
        let handle: { name: string } | null = null;
        // The official overview loads by stable factory ID, including records
        // written before the factory rename. Name-filtering old state loses it.
        const overview = await context.runModel({ definition: "dependency-package", method: "status", arguments: {} });
        if (!overview.ok) throw new Error(overview.error?.message ?? "cannot read package factory");
        const runs = (await context.queryData('modelName == "dependency-package" && name == "status-_factory"', "attributes.runs"))[0] as { workItem: string; stageId: string; status: string }[];
        await admitPackage(target, {
          http: curlHttp(context.globalArgs),
          projects: await groupConfigurations(target.workItem, context),
          targets: await context.queryData(`modelName == "${context.definition.name}" && name.startsWith("package-")`, "attributes") as PackageTarget[],
          runState: (workItem) => Promise.resolve(runs.find((run) => run.workItem === workItem) ?? null),
          save: async (value) => { handle = await context.writeResource("packageTarget", `package-${target.workItem}`, value); },
          start: async (workItem) => {
            const r = await context.runModel({ definition: "dependency-package", method: "start", arguments: { workItem } });
            if (!r.ok) throw new Error(r.error?.message ?? "cannot start package factory");
          },
        });
        const receipt = (await scheduleRows(context)).find((r) => r.mode === "package" && r.workItem === target.workItem);
        if (receipt) {
          const selection = { path: target.path, manager: target.manager, package: target.package, version: target.version, purpose: target.purpose, advisoryUrls: target.advisoryUrls, advisories: target.advisories };
          await scheduleStore(context).save({ ...receipt, target: selection, started: true });
        }
        return { dataHandles: handle ? [handle] : [] };
      },
    },
    package: {
      description: "One selected package through the existing routine stage client",
      arguments: z.object({ workItem: z.string(), stage: z.enum(["provision", "script", "repair", "handoff", "publish", "merged", "finish"]),
        key: z.string(), handoff: z.unknown().optional(), change: z.unknown().optional(), script: z.unknown().optional(), workspace: z.unknown().optional() }),
      execute: async (args: StageArgs, context: StageContext) => {
        const rows = await context.queryData(`modelName == "${context.definition.name}" && name == "package-${args.workItem}"`, "attributes");
        const target = PackageTargetSchema.parse(rows[0]);
        return executeStage({ ...args, routine: true, packageTarget: target }, context);
      },
    },
    assistance: {
      description: "Reconcile parked assistance; post-child group requests clear only after their exact factory approval",
      arguments: z.object({
        workItem: z.string().describe("tg-<task group id>"),
        parked: z.boolean(),
        stage: z.string(),
        cycle: z.number().int(),
        reason: z.string(),
        factory: z.string().optional(),
        gateIds: z.array(z.string()).optional(),
      }),
      execute: async (
        args: { workItem: string; parked: boolean; stage: string; cycle: number; reason: string; factory?: string; gateIds?: string[] },
        context: {
          globalArgs: { gatewayUrl: string; caFile: string; timeoutSeconds: number };
          definition: { name: string };
          queryData: (predicate: string, select?: string) => Promise<unknown[]>;
          writeResource: (spec: string, name: string, data: Record<string, unknown>) => Promise<{ name: string }>;
        },
      ) => {
        const read = async <T>(name: string): Promise<T | null> =>
          ((await context.queryData(`modelName == "${context.definition.name}" && name == "${name}"`, "attributes")) as T[])[0] ?? null;
        let handle: { name: string } | null = null;
        await syncAssistance(args, {
          http: curlHttp(context.globalArgs),
          readRecord: () => read<AssistanceRecord>(`assistance-${args.workItem}`),
          writeRecord: async (r) => {
            handle = await context.writeResource("assistance", `assistance-${args.workItem}`, r as unknown as Record<string, unknown>);
          },
          readJob: (key) => read<Job>(`job-${key}`),
          readRun: async () => args.factory
            ? ((await context.queryData(`modelName == "${args.factory}" && name == "state-${args.workItem}"`, "attributes")) as { workItem: string; startedAt: string }[])[0] ?? null
            : null,
          readApproval: async (gateId) => args.factory
            ? ((await context.queryData(`modelName == "${args.factory}" && name == "approval-${args.workItem}-${gateId}"`, "attributes")) as unknown[])[0] ?? null
            : null,
        });
        return { dataHandles: handle ? [handle] : [] };
      },
    },
    routine: {
      description: "Routine project update: the tracked script, one repair turn, checks, publication and cleanup through existing keyed jobs",
      arguments: z.object({ workItem: z.string(), stage: z.enum(["provision", "script", "repair", "handoff", "publish", "merged", "finish"]),
        key: z.string(), handoff: z.unknown().optional(), change: z.unknown().optional(), script: z.unknown().optional(), workspace: z.unknown().optional() }),
      execute: (args: StageArgs, context: StageContext) => executeStage({ ...args, routine: true }, context),
    },
    stage: {
      description: "Ensure the Gateway operation of one stage entry: start it once, then report its result",
      arguments: z.object({
        workItem: z.string().describe("tg-<task group id>"),
        stage: z.enum(STAGES),
        key: z.string().describe("<workItem>.<stage>.c<cycle>"),
        change: z.unknown().optional(),
        handoff: z.unknown().optional(),
        review: z.unknown().optional(),
        plan: z.unknown().optional(),
        analysis: z.unknown().optional(),
        workspace: z.unknown().optional(),
        baseline: z.unknown().optional(),
      }),
      execute: executeStage,
    },
  },
};

interface StageContext extends MaintenanceContext {
  runModel: MaintenanceContext["runModel"] & ((args: { definition: string; method: string; arguments?: Record<string, unknown> }) => Promise<{ ok: boolean; error?: { message: string } }>);
  globalArgs: GlobalArgs;
  definition: { name: string };
  queryData: (predicate: string, select?: string) => Promise<unknown[]>;
  writeResource: (spec: string, name: string, data: Record<string, unknown>) => Promise<{ name: string }>;
  logger: { info: (msg: string, props?: Record<string, unknown>) => void };
}

async function executeStage(args: StageArgs, context: StageContext) {
  let handle: { name: string } | null = null;
  const http = curlHttp(context.globalArgs);
  const job = await runStage(args, {
    maintenance: (input, group, request) => runMaintenance(input, group, input.detection && input.stage === "script" ? { ...request, mode: "detect" } : request, context, http),
    revokeWorker: (group) => revokeEndedWorker(group, workerControl(context, http)),
    http,
    projects: args.routine ? await groupConfigurations(args.workItem, context) : [],
    readJob: async () => {
      const rows = (await context.queryData(
        `modelName == "${context.definition.name}" && name == "job-${args.key}"`, "attributes",
      )) as Job[];
      return rows[0] ?? null;
    },
    readPriorPublish: async () => (await context.queryData(
      `modelName == "${context.definition.name}" && name.startsWith("job-${args.workItem}.publish.c")`, "attributes",
    )) as Job[],
    writeJob: async (j) => {
      handle = await context.writeResource("job", `job-${args.key}`, j as unknown as Record<string, unknown>);
    },
  });
  context.logger.info("{key}: {state} op={op} {status} {err}", {
    key: args.key, state: job.state, op: job.operationId ?? "-",
    status: job.operationStatus ?? "", err: job.lastError ?? "",
  });
  return { dataHandles: handle ? [handle] : [] };
}

async function groupConfigurations(workItem: string, context: StageContext) {
  if (!/^tg-[1-9]\d*$/.test(workItem)) throw new Error("invalid dependency work item");
  const response = await curlHttp(context.globalArgs)("GET", `/api/v1/task-groups/${workItem.slice(3)}`);
  const group = response.body?.data as { project_id?: number } | undefined;
  if (response.status !== 200 || !group?.project_id) throw new Error("cannot read dependency group");
  return projectConfigurations(context.globalArgs.dependencyProjects, context.globalArgs.projectExtensions, context, group.project_id);
}

function scheduleStore(context: StageContext) {
  return {
    http: curlHttp(context.globalArgs),
    read: async (key: string) => (await context.queryData(`modelName == ${JSON.stringify(context.definition.name)} && name == ${JSON.stringify(`schedule-${key}`)}`, "attributes"))[0] as ScheduleReceipt | undefined ?? null,
    save: async (receipt: ScheduleReceipt) => { await context.writeResource("schedule", `schedule-${receipt.key}`, receipt); },
  };
}
async function scheduleRows(context: StageContext) {
  return (await context.queryData(`modelName == ${JSON.stringify(context.definition.name)} && name.startsWith("schedule-")`, "attributes")).map((r) => ScheduleReceiptSchema.parse(r));
}
async function callDefinition(context: StageContext, definition: string, method: string, args: Record<string, unknown> = {}) {
  const result = await context.runModel({ definition, method, arguments: args });
  if (!result.ok) throw new Error(result.error?.message ?? `${definition}.${method} failed`);
}

async function startFactory(context: StageContext, factory: string, workItem: string) {
  const state = await context.queryData(`modelName == ${JSON.stringify(factory)} && name == ${JSON.stringify(`state-${workItem}`)}`, "attributes");
  if (!state.length) await callDefinition(context, factory, "start", { workItem });
}

async function projectWork(context: StageContext) {
  const store = scheduleStore(context);
  const work: Record<"routine" | "detect" | "package", string[]> = { routine: [], detect: [], package: [] };
  const rows = await scheduleRows(context);
  for (const pending of rows.filter((r) => !r.started && r.mode !== "package").slice(0, 4)) {
    const receipt = await ensureProjectGroup(pending, store);
    await startFactory(context, receipt.mode === "detect" ? "dependency-detect" : "dependency-routine", receipt.workItem!);
    await store.save({ ...receipt, started: true });
  }
  // Rotate bounded batches so a parked project cannot starve the rest of the fleet.
  const active = rows.filter((r) => r.started && !r.completed);
  const previous = (await context.queryData(`modelName == ${JSON.stringify(context.definition.name)} && name == "project-work"`, "attributes"))[0] as { nextOffset?: number; nextQueueOffset?: number } | undefined;
  const offset = active.length ? (previous?.nextOffset ?? 0) % active.length : 0;
  const batch = [...active.slice(offset), ...active.slice(0, offset)].slice(0, 4);
  for (const receipt of batch) {
    const factory = receipt.mode === "detect" ? "dependency-detect" : receipt.mode === "package" ? "dependency-package" : "dependency-routine";
    await callDefinition(context, factory, "status", { workItem: receipt.workItem });
    const state = (await context.queryData(`modelName == ${JSON.stringify(factory)} && name == ${JSON.stringify(`state-${receipt.workItem}`)}`, "attributes"))[0] as { stageId: string; status: string } | undefined;
    if (!state) throw new Error("scheduled factory state is missing");
    if (state.status !== "terminal") { work[receipt.mode].push(receipt.workItem!); continue; }
    if (state.stageId !== "completed") {
      const response = await store.http("GET", `/api/v1/task-groups/${receipt.workItem!.slice(3)}`);
      const group = response.body?.data as Record<string, unknown> | undefined;
      if (response.status === 200 && group?.status === "cancelled" && group.vm_power === "destroyed" &&
        !group.assistance_requested && !group.assistance_kind && !group.assistance_reason && !group.assistance_question &&
        Array.isArray(group.tasks) && group.tasks.every((task: Record<string, unknown>) =>
          ["completed", "cancelled", "failed"].includes(String(task.status)) && !task.assistance_requested && !task.assistance_kind && !task.assistance_reason && !task.assistance_question)) {
        await store.save({ ...receipt, completed: true });
      }
      continue;
    }
    if (receipt.mode === "detect") {
      const config = (await groupConfigurations(receipt.workItem!, context))[0];
      const artifact = (await context.queryData(`modelName == "dependency-detect" && name == ${JSON.stringify(`artifact-${receipt.workItem}-script`)}`, "attributes"))[0] as { payload?: { detections?: unknown[] } } | undefined;
      if (!Array.isArray(artifact?.payload?.detections)) throw new Error("completed detection has no report evidence");
      const findings = await findingReceipts(config, artifact.payload.detections);
      for (const finding of findings) {
        const existing = await store.read(finding.key);
        await ensureProjectGroup(existing ?? finding, store);
      }
    }
    await store.save({ ...receipt, completed: true });
  }
  // Existing admission owns package ordering. Tasks without an exact target remain backlog.
  const seenProjects = new Set<number>();
  const queuedRows = (await scheduleRows(context)).filter((r) => {
    if (r.mode !== "package" || !r.target || r.started || r.completed || seenProjects.has(r.projectId)) return false;
    seenProjects.add(r.projectId);
    return true;
  });
  const queueOffset = queuedRows.length ? (previous?.nextQueueOffset ?? 0) % queuedRows.length : 0;
  const queueBatch = [...queuedRows.slice(queueOffset), ...queuedRows.slice(0, queueOffset)].slice(0, 4);
  for (const queued of queueBatch) {
    await callDefinition(context, "dependency-package", "status");
    const runs = (await context.queryData('modelName == "dependency-package" && name == "status-_factory"', "attributes.runs"))[0] as { workItem: string; stageId: string; status: string }[];
    const target = PackageTargetSchema.parse({ ...queued.target, workItem: queued.workItem });
    try {
      await admitPackage(target, {
        http: store.http,
        projects: await groupConfigurations(target.workItem, context),
        targets: await context.queryData(`modelName == ${JSON.stringify(context.definition.name)} && name.startsWith("package-")`, "attributes") as PackageTarget[],
        runState: (item) => Promise.resolve(runs.find((r) => r.workItem === item) ?? null),
        save: async (value) => { await context.writeResource("packageTarget", `package-${target.workItem}`, value); },
        start: async (item) => {
          // A lost start reply is reconciled from the official factory state.
          const existing = runs.find((r) => r.workItem === item);
          if (!existing) await callDefinition(context, "dependency-package", "start", { workItem: item });
        },
      });
      await store.save({ ...queued, started: true });
      work.package.push(target.workItem);
    } catch (error) {
      if (!(error instanceof Error) || !error.message.startsWith("previous package ")) throw error;
    }
  }
  const handle = await context.writeResource("projectWork", "project-work", { ...work, nextOffset: active.length ? (offset + batch.length) % active.length : 0, nextQueueOffset: queuedRows.length ? (queueOffset + queueBatch.length) % queuedRows.length : 0 });
  return { dataHandles: [handle] };
}
