// @nckrtl/orbit-driver — the tick-driven reconcile loop for a software factory.
// One `tick` takes each work item one short, guarded step:
//   dispatch  record_dispatch once per stage entry, then launch the stage's method
//   poll      call the method again (ensure semantics); when it has finished,
//             record its artifacts and resultEvidence
//   advance   take the one satisfied transition with no human gate
//   park      report what a human must decide (approval or cycle override)
// Work modes:
//   method    (v1) call the stage method; it launches/polls a detached effect job
//   workflow  (v2) start the stage workflow detached (`swamp workflow run`); the
//             workflow records its own resultEvidence. Liveness comes from the
//             run's claim record (orbit-stage.claim); a dead run is relaunched
//             under the same key and its guards skip the work already done.
// The driver never re-dispatches an entry: recovery re-runs the idempotent
// method under the dispatch already recorded, so the dispatch guard is not used up.
import { z } from "npm:zod@4";

const GlobalArgsSchema = z.object({
  factory: z.string().default("orbit-subtask-factory"),
  // workflow mode only
  repoDir: z.string().default("/fast/apps/orbit-swamp"),
  scratchRoot: z.string().default("/fast/apps/orbit-swamp-scratch"),
  baseClone: z.string().default("/fast/apps/orbit-swamp-scratch/base"),
  planModel: z.string().default("orbit-deps"),
  stageModel: z.string().default("orbit-stage"),
  maxLaunches: z.number().int().default(3),
  startGraceSeconds: z.number().int().default(180),
  // When set, stage runs start through swamp serve (`--server --detach`) and
  // liveness comes from swamp's own run summary instead of a claim pid.
  serveUrl: z.string().optional(),
  serveTokenFile: z.string().optional(),
  maxRunSeconds: z.number().int().default(7200),
});
type GlobalArgs = z.infer<typeof GlobalArgsSchema>;

const KILL_POINTS = ["after-dispatch", "after-launch", "before-evidence", "after-evidence", "before-advance"] as const;

const TickSchema = z.object({
  at: z.string(),
  items: z.array(z.object({
    workItem: z.string(),
    stage: z.string(),
    cycle: z.number(),
    actions: z.array(z.string()),
    waiting: z.string().nullable(),
  })),
});

interface Gate {
  type: string;
  pass: boolean;
  reasons: string[];
}
interface Transition {
  name: string;
  to: string;
  manual: boolean;
  satisfied: boolean;
  cycleLimitBlocked: boolean;
  gates: Gate[];
}
interface Status {
  started: boolean;
  stage: { id: string; cycle: number; terminal: boolean };
  dispatch?: { required: boolean; executed: boolean; attempts: number; limit: number };
  work?: {
    mode: string;
    method?: { modelIdOrName: string; methodName: string; inputs: Record<string, unknown> };
    workflow?: { name: string; inputs: Record<string, unknown> };
    resultEvidence?: string;
  };
  unresolvedBindings: unknown[];
  transitions: Transition[];
}
interface RunResult {
  ok: boolean;
  error?: { message: string };
}

type Ctx = {
  globalArgs: GlobalArgs;
  definitionName: string;
  runModel: (o: { definition: string; method: string; arguments?: Record<string, unknown> }) => Promise<RunResult>;
  queryData: (predicate: string, select?: string) => Promise<unknown[]>;
  writeResource: (spec: string, name: string, data: Record<string, unknown>) => Promise<{ name: string }>;
  logger: { info: (msg: string, props?: Record<string, unknown>) => void; warning: (msg: string, props?: Record<string, unknown>) => void };
};

async function call(ctx: Ctx, definition: string, method: string, args: Record<string, unknown>): Promise<void> {
  const r = await ctx.runModel({ definition, method, arguments: args });
  if (!r.ok) throw new Error(`${definition}.${method}: ${r.error?.message}`);
}

async function latest<T>(ctx: Ctx, model: string, name: string): Promise<T | null> {
  const rows = (await ctx.queryData(`modelName == "${model}" && name == "${name}"`, "attributes")) as T[];
  return rows[0] ?? null;
}

function killIf(point: string, killAt: string | undefined, ctx: Ctx) {
  if (killAt !== point) return;
  ctx.logger.warning("kill test: SIGKILL at {point}", { point });
  Deno.kill(Deno.pid, "SIGKILL");
}

/** Is the swamp process that claimed a key still alive? */
function processAlive(pid: number): boolean {
  const r = new Deno.Command("ps", { args: ["-o", "comm=", "-p", String(pid)], stdout: "piped", stderr: "null" }).outputSync();
  return r.success && new TextDecoder().decode(r.stdout).trim() !== "";
}

interface Launch {
  key: string;
  workflow: string;
  launches: number;
  lastLaunchedAt: string;
  log: string;
  runId?: string;
}

/** Status of a serve-started run from swamp's run summary, or null while it has none. */
async function summaryStatus(ctx: Ctx, runId: string): Promise<string | null> {
  const rows = (await ctx.queryData(
    `name == "report-swamp-workflow-summary-json" && version > 0`,
    "content",
  )) as (string | null)[];
  for (const c of rows) {
    if (!c) continue;
    try {
      const j = JSON.parse(c) as { workflowRunId?: string; status?: string };
      if (j.workflowRunId === runId) return j.status ?? "unknown";
    } catch { /* not JSON */ }
  }
  return null;
}

/** Start one stage workflow run, detached. Phase 3 replaces this with a swamp serve API call. */
async function launchWorkflow(ctx: Ctx, workflow: string, key: string, inputs: Record<string, unknown>, prev: Launch | null): Promise<Launch> {
  const g = ctx.globalArgs;
  const dir = `${g.scratchRoot}/launch`;
  await Deno.mkdir(dir, { recursive: true });
  const n = (prev?.launches ?? 0) + 1;
  const inputFile = `${dir}/${key}.json`;
  const log = `${dir}/${key}.${n}.log`;
  await Deno.writeTextFile(inputFile, JSON.stringify(inputs));
  let runId: string | undefined;
  if (g.serveUrl) {
    // Through swamp serve: the run lives in serve; --detach returns its id at once.
    const out = await new Deno.Command("swamp", {
      args: ["workflow", "run", workflow, "--server", g.serveUrl, "--token-file", g.serveTokenFile ?? "", "--detach",
        "--input-file", inputFile, "--no-supersede", "--json"],
      cwd: g.repoDir, stdin: "null", stdout: "piped", stderr: "piped",
    }).output();
    const text = new TextDecoder().decode(out.stdout);
    await Deno.writeTextFile(log, text + new TextDecoder().decode(out.stderr));
    if (!out.success) throw new Error(`serve launch of ${workflow} for ${key} failed; see ${log}`);
    runId = (JSON.parse(text.slice(text.indexOf("{"), text.lastIndexOf("}") + 1)) as { runId: string }).runId;
  } else {
    const cmd = `exec swamp workflow run ${workflow} --input-file ${inputFile} --no-supersede > ${log} 2>&1`;
    await new Deno.Command("setsid", { args: ["--fork", "sh", "-c", cmd], cwd: g.repoDir, stdin: "null", stdout: "null", stderr: "null" })
      .spawn().status;
  }
  const launch = { key, workflow, launches: n, lastLaunchedAt: new Date().toISOString(), log, ...(runId ? { runId } : {}) };
  await ctx.writeResource("launch", `launch-${key}`, launch as unknown as Record<string, unknown>);
  return launch;
}

/** Workflow-mode work: start the stage run, or report whether the started one is alive. */
async function workflowStep(ctx: Ctx, workItem: string, key: string, status: Status, killAt?: string) {
  const g = ctx.globalArgs;
  const wf = status.work!.workflow!;
  if (status.dispatch?.required && !status.dispatch.executed) {
    await call(ctx, g.factory, "record_dispatch", { workItem, mode: "workflow", runId: key });
    killIf("after-dispatch", killAt, ctx);
  }
  const prev = await latest<Launch>(ctx, ctx.definitionName, `launch-${key}`);
  const claim = await latest<{ pid: number; startedAt: string; runId: string }>(ctx, g.stageModel, `claim-${key}`);
  if (prev?.runId) {
    // Serve mode: the run has ended once swamp wrote its summary.
    const ended = await summaryStatus(ctx, prev.runId);
    const age = (Date.now() - Date.parse(prev.lastLaunchedAt)) / 1000;
    if (ended === null && age < g.maxRunSeconds) {
      return { action: null, waiting: `stage run ${prev.runId.slice(0, 8)} for ${key} running (serve)` };
    }
    if (prev.launches >= g.maxLaunches) {
      return { action: null, waiting: `assistance: ${prev.workflow} for ${key} ended ${prev.launches} times without recording ${status.work!.resultEvidence} (last ${ended ?? "overdue"}); run ${prev.runId}` };
    }
  } else if (prev) {
    const claimedThisLaunch = claim !== null && claim.startedAt >= prev.lastLaunchedAt;
    if (claimedThisLaunch && processAlive(claim.pid)) return { action: null, waiting: `stage run ${claim.runId.slice(0, 8)} for ${key} running` };
    const age = (Date.now() - Date.parse(prev.lastLaunchedAt)) / 1000;
    if (!claimedThisLaunch && age < g.startGraceSeconds) return { action: null, waiting: `stage run for ${key} starting (${Math.round(age)}s)` };
    if (prev.launches >= g.maxLaunches) {
      return { action: null, waiting: `assistance: ${prev.workflow} for ${key} ended ${prev.launches} times without recording ${status.work!.resultEvidence}; see ${prev.log}` };
    }
  }
  const plan = (await latest<{ commit: string; subtasks: { id: string; subProject: string }[] }>(ctx, g.planModel, "upgrade-plan"))!;
  const subtask = plan.subtasks.find((s) => s.id === workItem);
  if (!subtask) throw new Error(`${workItem} is not a subtask of the current upgrade-plan`);
  const inputs = {
    ...wf.inputs,
    workItem,
    key,
    worktree: `${g.scratchRoot}/wt2/${workItem}`,
    branch: `swamp/v2/${workItem}`,
    subProject: subtask.subProject,
    planCommit: plan.commit,
    baseClone: g.baseClone,
  };
  const l = await launchWorkflow(ctx, wf.name, key, inputs, prev);
  killIf("after-launch", killAt, ctx);
  return { action: `launched ${wf.name} for ${key} (launch ${l.launches})`, waiting: `stage run for ${key} starting` };
}

/** One guarded step for one work item. Returns what it did, or why it waits. */
async function step(ctx: Ctx, workItem: string, killAt?: string): Promise<{ status: Status; action: string | null; waiting: string | null }> {
  const f = ctx.globalArgs.factory;
  await call(ctx, f, "status", { workItem });
  const status = (await latest<Status>(ctx, f, `status-${workItem}`))!;
  const { id: stage, cycle, terminal } = status.stage;
  if (terminal) return { status, action: null, waiting: `terminal (${stage})` };

  const work = status.work;
  const key = `${workItem}.${stage}.c${cycle}`;
  if (work?.mode === "workflow" && work.workflow && work.resultEvidence) {
    const ev = await latest<{ stageId: string; cycle: number }>(ctx, f, `evidence-${workItem}-${work.resultEvidence}`);
    if (!(ev !== null && ev.stageId === stage && ev.cycle === cycle)) {
      return { status, ...(await workflowStep(ctx, workItem, key, status, killAt)) };
    }
  }
  if (work?.mode === "method" && work.method && work.resultEvidence) {
    if (status.unresolvedBindings.length > 0) {
      return { status, action: null, waiting: `unresolved bindings ${JSON.stringify(status.unresolvedBindings)}` };
    }
    const ev = await latest<{ stageId: string; cycle: number }>(ctx, f, `evidence-${workItem}-${work.resultEvidence}`);
    const recordedThisEntry = ev !== null && ev.stageId === stage && ev.cycle === cycle;
    if (!recordedThisEntry) {
      if (status.dispatch?.required && !status.dispatch.executed) {
        await call(ctx, f, "record_dispatch", { workItem, mode: "method", runId: key });
        killIf("after-dispatch", killAt, ctx);
      }
      const m = work.method;
      await call(ctx, m.modelIdOrName, m.methodName, { ...m.inputs, key });
      const job = (await latest<{
        state: string;
        launchedNow: boolean;
        launches: number;
        artifacts: Record<string, Record<string, unknown>>;
        evidence: Record<string, unknown> | null;
      }>(ctx, m.modelIdOrName, `job-${key}`))!;
      if (job.state === "running") {
        if (job.launchedNow) killIf("after-launch", killAt, ctx);
        return { status, action: job.launchedNow ? `launched ${key} (launch ${job.launches})` : null, waiting: `job ${key} running` };
      }
      killIf("before-evidence", killAt, ctx);
      for (const [name, payload] of Object.entries(job.artifacts)) {
        await call(ctx, f, "record_artifact", { workItem, name, payload });
      }
      await call(ctx, f, "record_evidence", { workItem, name: work.resultEvidence, payload: job.evidence! });
      killIf("after-evidence", killAt, ctx);
      return { status, action: `recorded ${key} (${job.state})`, waiting: null };
    }
  }

  // Products are in: advance along the one satisfied transition. Satisfied
  // means every gate passed, so a human gate on it has been approved already.
  const auto = status.transitions.filter((t) => t.satisfied && !t.manual);
  if (auto.length === 1) {
    killIf("before-advance", killAt, ctx);
    await call(ctx, f, "advance", { workItem, transition: auto[0].name });
    return { status, action: `advanced ${stage} --${auto[0].name}--> ${auto[0].to}`, waiting: null };
  }
  if (auto.length > 1) return { status, action: null, waiting: `several transitions satisfied: ${auto.map((t) => t.name)}` };
  const limited = status.transitions.filter((t) => t.cycleLimitBlocked && t.gates.every((g) => g.pass));
  if (limited.length > 0) {
    return { status, action: null, waiting: `assistance: cycle limit; approve gateId=cycle-override:${limited[0].to}` };
  }
  const human = status.transitions.filter((t) =>
    t.name !== "abort" && t.gates.every((g) => g.pass || g.type === "human-approval") &&
    t.gates.some((g) => !g.pass)
  );
  if (human.length > 0) {
    const ids = human.flatMap((t) => t.gates.filter((g) => !g.pass).map((g) => g.reasons.join("; ")));
    return { status, action: null, waiting: `assistance: ${ids.join(" | ")}` };
  }
  const reasons = status.transitions.filter((t) => t.name !== "abort")
    .map((t) => `${t.name}: ${t.gates.filter((g) => !g.pass).flatMap((g) => g.reasons).join("; ")}`);
  return { status, action: null, waiting: `blocked: ${reasons.join(" | ")}` };
}

export const behavior = {
  type: "@nckrtl/orbit-driver",
  version: "2026.10.22.1",
  globalArguments: GlobalArgsSchema,
  resources: {
    launch: {
      description: "Detached stage workflow launches per key",
      schema: z.object({ key: z.string(), workflow: z.string(), launches: z.number(), lastLaunchedAt: z.string(), log: z.string(), runId: z.string().optional() }),
      lifetime: "30d",
      garbageCollection: 10,
    },
    tick: { description: "What one tick did per work item", schema: TickSchema, lifetime: "30d", garbageCollection: 500 },
  },
  methods: {
    tick: {
      description: "Take each work item one guarded step (dispatch, poll, record, or advance)",
      arguments: z.object({
        workItems: z.array(z.string()).nullish().describe("Default: every active run of the factory"),
        maxSteps: z.number().int().min(1).default(1),
        killAt: z.enum(KILL_POINTS).optional().describe("Kill test: SIGKILL this process at that point"),
      }),
      execute: async (
        args: { workItems?: string[] | null; maxSteps: number; killAt?: string },
        rawCtx: Omit<Ctx, "definitionName"> & { definition: { name: string } },
      ) => {
        const ctx = Object.assign(rawCtx, { definitionName: rawCtx.definition.name }) as unknown as Ctx;
        const f = ctx.globalArgs.factory;
        const items = args.workItems ?? ((await ctx.queryData(
          `modelName == "${f}" && name.startsWith("state-") && attributes.status == "active"`,
          "attributes.workItem",
        )) as string[]);
        const out = [];
        for (const workItem of items) {
          const actions: string[] = [];
          let last: Awaited<ReturnType<typeof step>> | null = null;
          for (let i = 0; i < args.maxSteps; i++) {
            last = await step(ctx, workItem, args.killAt);
            if (last.action) actions.push(last.action);
            if (last.waiting) break;
          }
          ctx.logger.info("{wi}: {acts} {wait}", {
            wi: workItem,
            acts: actions.join(", ") || "-",
            wait: last?.waiting ? `[${last.waiting}]` : "",
          });
          out.push({
            workItem,
            stage: last!.status.stage.id,
            cycle: last!.status.stage.cycle,
            actions,
            waiting: last!.waiting,
          });
        }
        const handle = await ctx.writeResource("tick", "tick", { at: new Date().toISOString(), items: out });
        return { dataHandles: [handle] };
      },
    },
  },
};
