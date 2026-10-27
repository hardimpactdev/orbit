import { type DependencyProject } from "./dependency_projects.ts";
import { type Http, type StageArgs } from "./gateway_stage.ts";
import {
  ensurePlacedMaintenance,
  MaintenanceAttention,
  revokeEndedWorker,
  workerAccessName,
  type WorkerControl,
  type WorkerSession,
  type WorkflowControl,
  type WorkflowIntent,
  type WorkflowRun,
} from "./maintenance_worker.ts";

export interface MaintenanceContext {
  globalArgs: {
    enrollmentVault: string;
    workerUrl: string;
    serveUrl: string;
    serveTokenFile: string;
    caFile: string;
  };
  definition: { name: string };
  queryData: (predicate: string, select?: string) => Promise<unknown[]>;
  writeResource: (
    spec: string,
    name: string,
    data: Record<string, unknown>,
  ) => Promise<{ name: string }>;
  runModel: (
    args: {
      modelType: string;
      name: string;
      method: string;
      arguments?: Record<string, unknown>;
    },
  ) => Promise<{ ok: boolean }>;
  vaultService: {
    get: (vault: string, key: string, consumer: string) => Promise<string>;
  };
}

/** Swamp CLI owns server authentication and workflow transport; no credential file is read here. */
export async function workflowCommand(
  context: MaintenanceContext,
  args: string[],
  input?: Record<string, unknown>,
): Promise<Record<string, unknown>> {
  const g = context.globalArgs;
  const child = new Deno.Command(Deno.execPath(), {
    args: [
      ...args,
      "--server",
      g.serveUrl,
      "--token-file",
      g.serveTokenFile,
      "--ca-cert",
      g.caFile,
      "--json",
    ],
    stdin: input ? "piped" : "null",
    stdout: "piped",
    stderr: "piped",
  }).spawn();
  if (input) {
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify(input)));
    await writer.close();
  }
  const output = await child.output();
  if (!output.success) {
    throw new Error(
      "Swamp maintenance workflow request failed; inspect its report and history",
    );
  }
  return JSON.parse(new TextDecoder().decode(output.stdout));
}

export function workerControl(
  context: MaintenanceContext,
  http: Http,
): WorkerControl {
  const latest = async <T>(model: string, name: string) =>
    (await context.queryData(
      `modelName == ${JSON.stringify(model)} && name == ${
        JSON.stringify(name)
      }`,
      "attributes",
    ))[0] as T | undefined ?? null;
  const call = async (
    name: string,
    method: string,
    args = {},
    modelType = "swamp/enrollment-token",
  ) => {
    const result = await context.runModel({
      modelType,
      name,
      method,
      arguments: args,
    });
    if (!result.ok) throw new Error(`Swamp worker token ${method} failed`);
  };
  return {
    http,
    url: context.globalArgs.workerUrl,
    read: (group) =>
      latest<WorkerSession>(context.definition.name, `worker-${group}`),
    save: async (session) => {
      await context.writeResource("worker", `worker-${session.group}`, {
        ...session,
      });
    },
    token: (name) => latest(name, "token-main"),
    mint: (name) =>
      call(name, "mint", {
        durationMs: 2 * 60 * 60 * 1000,
        vaultName: context.globalArgs.enrollmentVault,
        maxEnrollments: 1,
      }),
    serverToken: (name) => latest(workerAccessName(name), "token-main"),
    mintServer: (name, expiresAt) =>
      call(workerAccessName(name), "mint", {
        principalId: `worker:${name}`,
        principalEmail: `${name}@task.invalid`,
        durationMs: Math.max(1, Date.parse(expiresAt) - Date.now()),
        vaultName: context.globalArgs.enrollmentVault,
      }, "swamp/server-token"),
    revokeServer: (name) =>
      call(workerAccessName(name), "revoke", {}, "swamp/server-token"),
    secret: (token) =>
      context.vaultService.get(
        token.vaultName,
        token.secretKey,
        "orbit-maintenance-enrollment",
      ),
    revoke: (name) => call(name, "revoke"),
    connected: async (name) => {
      const worker = await latest<
        { tokenName: string; status: string }
      >(`worker-${name}`, "state-main");
      return worker?.tokenName === name &&
        ["idle", "busy"].includes(worker.status);
    },
  };
}

export function workflowControl(context: MaintenanceContext): WorkflowControl {
  return {
    read: async (key) =>
      (await context.queryData(
        `modelName == ${JSON.stringify(context.definition.name)} && name == ${
          JSON.stringify(`maintenance-${key}`)
        }`,
        "attributes",
      ))[0] as WorkflowIntent | undefined ?? null,
    save: async (intent) => {
      await context.writeResource("maintenance", `maintenance-${intent.key}`, {
        ...intent,
      });
    },
    search: async (key) => {
      const result = await workflowCommand(context, [
        "workflow",
        "history",
        "search",
        "--workflow",
        "maintenance-worker",
        "--input",
        `key=${key}`,
      ]);
      return await Promise.all(
        (result.results as { runId: string }[]).map(async (run) =>
          await workflowCommand(context, [
            "workflow",
            "history",
            "get",
            run.runId,
          ]) as unknown as WorkflowRun
        ),
      );
    },
    launch: async (inputs) => {
      const result = await workflowCommand(context, [
        "workflow",
        "run",
        "maintenance-worker",
        "--detach",
        "--no-supersede",
        "--stdin",
      ], inputs);
      if (typeof result.runId !== "string") {
        throw new Error("Swamp maintenance launch has no run id");
      }
      return result.runId;
    },
    get: async (id) =>
      await workflowCommand(context, [
        "workflow",
        "history",
        "get",
        id,
      ]) as unknown as WorkflowRun,
  };
}

export function maintenanceInputs(
  project: DependencyProject,
  args: StageArgs,
  group: number,
  request: Record<string, unknown>,
): Record<string, unknown> & { key: string } {
  const worker = project.worker!;
  const target = args.packageTarget;
  const mode = String(request.mode);
  const selected = target ? [target.path ?? "."] : null;
  if (
    target &&
    !worker[target.manager === "composer" ? "composerPaths" : "jsPaths"]
      .includes(selected![0])
  ) throw new Error("package path is not configured for its ecosystem");
  const composerPaths = target
    ? target.manager === "composer" ? selected! : []
    : worker.composerPaths;
  const jsPaths = target
    ? target.manager === "js" ? selected! : []
    : worker.jsPaths;
  const policy = (paths: string[]) =>
    Object.fromEntries(
      Object.entries(worker.policy).filter(([path]) => paths.includes(path)),
    );
  const common = (paths: string[]) => ({
    cwd: request.cwd,
    paths,
    policy: policy(paths),
    captureFailure: true,
  });
  const packageInput = target && mode === "package"
    ? {
      path: target.path ?? ".",
      package: target.package,
      version: target.version,
      purpose: target.purpose,
      advisoryIds: target.advisories,
    }
    : {};
  const all = [...new Set([...worker.composerPaths, ...worker.jsPaths])];
  return {
    key: args.stage === "publish" ? `${args.key}.summary` : args.key,
    name: (args.stage === "publish" ? `${args.key}.summary` : args.key)
      .replaceAll(".", "-"),
    group: String(group),
    cwd: request.cwd,
    mode,
    method: mode === "summary" ? "summary" : mode,
    composerPaths,
    jsPaths,
    composer: { ...common(composerPaths), ...packageInput },
    js: { ...common(jsPaths), ...packageInput },
    composerCommon: common(composerPaths),
    jsCommon: common(jsPaths),
    summary: {
      ...common(all),
      ...(request.base ? { base: request.base } : {}),
    },
  };
}

export interface MaintenanceResult {
  state: "running" | "succeeded" | "failed";
  runId?: string;
  result?: Record<string, unknown>;
  lastError?: string;
  workerName?: string;
}
export async function runMaintenance(
  args: StageArgs,
  group: number,
  request: Record<string, unknown>,
  context: MaintenanceContext,
  http: Http,
): Promise<MaintenanceResult> {
  const inputs = maintenanceInputs(
    args.dependencyProject!,
    args,
    group,
    request,
  );
  let run: WorkflowRun | null;
  try {
    run = await ensurePlacedMaintenance(
      group,
      inputs,
      workerControl(context, http),
      workflowControl(context),
    );
  } catch (error) {
    if (!(error instanceof MaintenanceAttention)) throw error;
    return {
      state: "failed",
      lastError: error.message,
      workerName: error.workerName,
    };
  }
  if (!run || ["running", "pending"].includes(run.status)) {
    return { state: "running", ...(run ? { runId: run.id } : {}) };
  }
  return maintenanceResult(run);
}

function resource(
  step: WorkflowRun["jobs"][number]["steps"][number] | undefined,
  name: string,
): Record<string, unknown> | undefined {
  return step?.dataArtifacts?.find((artifact) => artifact.name === name)
    ?.attributes;
}
export function maintenanceResult(run: WorkflowRun): MaintenanceResult {
  const steps = run.jobs.flatMap((job) => job.steps);
  const before = resource(
    steps.find((step) => step.name === "before"),
    "snapshot",
  );
  const after = resource(
    steps.find((step) => step.name === "after"),
    "snapshot",
  );
  const valid = (value: unknown) =>
    typeof value === "string" && /^(?:[a-f0-9]{40}|[a-f0-9]{64})$/.test(value);
  if (
    run.status !== "succeeded" || !before || !after ||
    ![before.head, before.tree, after.head, after.tree].every(valid)
  ) {
    return {
      state: "failed",
      runId: run.id,
      workerName: String(run.inputs.worker),
    };
  }
  if (steps.some((step) => step.status === "failed")) {
    return {
      state: "failed",
      runId: run.id,
      workerName: String(run.inputs.worker),
    };
  }
  const receipts = steps.map((step) => resource(step, "failure")).filter((
    value,
  ) => value !== undefined);
  if (receipts.some((value) => !Number.isInteger(value?.exitCode))) {
    return {
      state: "failed",
      runId: run.id,
      workerName: String(run.inputs.worker),
    };
  }
  const failures = receipts;
  const summary = resource(
    steps.find((step) => step.name === "summary"),
    "summary",
  );
  return {
    state: "succeeded",
    runId: run.id,
    workerName: String(run.inputs.worker),
    result: {
      outcome: failures.length ? "failed" : "passed",
      exit_code: failures.length ? receipts[0]!.exitCode : 0,
      output: failures.length
        ? receipts.map((value) => String(value!.message)).join("\n").slice(
          -16000,
        )
        : String(summary?.text ?? "Maintenance extensions completed."),
      output_truncated: false,
      changed: before.tree !== after.tree,
      head: after.head,
      tree: after.tree,
      ...(summary
        ? { summary: { title: summary.title, body: summary.body } }
        : {}),
      detections: steps.flatMap((step) => {
        const detection = resource(step, "detection");
        return detection ? [detection] : [];
      }),
    },
  };
}

export async function reconcileWorkers(
  context: MaintenanceContext,
  http: Http,
): Promise<void> {
  const sessions = await context.queryData(
    `modelName == ${
      JSON.stringify(context.definition.name)
    } && name.startsWith("worker-") && attributes.revoked == false`,
    "attributes",
  ) as WorkerSession[];
  for (const session of sessions) {
    try {
      await revokeEndedWorker(session.group, workerControl(context, http));
    } catch {
      // This session remains unrevoked for the next minute's retry. Continue
      // cleaning up other groups without retaining secret-bearing exceptions.
    }
  }
}
