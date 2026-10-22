// @nckrtl/orbit-subtask — the stage work of orbit-subtask-factory.
// `run` has ensure semantics for one idempotency key: it launches the stage's
// effect as a detached job when none is running, relaunches an orphaned job
// (process gone, no result), and otherwise reports the job's state. It never
// blocks on the effect, so a driver tick stays short however long the job takes.
// A finished job is mapped to the factory products (artifacts + resultEvidence).
import { z } from "npm:zod@4";

const GlobalArgsSchema = z.object({
  markersDir: z.string().describe("One directory per idempotency key"),
  runEffect: z.string().describe("Path of bin/run-effect"),
  planModel: z.string().default("orbit-deps"),
  maxLaunches: z.number().int().default(3).describe("Launches per key before the job counts as failed"),
});
type GlobalArgs = z.infer<typeof GlobalArgsSchema>;

const STAGES = ["mechanical", "handoff", "implement", "consult", "review"] as const;

const JobSchema = z.object({
  key: z.string(),
  workItem: z.string(),
  stage: z.enum(STAGES),
  state: z.enum(["running", "succeeded", "failed"]),
  launches: z.number(),
  launchedNow: z.boolean(),
  llmTurns: z.number(),
  artifacts: z.record(z.string(), z.record(z.string(), z.unknown())),
  evidence: z.record(z.string(), z.unknown()).nullable(),
});
type Job = z.infer<typeof JobSchema>;

interface Envelope {
  payload: Record<string, unknown>;
  recordedAt: string;
}

/** A bound `data.latest(...)` record → its envelope, or null. */
function envelope(rec: unknown): Envelope | null {
  if (rec === null || rec === undefined || typeof rec !== "object") return null;
  const r = rec as Record<string, unknown>;
  const env = (r.attributes ?? r) as Record<string, unknown>;
  if (typeof env.payload !== "object" || env.payload === null) return null;
  return { payload: env.payload as Record<string, unknown>, recordedAt: String(env.recordedAt ?? "") };
}

function after(a: Envelope | null, ...others: (Envelope | null)[]): boolean {
  return a !== null && others.every((o) => o === null || a.recordedAt > o.recordedAt);
}

/** The inputs an effect sees, filtered to what is current for this entry. */
function effectInputs(stage: string, args: Record<string, unknown>): Record<string, unknown> {
  const fb = envelope(args.feedback);
  const rv = envelope(args.findings);
  const ca = envelope(args.consult);
  switch (stage) {
    case "implement":
      return {
        feedback: fb && fb.payload.status === "failed" && after(fb, rv) ? fb.payload : null,
        findings: after(rv, fb) ? rv!.payload : null,
        consult: after(ca, fb, rv) ? ca!.payload : null,
      };
    case "consult":
      return { blocked: envelope(args.blocked)?.payload ?? null };
    case "review":
      return { mechanical: envelope(args.mechanical)?.payload ?? null, previous: envelope(args.previous)?.payload ?? null };
    default:
      return {};
  }
}

function compact(o: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(Object.entries(o).filter(([, v]) => v !== null && v !== undefined && v !== ""));
}

/** Map an effect result to the products the factory declares for the stage. */
function products(stage: string, key: string, r: Record<string, unknown>): Pick<Job, "artifacts" | "evidence"> {
  const ok = r.status === "succeeded";
  const evidence: Record<string, unknown> = { status: ok ? "succeeded" : "failed", runId: key };
  const artifacts: Record<string, Record<string, unknown>> = {};
  switch (stage) {
    case "mechanical":
      if (ok) {
        artifacts["mechanical-change"] = compact({
          subProject: r.subProject,
          commit: r.commit,
          lockfileChanged: r.lockfileChanged,
          packages: r.packages,
        });
      }
      break;
    case "handoff":
      if (typeof r.commit === "string") {
        artifacts["handoff-result"] = compact({
          status: ok ? "succeeded" : "failed",
          exitCode: r.exitCode,
          commit: r.commit,
          diffstat: r.diffstat,
          consecutiveFailures: r.consecutiveFailures,
          outputTail: r.outputTail,
        });
        if (ok) artifacts["candidate"] = compact({ commit: r.commit, diffstat: r.diffstat });
      }
      break;
    case "implement": {
      const outcome = ok ? String(r.outcome) : "error";
      evidence.outcome = outcome;
      artifacts["change-summary"] = compact({ outcome, commit: r.commit, summary: r.summary, question: r.question });
      break;
    }
    case "consult":
      if (ok) artifacts["consult-answer"] = { question: r.question, answer: r.answer, needsPerson: r.needsPerson };
      break;
    case "review":
      if (ok) artifacts["code-review"] = (r.review ?? {}) as Record<string, unknown>;
      break;
  }
  return { artifacts, evidence };
}

function alive(pid: number): boolean {
  try {
    return Deno.statSync(`/proc/${pid}`).isDirectory;
  } catch {
    return false;
  }
}

function readText(path: string): string | null {
  try {
    return Deno.readTextFileSync(path);
  } catch {
    return null;
  }
}

export const behavior = {
  type: "@nckrtl/orbit-subtask",
  version: "2026.10.22.1",
  globalArguments: GlobalArgsSchema,
  resources: {
    job: {
      description: "State of one stage effect, by idempotency key",
      schema: JobSchema,
      lifetime: "infinite",
      garbageCollection: 10,
    },
  },
  methods: {
    run: {
      description: "Ensure the effect for one key: launch, relaunch an orphan, or report its result",
      arguments: z.object({
        workItem: z.string(),
        stage: z.enum(STAGES),
        key: z.string().describe("<workItem>.<stage>.c<cycle>"),
        feedback: z.unknown().optional(),
        findings: z.unknown().optional(),
        consult: z.unknown().optional(),
        blocked: z.unknown().optional(),
        mechanical: z.unknown().optional(),
        previous: z.unknown().optional(),
      }),
      execute: async (
        args: Record<string, unknown> & { workItem: string; stage: (typeof STAGES)[number]; key: string },
        context: {
          globalArgs: GlobalArgs;
          readModelData: (model: string, spec?: string) => Promise<{ attributes: Record<string, unknown> }[]>;
          writeResource: (spec: string, name: string, data: Record<string, unknown>) => Promise<{ name: string }>;
          logger: { info: (msg: string, props?: Record<string, unknown>) => void };
        },
      ) => {
        const g = context.globalArgs;
        const dir = `${g.markersDir}/${args.key}`;
        const launches = (readText(`${dir}/attempts`) ?? "").split("\n").filter(Boolean).length;
        const resultText = readText(`${dir}/result.json`);
        const pid = Number(readText(`${dir}/pid`) ?? "0");

        let job: Job;
        const base = { key: args.key, workItem: args.workItem, stage: args.stage, launches };
        if (resultText !== null) {
          const r = JSON.parse(resultText) as Record<string, unknown>;
          job = {
            ...base,
            state: r.status === "succeeded" ? "succeeded" : "failed",
            launchedNow: false,
            llmTurns: Number(r.llmTurns ?? 0),
            ...products(args.stage, args.key, r),
          };
        } else if (pid > 0 && alive(pid)) {
          job = { ...base, state: "running", launchedNow: false, llmTurns: 0, artifacts: {}, evidence: null };
        } else if (launches >= g.maxLaunches) {
          job = {
            ...base,
            state: "failed",
            launchedNow: false,
            llmTurns: 0,
            ...products(args.stage, args.key, { status: "failed", error: `orphaned ${launches} times` }),
          };
        } else {
          // Not launched yet, or orphaned (killed without a result): (re)launch.
          const plan = (await context.readModelData(g.planModel, "upgradePlan"))[0]?.attributes as
            | { commit: string; subtasks: { id: string; subProject: string }[] }
            | undefined;
          const subtask = plan?.subtasks.find((s) => s.id === args.workItem);
          if (!plan || !subtask) throw new Error(`work item ${args.workItem} is not a subtask of the current upgrade-plan`);
          Deno.mkdirSync(dir, { recursive: true });
          Deno.writeTextFileSync(
            `${dir}/env`,
            [`WORK_ITEM=${args.workItem}`, `SUB_PROJECT=${subtask.subProject}`, `KEY=${args.key}`, `PLAN_COMMIT=${plan.commit}`, ""]
              .join("\n"),
          );
          Deno.writeTextFileSync(`${dir}/inputs.json`, JSON.stringify(effectInputs(args.stage, args), null, 2));
          const child = new Deno.Command("setsid", {
            args: ["--fork", g.runEffect, dir, args.stage],
            stdin: "null",
            stdout: "null",
            stderr: "null",
          }).spawn();
          await child.status;
          context.logger.info("launched {key} (launch {n})", { key: args.key, n: launches + 1 });
          job = { ...base, launches: launches + 1, state: "running", launchedNow: true, llmTurns: 0, artifacts: {}, evidence: null };
        }
        const handle = await context.writeResource("job", `job-${args.key}`, job);
        return { dataHandles: [handle] };
      },
    },
  },
};
