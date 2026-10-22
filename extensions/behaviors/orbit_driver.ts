// @nckrtl/orbit-driver — the tick-driven reconcile loop for a software factory.
// One `tick` takes each work item one short, guarded step:
//   dispatch  record_dispatch once per stage entry, then launch the stage's method
//   poll      call the method again (ensure semantics); when it has finished,
//             record its artifacts and resultEvidence
//   advance   take the one satisfied transition with no human gate
//   park      report what a human must decide (approval or cycle override)
// The driver never re-dispatches an entry: recovery re-runs the idempotent
// method under the dispatch already recorded, so the dispatch guard is not used up.
import { z } from "npm:zod@4";

const GlobalArgsSchema = z.object({
  factory: z.string().default("orbit-subtask-factory"),
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
  work?: { mode: string; method?: { modelIdOrName: string; methodName: string; inputs: Record<string, unknown> }; resultEvidence?: string };
  unresolvedBindings: unknown[];
  transitions: Transition[];
}
interface RunResult {
  ok: boolean;
  error?: { message: string };
}

type Ctx = {
  globalArgs: GlobalArgs;
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

/** One guarded step for one work item. Returns what it did, or why it waits. */
async function step(ctx: Ctx, workItem: string, killAt?: string): Promise<{ status: Status; action: string | null; waiting: string | null }> {
  const f = ctx.globalArgs.factory;
  await call(ctx, f, "status", { workItem });
  const status = (await latest<Status>(ctx, f, `status-${workItem}`))!;
  const { id: stage, cycle, terminal } = status.stage;
  if (terminal) return { status, action: null, waiting: `terminal (${stage})` };

  const work = status.work;
  const key = `${workItem}.${stage}.c${cycle}`;
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

  // Products are in: advance along the one transition that needs no human.
  const auto = status.transitions.filter((t) =>
    t.satisfied && !t.manual && !t.gates.some((g) => g.type === "human-approval")
  );
  if (auto.length === 1) {
    killIf("before-advance", killAt, ctx);
    await call(ctx, f, "advance", { workItem, transition: auto[0].name });
    return { status, action: `advanced ${stage} --${auto[0].name}--> ${auto[0].to}`, waiting: null };
  }
  if (auto.length > 1) return { status, action: null, waiting: `several transitions satisfied: ${auto.map((t) => t.name)}` };
  const limited = status.transitions.filter((t) => t.cycleLimitBlocked);
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
    tick: { description: "What one tick did per work item", schema: TickSchema, lifetime: "30d", garbageCollection: 500 },
  },
  methods: {
    tick: {
      description: "Take each work item one guarded step (dispatch, poll, record, or advance)",
      arguments: z.object({
        workItems: z.array(z.string()).optional().describe("Default: every active run of the factory"),
        maxSteps: z.number().int().min(1).default(1),
        killAt: z.enum(KILL_POINTS).optional().describe("Kill test: SIGKILL this process at that point"),
      }),
      execute: async (
        args: { workItems?: string[]; maxSteps: number; killAt?: string },
        ctx: Ctx,
      ) => {
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
