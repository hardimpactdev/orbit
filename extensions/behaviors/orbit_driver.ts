// @nckrtl/orbit-driver — the tick-driven reconcile loop for a software factory.
// One `tick` takes each work item one short, guarded step:
//   dispatch  record_dispatch once per stage entry, then launch the stage's method
//   poll      call the method again (ensure semantics); when it has finished,
//             record its artifacts and resultEvidence
//   advance   take the one satisfied transition with no human gate
//   park      report what a human must decide (approval or cycle override); a
//             stored pre-approval for exactly that work item, gate, stage and
//             cycle is applied instead, so a pre-approved gate needs no live turn
//   assist    with assistanceModel set, the parked run raises assistance on the
//             target system; that behavior reconciles ownership and consent
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
  // A behavior with an `assistance` method; the driver tells it each tick
  // whether the work item is parked at a human gate.
  assistanceModel: z.string().optional(),
  // swamp caps cross-behavior calls per execution (100). A tick stops starting new
  // steps before it would reach this budget; the next tick resumes with the work
  // items it deferred.
  maxCallsPerTick: z.number().int().min(20).default(90),
});

/** The most calls one guarded step makes (status, dispatch, stage, artifacts, evidence, advance, approve), plus the assistance update. */
const STEP_CALL_RESERVE = 10;

/** Work items in tick order: starting at the first one the last tick deferred, so none starves. */
export function tickOrder(items: string[], offset: number): string[] {
  if (items.length === 0) return items;
  const o = ((offset % items.length) + items.length) % items.length;
  return [...items.slice(o), ...items.slice(0, o)];
}
type GlobalArgs = z.infer<typeof GlobalArgsSchema>;

const KILL_POINTS = ["after-dispatch", "after-launch", "before-evidence", "after-evidence", "before-advance"] as const;

const PreapprovalSchema = z.object({
  workItem: z.string(),
  gateId: z.string(),
  stage: z.string(),
  cycle: z.number().int(),
  actor: z.string(),
  note: z.string().nullable(),
  createdAt: z.string(),
  appliedAt: z.string().nullable(),
});
type Preapproval = z.infer<typeof PreapprovalSchema>;

/** One pre-approval per exact work item, gate, stage and cycle: another of any of them never matches. */
export function preapprovalName(workItem: string, gateId: string, stage: string, cycle: number): string {
  return `preapproval-${workItem}-${gateId}-${stage}-c${cycle}`;
}

/** The gate ids a parked status waits on: its pending human-approval gates and cycle overrides. */
export function pendingGateIds(status: Pick<Status, "transitions">): string[] {
  const ids = new Set<string>();
  for (const t of status.transitions) {
    if (t.name === "abort") continue;
    if (t.cycleLimitBlocked && t.gates.every((g) => g.pass)) ids.add(`cycle-override:${t.to}`);
    if (!t.gates.every((g) => g.pass || g.type === "human-approval")) continue;
    for (const g of t.gates) {
      if (g.type !== "human-approval" || g.pass) continue;
      for (const r of g.reasons) {
        const m = /awaiting human approval '([^']+)'/.exec(r);
        if (m) ids.add(m[1]);
      }
    }
  }
  return [...ids];
}

/** Evidence counts for the current entry only when it was recorded in this run's era (after start or reset). */
export function recordedThisEntry(
  ev: { stageId: string; cycle: number; recordedAt?: string } | null,
  stage: string,
  cycle: number,
  startedAt: string | null,
): boolean {
  if (ev === null || ev.stageId !== stage || ev.cycle !== cycle) return false;
  return startedAt === null || ev.recordedAt === undefined || ev.recordedAt >= startedAt;
}

const TickSchema = z.object({
  at: z.string(),
  deferred: z.array(z.string()).default([]),
  nextOffset: z.number().int().default(0),
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
  calls?: number;
};

async function call(ctx: Ctx, definition: string, method: string, args: Record<string, unknown>): Promise<void> {
  ctx.calls = (ctx.calls ?? 0) + 1;
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

/** A terminal run-record status (failed, cancelled, interrupted), or null. */
function runRecordEnded(repoDir: string, runId: string): string | null {
  const out = new Deno.Command(Deno.execPath(), {
    args: ["workflow", "history", "get", runId, "--json"],
    cwd: repoDir, stdin: "null", stdout: "piped", stderr: "null",
  }).outputSync();
  if (!out.success) return null;
  try {
    const text = new TextDecoder().decode(out.stdout);
    const status = (JSON.parse(text.slice(text.indexOf("{"), text.lastIndexOf("}") + 1)) as { status?: string }).status;
    return status && ["failed", "cancelled", "interrupted"].includes(status) ? status : null;
  } catch {
    return null;
  }
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
    // Deno.execPath() is the swamp binary itself; a systemd unit has no shell PATH.
    const out = await new Deno.Command(Deno.execPath(), {
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
    // Serve mode: the run has ended once swamp wrote its summary, or once its run
    // record says it failed, was cancelled, or was interrupted (a serve restart).
    const ended = (await summaryStatus(ctx, prev.runId)) ?? runRecordEnded(g.repoDir, prev.runId);
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
  const state = await latest<{ startedAt?: string }>(ctx, f, `state-${workItem}`);
  const startedAt = state?.startedAt ?? null;
  if (work?.mode === "workflow" && work.workflow && work.resultEvidence) {
    const ev = await latest<{ stageId: string; cycle: number; recordedAt?: string }>(ctx, f, `evidence-${workItem}-${work.resultEvidence}`);
    if (!recordedThisEntry(ev, stage, cycle, startedAt)) {
      return { status, ...(await workflowStep(ctx, workItem, key, status, killAt)) };
    }
  }
  if (work?.mode === "method" && work.method && work.resultEvidence) {
    if (status.unresolvedBindings.length > 0) {
      return { status, action: null, waiting: `unresolved bindings ${JSON.stringify(status.unresolvedBindings)}` };
    }
    const ev = await latest<{ stageId: string; cycle: number; recordedAt?: string }>(ctx, f, `evidence-${workItem}-${work.resultEvidence}`);
    if (!recordedThisEntry(ev, stage, cycle, startedAt)) {
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
  // Parked at a human gate: apply a stored pre-approval for exactly this entry.
  for (const gateId of pendingGateIds(status)) {
    const name = preapprovalName(workItem, gateId, stage, cycle);
    const pre = await latest<Preapproval>(ctx, ctx.definitionName, name);
    if (pre === null || pre.appliedAt !== null || pre.workItem !== workItem || pre.gateId !== gateId || pre.stage !== stage || pre.cycle !== cycle ||
      (startedAt === null || !Number.isFinite(Date.parse(startedAt)) || !Number.isFinite(Date.parse(pre.createdAt)) || Date.parse(pre.createdAt) < Date.parse(startedAt))) continue;
    await call(ctx, f, "approve", {
      workItem, gateId, actor: pre.actor, note: `pre-approved ${pre.createdAt}${pre.note ? `: ${pre.note}` : ""}`,
    });
    await ctx.writeResource("preapproval", name, { ...pre, appliedAt: new Date().toISOString() });
    return { status, action: `applied pre-approval ${gateId} by ${pre.actor}`, waiting: null };
  }
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
  version: "2026.10.23.1",
  globalArguments: GlobalArgsSchema,
  resources: {
    launch: {
      description: "Detached stage workflow launches per key",
      schema: z.object({ key: z.string(), workflow: z.string(), launches: z.number(), lastLaunchedAt: z.string(), log: z.string(), runId: z.string().optional() }),
      lifetime: "30d",
      garbageCollection: 10,
    },
    tick: { description: "What one tick did per work item", schema: TickSchema, lifetime: "30d", garbageCollection: 500 },
    preapproval: {
      description: "A human approval stored ahead of time for one exact work item, gate, stage and cycle",
      schema: PreapprovalSchema,
      lifetime: "infinite",
      garbageCollection: 10,
    },
  },
  methods: {
    preapprove: {
      description: "Store a human approval for a gate the run has not reached yet; the next tick that parks at exactly that gate, stage and cycle applies it",
      arguments: z.object({
        workItem: z.string(),
        gateId: z.string().min(1),
        stage: z.string().min(1),
        cycle: z.number().int().min(1),
        actor: z.string().min(1),
        note: z.string().optional(),
      }),
      execute: async (
        args: { workItem: string; gateId: string; stage: string; cycle: number; actor: string; note?: string },
        rawCtx: Omit<Ctx, "definitionName"> & { definition: { name: string } },
      ) => {
        const ctx = Object.assign(rawCtx, { definitionName: rawCtx.definition.name }) as unknown as Ctx;
        const state = await latest<{ status?: string }>(ctx, ctx.globalArgs.factory, `state-${args.workItem}`);
        if (state?.status !== "active") throw new Error(`${args.workItem} has no active run in ${ctx.globalArgs.factory}`);
        const handle = await ctx.writeResource("preapproval", preapprovalName(args.workItem, args.gateId, args.stage, args.cycle), {
          workItem: args.workItem, gateId: args.gateId, stage: args.stage, cycle: args.cycle, actor: args.actor,
          note: args.note ?? null, createdAt: new Date().toISOString(), appliedAt: null,
        });
        return { dataHandles: [handle] };
      },
    },
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
        const prev = await latest<{ nextOffset?: number }>(ctx, ctx.definitionName, "tick");
        const order = tickOrder(items, prev?.nextOffset ?? 0);
        const budgetLeft = () => ctx.globalArgs.maxCallsPerTick - (ctx.calls ?? 0) >= STEP_CALL_RESERVE;
        const out = [];
        const deferred: string[] = [];
        for (const workItem of order) {
          if (!budgetLeft()) {
            deferred.push(workItem);
            continue;
          }
          const actions: string[] = [];
          let last: Awaited<ReturnType<typeof step>> | null = null;
          for (let i = 0; i < args.maxSteps; i++) {
            if (i > 0 && !budgetLeft()) break;
            last = await step(ctx, workItem, args.killAt);
            if (last.action) actions.push(last.action);
            if (last.waiting) break;
          }
          if (ctx.globalArgs.assistanceModel && last) {
            // Tell the target system whether this work item now waits for a person.
            const parked = (last.waiting ?? "").startsWith("assistance: ");
            try {
              await call(ctx, ctx.globalArgs.assistanceModel, "assistance", {
                workItem, parked, factory: f, gateIds: parked ? pendingGateIds(last.status) : [], stage: last.status.stage.id, cycle: last.status.stage.cycle,
                reason: parked ? last.waiting!.slice("assistance: ".length) : "",
              });
            } catch (e) {
              ctx.logger.warning("{wi}: assistance update failed: {err}", { wi: workItem, err: String(e) });
            }
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
        if (deferred.length > 0) {
          ctx.logger.info("deferred {n} work item(s) to the next tick: call budget used", { n: deferred.length });
        }
        const nextOffset = deferred.length > 0 ? items.indexOf(deferred[0]) : 0;
        const handle = await ctx.writeResource("tick", "tick", { at: new Date().toISOString(), items: out, deferred, nextOffset });
        return { dataHandles: [handle] };
      },
    },
  },
};
