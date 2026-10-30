import { batchReviewWait } from "./operations/dependency-merge/review.ts";
import { MergeConfiguration, mergeConfigurationMethod } from "./operations/dependency-merge/configuration.ts";
import { Admission as DependencyMergeAdmission, admitBatchMerge } from "./operations/dependency-merge/admission.ts";
import { pruneDependencyResults, type RetentionContext } from "./_lib/dependency_retention.ts";
import { parkForReview } from "./_lib/review_capacity.ts";
import { AdmissionSchema, reconcileAdmissions, reserveMaintenance, orderProjects, type Admission, type AdmissionIntent } from "./_lib/project_admission.ts";
import { project as horizonProject } from "./projects/horizon-new-dawn/config.ts";
import { cleanedCancelledGroup, ensureProjectGroup, batchReceipt, FindingsSchema, isolateScheduleRow, scheduleReceipt, ScheduleReceiptSchema, SchedulerIssueSchema, SchedulerSkipSchema, type ScheduleReceipt } from "./_lib/project_schedule.ts";
import { runMaintenance, reconcileWorkers, workerControl, type MaintenanceContext } from "./_lib/swamp_maintenance.ts";
import { ensureWorker, revokeEndedWorker } from "./_lib/maintenance_worker.ts";
import { projectConfigurations, ProjectExtensionsSchema } from "./_lib/project_config.ts";
import { assertProjectSettings, DependencyProjectSchema, DependencyProjectsSchema } from "./_lib/dependency_projects.ts";
import { admitPackage, assertPreviousPackages, PackageTargetSchema, type PackageTarget } from "./_lib/package_target.ts";
import { indexName, reportName, IndexSchema, ReportSchema } from "./_lib/dependency_results_schema.ts";
import { assertCurrentPlan, reconcileDependencyIndex, BatchEvidenceSchema } from "./_lib/service_detection.ts";
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
  version: "2026.10.30.1",
  globalArguments: GlobalArgsSchema,
  resources: {
    dependencyMergeConfiguration: {description:"Fresh private dependency merge switch",schema:MergeConfiguration,lifetime:"infinite",garbageCollection:1},
    dependencyMergeAdmission: {description:"Disabled-by-default exact-head merge admission",schema:DependencyMergeAdmission,lifetime:"infinite",garbageCollection:10},
    dependencyRefreshInput:{description:"Bounded credential-free PR refresh configuration",schema:z.object({runId:z.string(),rows:z.array(z.object({project:DependencyProjectSchema,index:IndexSchema})),http:z.object({gatewayUrl:z.string(),caFile:z.string(),timeoutSeconds:z.number()})}),lifetime:"infinite",garbageCollection:2},
    dependencyScanInput: {description:"Scan configuration handoff",schema:z.object({runId:z.string(),project:DependencyProjectsSchema.element,http:z.object({gatewayUrl:z.string(),caFile:z.string(),timeoutSeconds:z.number()})}),lifetime:"infinite",garbageCollection:1},
    // Index history is seven days; reports are pruned only by retained-index reachability.
    dependencyIndex: { description: "orbit.dependencies.v1 Project/ref publication", schema: IndexSchema, lifetime: "infinite", garbageCollection: Number.MAX_SAFE_INTEGER },
    dependencyReport: { description: "Immutable orbit.dependencies.v1 source report", schema: ReportSchema, lifetime: "infinite", garbageCollection: Number.MAX_SAFE_INTEGER },
    schedule: {
      description: "Project schedule and admitted batch group-creation receipts",
      schema: ScheduleReceiptSchema, lifetime: "infinite", garbageCollection: 1,
    },
    findings: {description:"Project scan findings, eligible targets and blocked reasons",schema:FindingsSchema,lifetime:"infinite",garbageCollection: 1},
    admission: {description:"Durable shared maintenance VM reservations",schema:AdmissionSchema,lifetime:"infinite",garbageCollection: 1},
    schedulerIssue: {
      description: "A stuck schedule row and its latest reconciliation result",
      schema: SchedulerIssueSchema, lifetime: "infinite", garbageCollection: 1,
    },
    schedulerSkip: {
      description: "A scheduled fire skipped because this Project already has unfinished work",
      schema: SchedulerSkipSchema, lifetime: "infinite", garbageCollection: 1,
    },
    projectWork: {
      description: "Scheduled work items for the existing factory drivers",
      schema: z.object({ routine: z.array(z.string()), detect: z.array(z.string()), package: z.array(z.string()), nextOffset: z.number().int().nonnegative(), nextQueueOffset: z.number().int().nonnegative(), nextPendingOffset: z.number().int().nonnegative(), issues: z.array(SchedulerIssueSchema) }),
      lifetime: "infinite", garbageCollection: 1,
    },
    worker: {
      description: "Public per-group Swamp enrollment and Gateway worker operation receipt",
      schema: z.object({ group: z.number().int().positive(), generation: z.number().int().positive(), tokenName: z.string(), operationId: z.number().int().positive().nullable(), nextReadAt: z.string().nullable().optional(), startedAt: z.string().optional(), enrollmentAttempt: z.number().int().positive().optional(), ready: z.boolean(), revoked: z.boolean() }),
      lifetime: "infinite", garbageCollection: 1,
    },
    maintenance: {
      description: "Swamp workflow start intent and adopted run; no credentials",
      schema: z.object({ key: z.string(), inputs: z.record(z.string(), z.unknown()), runId: z.string().nullable(), createdAt: z.string() }),
      lifetime: "infinite", garbageCollection: 1,
    },
    packageTarget: { description: "Immutable whole-project batch selection per admitted group", schema: PackageTargetSchema, lifetime: "infinite", garbageCollection: 1 },
    job: {
      description: "One Gateway task operation per driver key, with its stage products",
      schema: JobSchema,
      lifetime: "infinite",
      garbageCollection: 1,
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
      garbageCollection: 1,
    },
  },
  methods: {
    dependency_merge_configuration: mergeConfigurationMethod,
    prepare_dependency_refresh: {
      outputLifetime: "7d",
      description:"Select due PR observations without holding the model lock during network access",
      arguments:z.object({runId:z.string()}),
      execute:async(args:{runId:string},context:StageContext)=>{
        const indexes=(await context.queryData('modelName == "orbit-gateway" && name.startsWith("dependencies-v1-project-")',"attributes")).map(i=>IndexSchema.parse(i))
          .filter(i=>Date.now()-Date.parse(i.batch_pr.checked_at)>=180000).sort((a,b)=>a.batch_pr.checked_at.localeCompare(b.batch_pr.checked_at)).slice(0,6);
        const rows=[];
        for(const index of indexes){const project=(await projectConfigurations(context.globalArgs.dependencyProjects,context.globalArgs.projectExtensions,context,index.project_id))[0];if(project?.worker)rows.push({project,index});}
        const {gatewayUrl,caFile}=context.globalArgs;
        return {dataHandles:[await context.writeResource("dependencyRefreshInput","dependency-refresh-input",{runId:args.runId,rows,http:{gatewayUrl,caFile,timeoutSeconds:5}})]};
      },
    },
    publish_dependency_refresh: {
      outputLifetime: "7d",
      description:"Publish credential-free evidence only if its index publication is still current",
      arguments:z.object({projectId:z.number().int().positive(),expectedPublicationId:z.string(),evidence:BatchEvidenceSchema}),
      execute:async(args:{projectId:number;expectedPublicationId:string;evidence:z.infer<typeof BatchEvidenceSchema>},context:StageContext)=>{
        const indexes=(await context.queryData(`modelName == "orbit-gateway" && name.startsWith("dependencies-v1-project-${args.projectId}-")`,"attributes")).map(i=>IndexSchema.parse(i));
        const index=indexes.find(i=>i.publication_id===args.expectedPublicationId);
        if(!index)return {dataHandles:[]};
        await reconcileDependencyIndex(index,context,args.evidence);
        const project=(await projectConfigurations(context.globalArgs.dependencyProjects,context.globalArgs.projectExtensions,context,args.projectId))[0];
        if(project)await admitBatchMerge(project,args.evidence.pr,context,curlHttp(context.globalArgs));
        return {dataHandles:[]};
      },
    },
    prune_dependency_results: {
      outputLifetime: "7d",
      description: "Prune seven-day dependency history while preserving referenced exact reports",
      arguments:z.object({}),
      execute:async(_args:Record<string,never>,context:RetentionContext)=>{await pruneDependencyResults(context);return {dataHandles:[]};},
    },
    prepare_dependency_scan: {
      outputLifetime: "7d",
      description: "Read scan configuration without holding the gateway lock during scanning",
      arguments: z.object({projectId:z.number().int().positive(),runId:z.string().min(1).max(256)}),
      execute: async (args: {projectId:number;runId:string}, context: StageContext) => {
        const project = (await projectConfigurations(context.globalArgs.dependencyProjects, context.globalArgs.projectExtensions, context, args.projectId))[0];
        if(!project?.worker) throw new Error("unconfigured_dependency_project");
        const {gatewayUrl,caFile,timeoutSeconds}=context.globalArgs;
        return {dataHandles:[await context.writeResource("dependencyScanInput",`dependency-scan-input-${args.projectId}`,{runId:args.runId,project,http:{gatewayUrl,caFile,timeoutSeconds:Math.min(timeoutSeconds,5)}})]};
      },
    },
    publish_dependency: {
      outputLifetime: "7d",
      description: "Publish one bounded dependency resource under the short gateway model lock",
      arguments: z.object({spec:z.enum(["dependencyIndex","dependencyReport","findings"]),name:z.string(),value:z.record(z.string(),z.unknown()),overrides:z.object({tags:z.record(z.string(),z.string())}).optional(),runId:z.string(),projectId:z.number().int().positive()}),
      execute: async (args: {spec:string;name:string;value:Record<string,unknown>;overrides?:{tags:Record<string,string>};runId:string;projectId:number}, context: StageContext) => {
        const request=(await context.queryData(`modelName == "orbit-gateway" && name == "dependency-scan-input-${args.projectId}"`,"attributes"))[0] as {runId?:string}|undefined;
        if(request?.runId!==args.runId) throw new Error("stale_scan_publication");
        if(args.spec === "findings") {
          const value=FindingsSchema.parse(args.value);
          if(value.projectId!==args.projectId || value.source!==args.runId || args.name!==`findings-${args.projectId}`) throw new Error("scan_publication_identity_mismatch");
        } else {
          const value=args.spec==="dependencyIndex"?IndexSchema.parse(args.value):ReportSchema.parse(args.value);
          const expected="last_attempt" in value ? await indexName(value.project_id,value.source_ref) : await reportName(value.publication_id,value);
          if(args.name!==expected || value.project_id!==args.projectId || ("last_attempt" in value ? value.last_attempt.run_id : value.run_id)!==args.runId) throw new Error("scan_publication_identity_mismatch");
        }
        return {dataHandles:[await context.writeResource(args.spec,args.name,args.value,args.overrides)]};
      },
    },
    ensure_worker: {
      outputLifetime: "7d",
      description: "Enroll or reconcile worker startup on one provisioned external group; never starts agents or task work",
      arguments: z.object({ group: z.number().int().positive() }),
      execute: async (args: { group: number }, context: StageContext) => {
        const http = curlHttp(context.globalArgs);
        const response = await http("GET", `/api/v1/task-groups/${args.group}`);
        const group = response.body?.data as { id?: number; project_id?: number; brief?: string; orchestration?: string; status?: string; taskable_id?: number | null } | undefined;
        if (response.status !== 200 || group?.id !== args.group || group.orchestration !== "external" ||
          !["running", "reviewing"].includes(group.status ?? "") ||
          !Number.isSafeInteger(group.taskable_id) || Number(group.taskable_id) < 1) {
          throw new Error("Worker enrollment requires an open, provisioned external task group");
        }
        if (typeof group.brief !== "string" || !group.brief.split(/\r?\n/).includes("Swamp driver: orbit-gateway")) {
          throw new Error("Worker enrollment requires the Swamp driver brief");
        }
        if (!Number.isSafeInteger(group.project_id) || Number(group.project_id) < 1) throw new Error("Worker Project is not configured");
        if (group.project_id === horizonProject.projectId) {
          await assertProjectSettings({
            projectId: horizonProject.projectId,
            repository: `https://github.com/${horizonProject.repository}.git`,
            check: horizonProject.checkCommand,
            defaultBranch: horizonProject.defaultBranch,
          }, http);
        } else {
          const configs = await projectConfigurations(context.globalArgs.dependencyProjects, context.globalArgs.projectExtensions, context, group.project_id);
          const config = configs.find((entry) => entry.projectId === group.project_id);
          if (!config) throw new Error(`Worker Project ${group.project_id} is not configured`);
          await assertProjectSettings(config, http);
          const workItem = `tg-${args.group}`;
          const row = (await scheduleRows(context)).find(r => r.workItem === workItem);
          const claim = await reserve(context, { key: row?.key ?? `manual-${workItem}`, projectId: config.projectId, mode: row?.mode ?? "routine", workItem });
          if (!claim.admitted) throw new Error(claim.reason);
        }
        const existing = (await context.queryData(`modelName == ${JSON.stringify(context.definition.name)} && name == ${JSON.stringify(`worker-${args.group}`)}`, "attributes"))[0] as { ready?: boolean } | undefined;
        if (existing?.ready) throw new Error("The group worker is already enrolled; use its owning workflow to reconcile it");
        const handles: { name: string }[] = [];
        await ensureWorker(args.group, workerControl({
          ...context,
          writeResource: async (spec, name, value) => {
            const handle = await context.writeResource(spec, name, value);
            handles.push(handle);
            return handle;
          },
        }, http));
        return { dataHandles: handles };
      },
    },
    schedule_project: {
      outputLifetime: "7d",
      description: "Scan a Project on Services without a VM, or schedule routine execution",
      arguments: z.object({ projectId: z.number().int().positive(), mode: z.enum(["routine", "detect"]), runId: z.string().min(1).max(256).optional() }),
      execute: async (args: { projectId: number; mode: "routine" | "detect"; runId?: string }, context: StageContext) => {
        const configs = await projectConfigurations(context.globalArgs.dependencyProjects, context.globalArgs.projectExtensions, context, args.projectId);
        const config = configs[0];
        if (!config?.worker) throw new Error("scheduled maintenance requires a configured project worker");
        if (args.mode === "detect") {
          throw new Error("Use the project detect workflow on dependency-scanner");
        }
        await assertProjectSettings(config, curlHttp(context.globalArgs));
        const store = scheduleStore(context);
        const desired = scheduleReceipt(config, args.mode);
        // Only one scheduled routine/detect workspace per project at a time.
        const other = (await scheduleRows(context)).find((r) => r.projectId === args.projectId && r.key !== desired.key && !r.completed);
        if (other) {
          const skip = await context.writeResource("schedulerSkip", `scheduler-skip-${desired.key}`, {
            key: desired.key, projectId: args.projectId, mode: args.mode, blocker: other.key,
            reason: `scheduled project work ${other.key} is unfinished; skip this ${args.mode} fire`,
          });
          return { dataHandles: [skip] };
        }
        const existing=await store.read(desired.key);
        if(!existing)await store.save(desired);
        await projectWork(context);
        return { dataHandles: [] };
      },
    },
    project_work: {
      outputLifetime: "7d",
      description: "Read official factory state, store detection findings and admit bounded project batches, and list scheduled driver work",
      arguments: z.object({}),
      execute: async (_args: Record<string, never>, context: StageContext) => projectWork(context),
    },
    detect: {
      outputLifetime: "7d",
      description: "Read-only detection through the existing worker, check and no-change completion path",
      arguments: z.object({ workItem: z.string(), stage: z.enum(["provision", "script", "finish"]), key: z.string(),
        script: z.unknown().optional(), handoff: z.unknown().optional() }),
      execute: (args: StageArgs, context: StageContext) => executeStage({ ...args, routine: true, detection: true }, context),
    },
    reconcile_workers: {
      outputLifetime: "7d",
      description: "Revoke enrollment for ended groups, including external cancellation",
      arguments: z.object({}),
      execute: async (_args: Record<string, never>, context: StageContext) => {
        await reconcileWorkers(context, curlHttp(context.globalArgs));
        return { dataHandles: [] };
      },
    },
    admit_package: {
      outputLifetime: "7d",
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
            const project=(await groupConfigurations(workItem,context))[0];
            const row=(await scheduleRows(context)).find(r=>r.workItem===workItem);
            const claim=await reserve(context,{key:row?.key??`manual-${workItem}`,projectId:project.projectId,mode:"package",workItem});
            if(!claim.admitted)throw new Error(claim.reason);
            const r = await context.runModel({ definition: "dependency-package", method: "start", arguments: { workItem } });
            if (!r.ok) throw new Error(r.error?.message ?? "cannot start package factory");
          },
        });
        const receipt = (await scheduleRows(context)).find((r) => r.mode === "package" && r.workItem === target.workItem);
        if (receipt) {
          const selection = target.targets;
          await scheduleStore(context).save({ ...receipt, targets: selection, started: true });
        }
        return { dataHandles: handle ? [handle] : [] };
      },
    },
    package: {
      outputLifetime: "7d",
      description: "One project batch through the existing routine stage client",
      arguments: z.object({ workItem: z.string(), stage: z.enum(["provision", "script", "repair", "handoff", "publish", "merged", "finish"]),
        key: z.string(), handoff: z.unknown().optional(), change: z.unknown().optional(), script: z.unknown().optional(), workspace: z.unknown().optional() }),
      execute: async (args: StageArgs, context: StageContext) => {
        const rows = await context.queryData(`modelName == "${context.definition.name}" && name == "package-${args.workItem}"`, "attributes");
        const target = PackageTargetSchema.parse(rows[0]);
        return executeStage({ ...args, routine: true, packageTarget: target }, context);
      },
    },
    assistance: {
      outputLifetime: "7d",
      description: "Reconcile parked assistance; post-child group requests clear only after their exact factory approval",
      arguments: z.object({
        workItem: z.string().describe("tg-<task group id>"),
        parked: z.boolean(),
        stage: z.string(),
        cycle: z.number().int(),
        reason: z.string(),
        factory: z.string().optional(),
        gateIds: z.array(z.string()).optional(),
        driverDefinition: z.string().optional(),
        beforeWork: z.boolean().optional().describe("Before dispatch, reconcile only assistance from a previous stage entry"),
      }),
      execute: async (
        args: { workItem: string; parked: boolean; stage: string; cycle: number; reason: string; factory?: string; gateIds?: string[]; driverDefinition?: string; beforeWork?: boolean },
        context: {
          globalArgs: { gatewayUrl: string; caFile: string; timeoutSeconds: number };
          definition: { name: string };
          queryData: (predicate: string, select?: string) => Promise<unknown[]>;
          writeResource: (spec: string, name: string, data: Record<string, unknown>, overrides?:{tags:Record<string,string>}) => Promise<{ name: string }>;
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
          readRun: async () => args.stage === "driver" && args.gateIds?.[0] === "driver-factory-conflict" && args.driverDefinition
            ? ((await context.queryData(`modelName == ${JSON.stringify(args.driverDefinition)} && name == ${JSON.stringify(`conflict-${args.workItem}`)}`, "attributes")) as { workItem: string; startedAt: string }[])[0] ?? null
            : args.factory
            ? ((await context.queryData(`modelName == "${args.factory}" && name == "state-${args.workItem}"`, "attributes")) as { workItem: string; startedAt: string }[])[0] ?? null
            : null,
          readApproval: async (gateId) => /^driver-retry-g[1-9]\d*$/.test(gateId)
            ? args.driverDefinition
              ? ((await context.queryData(`modelName == ${JSON.stringify(args.driverDefinition)} && name == "retry"`, "attributes")) as unknown[])[0] ?? null
              : null
            : args.factory
            ? ((await context.queryData(`modelName == "${args.factory}" && name == "approval-${args.workItem}-${gateId}"`, "attributes")) as unknown[])[0] ?? null
            : null,
        });
        return { dataHandles: handle ? [handle] : [] };
      },
    },
    routine: {
      outputLifetime: "7d",
      description: "Routine project update: the tracked script, one repair turn, checks, publication and cleanup through existing keyed jobs",
      arguments: z.object({ workItem: z.string(), stage: z.enum(["provision", "script", "repair", "handoff", "publish", "merged", "finish"]),
        key: z.string(), handoff: z.unknown().optional(), change: z.unknown().optional(), script: z.unknown().optional(), workspace: z.unknown().optional() }),
      execute: (args: StageArgs, context: StageContext) => executeStage({ ...args, routine: true }, context),
    },
    stage: {
      outputLifetime: "7d",
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
  signal?: AbortSignal;
  readResource?: (name: string, version?: number) => Promise<Record<string, unknown> | null>;
  runModel: MaintenanceContext["runModel"] & ((args: { definition: string; method: string; arguments?: Record<string, unknown> }) => Promise<{ ok: boolean; error?: { message: string } }>);
  globalArgs: GlobalArgs;
  definition: { name: string };
  queryData: (predicate: string, select?: string) => Promise<unknown[]>;
  writeResource: (spec: string, name: string, data: Record<string, unknown>, overrides?:{tags:Record<string,string>}) => Promise<{ name: string }>;
  logger: { info: (msg: string, props?: Record<string, unknown>) => void };
}

async function executeStage(args: StageArgs, context: StageContext) {
  let handle: { name: string } | null = null;
  const http = curlHttp(context.globalArgs);
  if(args.routine && args.stage === "provision") {
    const config=(await groupConfigurations(args.workItem,context))[0];
    const row=(await scheduleRows(context)).find(r=>r.workItem===args.workItem);
    const claim=await reserve(context,{key:row?.key??`manual-${args.workItem}`,projectId:config.projectId,mode:args.detection?"detect":args.packageTarget?"package":"routine",workItem:args.workItem});
    if(!claim.admitted)throw new Error(claim.reason);
  }
  const intent = async () => {
    const config = (await groupConfigurations(args.workItem, context))[0];
    const row = (await scheduleRows(context)).find(r => r.workItem === args.workItem);
    return { key: row?.key ?? `manual-${args.workItem}`, projectId: config.projectId, mode: args.detection ? "detect" as const : args.packageTarget ? "package" as const : "routine" as const, workItem: args.workItem };
  };
  const job = await runStage(args, {
    ...(args.routine ? {
      reserveCapacity: async () => (await reserve(context, await intent())).admitted,
      reviewWait: async (job: Job) => batchReviewWait(job, {
        targets: () => context.queryData(`modelName == ${JSON.stringify(context.definition.name)} && name == ${JSON.stringify(`package-${job.workItem}`)}`, "attributes"),
        park: async () => {
          const input = await intent();
          const rows = await context.queryData(`modelName == ${JSON.stringify(context.definition.name)} && name == ${JSON.stringify(`admission-${input.key}`)}`, "attributes");
          if (!rows[0]) throw new Error("Review parking requires an admission receipt");
          const result = await parkForReview(job.key, AdmissionSchema.parse(rows[0]), { http, save: async a => { await context.writeResource("admission", `admission-${a.key}`, a); } });
          if (args.stage === "merged") return { ...job, lastError: job.lastError ?? result.reason ?? null };
          return result.ready ? job : { ...job, state: "running" as const, evidence: null, lastError: result.reason ?? null };
        },
      }),
    } : {}),
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

async function reserve(context: StageContext, intent: AdmissionIntent) {
  const rows = await scheduleRows(context);
  return reserveMaintenance(intent, {
    http: curlHttp(context.globalArgs),
    admissions:
      (await context.queryData(
        `modelName == ${
          JSON.stringify(context.definition.name)
        } && name.startsWith("admission-")`,
        "attributes",
      )).map((a) => AdmissionSchema.parse(a)),
    existing: rows.filter((r) => r.attempted && !r.completed).map((r) => ({
      key: r.key,
      projectId: r.projectId,
      mode: r.mode,
      workItem: r.workItem,
    })),
    save: async (a) => {
      await context.writeResource("admission", `admission-${a.key}`, a);
    },
  });
}
async function bindAdmission(
  context: StageContext,
  key: string,
  workItem: string,
) {
  const a =
    (await context.queryData(
      `modelName == ${JSON.stringify(context.definition.name)} && name == ${
        JSON.stringify(`admission-${key}`)
      }`,
      "attributes",
    ))[0] as Admission;
  await context.writeResource("admission", `admission-${key}`, {
    ...a,
    workItem,
  });
}
async function projectWork(context: StageContext) {
  const store = scheduleStore(context),
    work: Record<"routine" | "detect" | "package", string[]> = {
      routine: [],
      detect: [],
      package: [],
    };
  await reconcileAdmissions({
    admissions: (await context.queryData(
      `modelName == ${JSON.stringify(context.definition.name)} && name.startsWith("admission-")`,
      "attributes",
    )).map(a => AdmissionSchema.parse(a)),
    receipts: await scheduleRows(context),
    http: store.http,
    save: async a => {
      await context.writeResource("admission", `admission-${a.key}`, a);
    },
    saveReceipt: store.save,
    assistance: async (a, reason) => {
      await isolateScheduleRow(context, a, "admission", () =>
        reason === null ? Promise.resolve() : Promise.reject(new Error(reason)));
    },
  });
  const rows = await scheduleRows(context);
  const previous =
    (await context.queryData(
      `modelName == ${
        JSON.stringify(context.definition.name)
      } && name == "project-work"`,
      "attributes",
    ))[0] as { nextOffset?: number; nextPendingOffset?: number } | undefined;
  const active = rows.filter((r) => r.started && !r.completed),
    offset = active.length ? (previous?.nextOffset ?? 0) % active.length : 0;
  const batch = [...active.slice(offset), ...active.slice(0, offset)].slice(
    0,
    4,
  );
  for (const receipt of batch) {
    await isolateScheduleRow(context, receipt, "active", async () => {
      // Orbit cancellation is authoritative even when the old factory stage
      // no longer exists. Retire only after its cleanup and assistance settle.
      const response = await store.http(
          "GET",
          `/api/v1/task-groups/${receipt.workItem!.slice(3)}`,
        ),
        group = response.body?.data as Record<string, unknown> | undefined;
      if (response.status !== 200 || !group) {
        throw new Error("cannot read scheduled group cancellation state");
      }
      if (cleanedCancelledGroup(group)) {
        await store.save({ ...receipt, completed: true });
        return;
      }
      const factory = receipt.mode === "detect"
        ? "dependency-detect"
        : receipt.mode === "package"
        ? "dependency-package"
        : "dependency-routine";
      await callDefinition(context, factory, "status", {
        workItem: receipt.workItem,
      });
      const state =
        (await context.queryData(
          `modelName == ${JSON.stringify(factory)} && name == ${
            JSON.stringify(`state-${receipt.workItem}`)
          }`,
          "attributes",
        ))[0] as { stageId: string; status: string } | undefined;
      if (!state) {
        throw new Error("scheduled factory state is missing");
      }
      if (state.status !== "terminal") {
        work[receipt.mode].push(receipt.workItem!);
        return;
      }
      if (state.stageId !== "completed") {
        return;
      }
      // VM-era artifacts remain historical evidence. They cannot overwrite a Services plan.
      await store.save({ ...receipt, completed: true });
    });
  }
  // Only admitted intents are materialized. Findings without targets stay data.
  let latestRows = await scheduleRows(context);
  const documents =
    (await context.queryData(
      `modelName == ${
        JSON.stringify(context.definition.name)
      } && name.startsWith("findings-")`,
      "attributes",
    )).map((f) => FindingsSchema.parse(f));
  // Supersede only a proven unattempted, unbound package intent. Keep the complete ledger.
  // Attempted creates and real groups remain held until their ordinary reconciliation settles.
  for (const receipt of latestRows.filter(r => r.mode === "package" && !r.attempted && !r.started && !r.completed && !r.workItem)) {
    const current = documents.find(f => f.projectId === receipt.projectId);
    if (!current || current.source === receipt.findingsRunId) continue;
    await isolateScheduleRow(context,receipt,"admission",async()=>{
      const reservations = await context.queryData(`modelName == ${JSON.stringify(context.definition.name)} && name == ${JSON.stringify(`admission-${receipt.key}`)}`,"attributes");
      if(reservations.length>1)throw new Error("ambiguous unattempted admission");
      if(reservations.length){
        const reservation=AdmissionSchema.parse(reservations[0]);
        if(reservation.workItem!==null)throw new Error("superseded admission remains bound to a group");
        if(!reservation.released)await context.writeResource("admission",`admission-${receipt.key}`,{...reservation,released:true});
      }
      await store.save({...receipt,completed:true,supersededBy:current.source});
    });
  }
  latestRows = await scheduleRows(context);
  const queued = latestRows.filter((r) => !r.started && !r.completed && r.mode !== "detect").map(
    (r) => ({projectId:r.projectId,security:Boolean(r.targets?.some(t=>t.purpose==="security")),receipt:r,findings:null as z.infer<typeof FindingsSchema>|null})
  );
  for (const findings of documents) {
    if (!findings.targets.length || !findings.sourceCommit) continue;
    if (
      latestRows.some((r) => r.projectId === findings.projectId && !r.completed && (r.mode !== "detect" || r.attempted))
    ) continue;
    const config =
      (await projectConfigurations(
        context.globalArgs.dependencyProjects,
        context.globalArgs.projectExtensions,
        context,
        findings.projectId,
      ))[0];
    const receipt = await batchReceipt(config, findings);
    if (latestRows.some((r) => r.key === receipt.key)) continue;
    queued.push({
      projectId: findings.projectId,
      security: findings.targets.some((t) => t.purpose === "security"),
      receipt,
      findings,
    });
  }
  const history =
    (await context.queryData(
      `modelName == ${
        JSON.stringify(context.definition.name)
      } && name.startsWith("admission-")`,
      "attributes",
    )).map((a) => AdmissionSchema.parse(a));
  for (const entry of orderProjects(queued, history)) {
    await isolateScheduleRow(context, entry.receipt, "admission", async () => {
      let receipt = entry.receipt;
      if (receipt.mode === "package") {
        const current = documents.find((f) => f.projectId === receipt.projectId && f.source === receipt.findingsRunId);
        if (!current || JSON.stringify(current.targets) !== JSON.stringify(receipt.targets)) throw new Error("fresh_service_detection_required");
        const config = (await projectConfigurations(context.globalArgs.dependencyProjects, context.globalArgs.projectExtensions, context, receipt.projectId))[0];
        await assertCurrentPlan(config, current, context);
        await callDefinition(context, "dependency-package", "status", {});
        const runs =
          (await context.queryData(
            'modelName == "dependency-package" && name == "status-_factory"',
            "attributes.runs",
          ))[0] as { workItem: string; stageId: string; status: string }[];
        await assertPreviousPackages(
          receipt.projectId,
          receipt.workItem ?? "",
          {
            http: store.http,
            targets: await context.queryData(
              `modelName == ${
                JSON.stringify(context.definition.name)
              } && name.startsWith("package-")`,
              "attributes",
            ) as PackageTarget[],
            runState: (item) =>
              Promise.resolve(runs.find((r) => r.workItem === item) ?? null),
          },
        );
      }
      const claim = await reserve(context, {
        key: receipt.key,
        projectId: receipt.projectId,
        mode: receipt.mode,
        workItem: receipt.workItem,
      });
      if (!claim.admitted) {
        if (claim.admission?.released) await store.save({ ...receipt, completed: true });
        return;
      }
      receipt = await ensureProjectGroup(receipt, store);
      await bindAdmission(context, receipt.key, receipt.workItem!);
      if (receipt.mode === "package") {
        const target = PackageTargetSchema.parse({
          workItem: receipt.workItem,
          targets: receipt.targets,
          sourceCommit: documents.find((f) => f.source === receipt.findingsRunId && f.projectId === receipt.projectId)?.sourceCommit,
        });
        await callDefinition(context, "dependency-package", "status", {});
        const runs =
          (await context.queryData(
            'modelName == "dependency-package" && name == "status-_factory"',
            "attributes.runs",
          ))[0] as { workItem: string; stageId: string; status: string }[];
        await admitPackage(target, {
          http: store.http,
          projects: await groupConfigurations(target.workItem, context),
          targets: await context.queryData(
            `modelName == ${
              JSON.stringify(context.definition.name)
            } && name.startsWith("package-")`,
            "attributes",
          ) as PackageTarget[],
          runState: (item) =>
            Promise.resolve(
              runs.find((r) => r.workItem === item) ?? null,
            ),
          save: async (value) => {
            await context.writeResource(
              "packageTarget",
              `package-${value.workItem}`,
              value,
            );
          },
          start: (item) => startFactory(context, "dependency-package", item),
        });
      } else {await startFactory(
          context,
          receipt.mode === "detect"
            ? "dependency-detect"
            : "dependency-routine",
          receipt.workItem!,
        );}
      await store.save({ ...receipt, started: true });
      work[receipt.mode].push(receipt.workItem!);
    });
  }
  const issues =
    (await context.queryData(
      `modelName == ${
        JSON.stringify(context.definition.name)
      } && name.startsWith("scheduler-issue-")`,
      "attributes",
    )).map((r) => SchedulerIssueSchema.parse(r)).filter((r) =>
      r.status === "open"
    );
  const handle = await context.writeResource("projectWork", "project-work", {
    ...work,
    issues,
    nextOffset: active.length ? (offset + batch.length) % active.length : 0,
    nextQueueOffset: 0,
    nextPendingOffset: 0,
  });
  return { dataHandles: [handle] };
}
