import { callWorkerModel, type WorkerModelContext } from "./_lib/worker_model.ts";
// @nckrtl/orbit-stage — glue steps for the stage workflows of orbit-subtasks.
// Each stage workflow (workflows/workflow-stage-*.yaml) does its work with
// @swamp/git, @nckrtl/composer and @swamp/agent-runner, and uses these methods
// to claim the run, check exactly-once state, build prompts, and finally record
// the stage's artifacts and resultEvidence on the factory with its own run id
// (the factory DESIGN's self-reporting variant).
import { z } from "npm:zod@4";
import {
  cycleOf,
  type Envelope,
  firstJson,
  implementContext,
  implementOutcome,
  nextStreak,
  toFactoryFindings,
} from "./_lib/stage_logic.ts";

const GlobalArgsSchema = z.object({
  factory: z.string().default("orbit-subtasks"),
  composer: z.string().default("composer"),
  scratchRoot: z.string().default("/fast/apps/orbit-swamp-scratch"),
});
type G = z.infer<typeof GlobalArgsSchema>;

const STAGES = ["mechanical", "handoff", "implement", "consult", "review"] as const;
const RESULT_EVIDENCE: Record<string, string> = {
  mechanical: "mechanical-run",
  handoff: "handoff-run",
  implement: "implement-run",
  consult: "consult-run",
  review: "review-run",
};

type Rec = { name: string; attributes: Record<string, unknown>; version?: number };
type Ctx = WorkerModelContext & {
  globalArgs: G;
  queryData: (predicate: string, select?: string) => Promise<unknown[]>;
  writeResource: (spec: string, name: string, data: Record<string, unknown>) => Promise<{ name: string }>;
  logger: { info: (m: string, p?: Record<string, unknown>) => void };
};

const StageArgs = z.object({
  workItem: z.string(),
  stage: z.enum(STAGES),
  key: z.string(),
  worktree: z.string(),
  subProject: z.string(),
  planCommit: z.string(),
});
type StageArgsT = z.infer<typeof StageArgs>;

async function one(ctx: Ctx, predicate: string): Promise<Rec | null> {
  return ((await ctx.queryData(predicate)) as Rec[])[0] ?? null;
}

async function envelope(ctx: Ctx, workItem: string, artifact: string): Promise<Envelope | null> {
  const r = await one(ctx, `modelName == "${ctx.globalArgs.factory}" && name == "artifact-${workItem}-${artifact}"`);
  return r ? (r.attributes as unknown as Envelope) : null;
}

async function call(ctx: Ctx, o: import("./native_host_method.ts").NativeModelRequest) {
  const r = await callWorkerModel(ctx, o);
  if (!r.ok) throw new Error(`${o.definition ?? o.name}.${o.method}: ${r.error?.message}`);
}

/** @swamp/git on the work item's worktree, via direct execution. */
async function gitWt(ctx: Ctx, a: StageArgsT, method: string, args: Record<string, unknown>): Promise<Record<string, unknown>> {
  const name = `git-wt-${a.workItem}`;
  await call(ctx, { modelType: "@swamp/git", name, method, arguments: { repoPath: a.worktree, ...args } });
  return (await one(ctx, `modelName == "${name}" && name == "${method}"`))!.attributes;
}

async function committedFor(ctx: Ctx, a: StageArgsT): Promise<string | null> {
  const log = await gitWt(ctx, a, "log", {
    format: "%H %(trailers:key=Swamp-Idempotency-Key,valueonly,separator=%x2C)",
    maxCount: 200,
  });
  for (const line of String(log.raw).split("\n")) {
    const [sha, keys = ""] = line.split(" ");
    if (keys.split(",").includes(a.key)) return sha;
  }
  return null;
}

async function git(dir: string, args: string[]): Promise<string> {
  const out = await new Deno.Command("git", { args: ["-C", dir, ...args], stdout: "piped", stderr: "piped" }).output();
  if (!out.success) throw new Error(`git ${args.join(" ")}: ${new TextDecoder().decode(out.stderr)}`);
  return new TextDecoder().decode(out.stdout);
}

export const behavior = {
  type: "@nckrtl/orbit-stage",
  version: "2026.10.22.1",
  globalArguments: GlobalArgsSchema,
  resources: {
    claim: {
      description: "Which workflow run and process work on a key",
      schema: z.object({ key: z.string(), stage: z.string(), runId: z.string(), pid: z.number(), host: z.string(), startedAt: z.string() }),
      lifetime: "30d",
      garbageCollection: 10,
    },
    committed: {
      description: "The commit that carries a key's Swamp-Idempotency-Key trailer, if any",
      schema: z.object({ key: z.string(), commit: z.string().nullable() }),
      lifetime: "30d",
      garbageCollection: 10,
    },
    prompt: {
      description: "Prompt (and for review, a prompt file and diff) for one agent step",
      schema: z.object({ key: z.string(), text: z.string(), promptFile: z.string().optional(), diff: z.string().optional() }),
      lifetime: "30d",
      garbageCollection: 10,
    },
    report: {
      description: "What a stage run recorded on the factory",
      schema: z.object({ key: z.string(), runId: z.string(), status: z.string(), artifacts: z.array(z.string()), skipped: z.boolean() }),
      lifetime: "30d",
      garbageCollection: 10,
    },
  },
  methods: {
    claim: {
      description: "Record that this workflow run (and its process) works on the key",
      arguments: StageArgs.pick({ workItem: true, stage: true, key: true }).extend({ runId: z.string() }),
      execute: async (a: { key: string; stage: string; runId: string }, ctx: Ctx) => {
        const h = await ctx.writeResource("claim", `claim-${a.key}`, {
          key: a.key, stage: a.stage, runId: a.runId, pid: Deno.pid, host: Deno.hostname(), startedAt: new Date().toISOString(),
        });
        return { dataHandles: [h] };
      },
    },

    committed: {
      description: "Find the commit that already carries this key (guards skip work when it exists)",
      arguments: StageArgs,
      execute: async (a: StageArgsT, ctx: Ctx) => {
        const h = await ctx.writeResource("committed", `committed-${a.key}`, { key: a.key, commit: await committedFor(ctx, a) });
        return { dataHandles: [h] };
      },
    },

    discard: {
      description: "Drop uncommitted changes a killed attempt of this key left in the sub-project (@swamp/git has no reset)",
      arguments: StageArgs,
      execute: async (a: StageArgsT, _ctx: Ctx) => {
        await git(a.worktree, ["reset", "-q", "--hard", "HEAD"]);
        await git(a.worktree, ["clean", "-qfd", "--", a.subProject]);
        return { dataHandles: [] };
      },
    },

    prompt: {
      description: "Build the agent prompt for implement, consult or review from the factory's records",
      arguments: StageArgs,
      execute: async (a: StageArgsT, ctx: Ctx) => {
        let text = "";
        let promptFile: string | undefined;
        let diff: string | undefined;
        if (a.stage === "implement") {
          const c = implementContext(
            await envelope(ctx, a.workItem, "handoff-result"),
            await envelope(ctx, a.workItem, "code-review"),
            await envelope(ctx, a.workItem, "consult-answer"),
          );
          const files = String((await gitWt(ctx, a, "diff", { base: a.planCommit, head: "HEAD", nameOnly: true, paths: [a.subProject] })).raw);
          const findings = ((c.findings?.findings ?? []) as { id: string; severity: string; description: string; resolved?: boolean }[])
            .filter((f) => !f.resolved).map((f) => `- ${f.id} (${f.severity}): ${f.description}`).join("\n");
          text = [
            `You are working in a git worktree of the Orbit monorepo. The sub-project ${a.subProject} just had its`,
            "Composer dependencies updated within their declared constraints. Make its checks and tests pass again:",
            `\`composer check\` and \`composer test\` inside ${a.subProject}.`,
            "",
            "Rules:",
            `- Change only files under ${a.subProject}. Do not change version constraints in composer.json.`,
            "  Do not run composer update. Do not commit, push or open PRs.",
            "- Fix the root cause in application code or tests. No skipped tests, no baseline entries, no ignore rules.",
            "- If you cannot continue without a decision from a person, stop and make the last line of your reply:",
            "  BLOCKED: <one question>",
            "- Otherwise make the last line of your reply: DONE: <one-line summary>",
            "",
            "Files changed by the update so far:",
            files.trim(),
            c.feedback ? `\nThe last handoff check failed (exit ${c.feedback.exitCode}). Output tail:\n${c.feedback.outputTail}` : "",
            findings ? `\nOpen review findings to fix:\n${findings}` : "",
            c.consult ? `\nA reviewer answered your earlier question:\nQ: ${c.consult.question}\nA: ${c.consult.answer}` : "",
          ].join("\n");
        } else if (a.stage === "consult") {
          const cs = await envelope(ctx, a.workItem, "change-summary");
          text = [
            `You are the reviewer for a dependency update of ${a.subProject} in the Orbit monorepo.`,
            "The implementer stopped with this question:",
            "",
            String(cs?.payload.question ?? "No question recorded."),
            "",
            "Answer it so the implementer can continue without a person, if you can. If only a person can decide,",
            'say so. Answer with one JSON object only: {"answer": "<text>", "needsPerson": true|false}',
          ].join("\n");
        } else if (a.stage === "review") {
          const mech = await envelope(ctx, a.workItem, "mechanical-change");
          const prev = await envelope(ctx, a.workItem, "code-review");
          const moved = ((mech?.payload.packages ?? []) as { name: string; from: string | null; to: string }[])
            .map((p) => `${p.name} ${p.from ?? "(new)"} -> ${p.to}`).join("\n");
          const src = String((await gitWt(ctx, a, "diff", { base: a.planCommit, head: "HEAD", paths: [a.subProject, `:(exclude)${a.subProject}/composer.lock`] })).raw);
          const head = (await git(a.worktree, ["rev-parse", "HEAD"])).trim();
          diff = `Locked package changes:\n${moved}\n\nSource diff (composer.lock excluded):\n${src || "(none)"}`;
          const previous = ((prev?.payload.findings ?? []) as { id: string; severity: string; description: string }[])
            .map((f) => `- ${f.id} (${f.severity}): ${f.description}`).join("\n");
          text = [
            `Review a dependency update in the Orbit monorepo, sub-project ${a.subProject}, commits`,
            `${a.planCommit.slice(0, 9)}..${head.slice(0, 9)}. The handoff check (composer check + composer test) passed.`,
            "Look for: breaking changes in the updated packages that tests may not cover, unsafe code changes,",
            "skipped or weakened tests, constraint edits, and unexpected downgrades. Do not edit files.",
            previous ? `\nFindings from the previous round; check each again:\n${previous}` : "",
          ].join("\n");
          promptFile = `${ctx.globalArgs.scratchRoot}/prompts/${a.key}.md`;
          await Deno.mkdir(`${ctx.globalArgs.scratchRoot}/prompts`, { recursive: true });
          await Deno.writeTextFile(promptFile, text);
        }
        const h = await ctx.writeResource("prompt", `prompt-${a.key}`, { key: a.key, text, ...(promptFile ? { promptFile } : {}), ...(diff ? { diff } : {}) });
        return { dataHandles: [h] };
      },
    },

    report: {
      description: "Record the stage's artifacts and resultEvidence on the factory, with this workflow run's id",
      arguments: StageArgs.extend({ runId: z.string() }),
      execute: async (a: StageArgsT & { runId: string }, ctx: Ctx) => {
        const f = ctx.globalArgs.factory;
        const cycle = cycleOf(a.key);
        const evName = RESULT_EVIDENCE[a.stage];
        const done = await one(ctx, `modelName == "${f}" && name == "evidence-${a.workItem}-${evName}"`);
        const de = done?.attributes as { stageId?: string; cycle?: number } | undefined;
        if (de && de.stageId === a.stage && de.cycle === cycle) {
          const h = await ctx.writeResource("report", `report-${a.key}`, { key: a.key, runId: a.runId, status: "already-recorded", artifacts: [], skipped: true });
          return { dataHandles: [h] };
        }

        // One Composer definition per work item (composer-<workItem>) avoids the
        // per-definition lock; runs from before that change used the shared one.
        const comp = async (n: string) =>
          (await one(ctx, `modelName == "composer-${a.workItem}" && name == "${n}"`)) ??
            (await one(ctx, `modelName == "${ctx.globalArgs.composer}" && name == "${n}"`));
        const artifacts: Record<string, Record<string, unknown>> = {};
        const evidence: Record<string, unknown> = { status: "failed", runId: a.runId };

        if (a.stage === "mechanical") {
          const up = (await comp(`update-${a.key}`))?.attributes;
          const commit = await committedFor(ctx, a);
          if (up?.passed) {
            evidence.status = "succeeded";
            artifacts["mechanical-change"] = {
              subProject: a.subProject,
              ...(commit ? { commit } : {}),
              lockfileChanged: commit !== null,
              packages: ((up.packages ?? []) as { name: string; from: string | null; to: string | null }[])
                .map((p) => ({ name: p.name, from: p.from, to: p.to ?? "removed" })),
            };
          }
        } else if (a.stage === "handoff") {
          const check = (await comp(`script-${a.key}-check`))?.attributes;
          const test = (await comp(`script-${a.key}-test`))?.attributes;
          const failed = !check?.passed || !test?.passed;
          const head = (await git(a.worktree, ["rev-parse", "HEAD"])).trim();
          const diffstat = (await git(a.worktree, ["diff", "--shortstat", a.planCommit, "HEAD", "--", a.subProject])).trim();
          const prev = await envelope(ctx, a.workItem, "handoff-result");
          const last = !check?.passed ? check : test;
          evidence.status = failed ? "failed" : "succeeded";
          artifacts["handoff-result"] = {
            status: evidence.status,
            exitCode: Number(last?.exitCode ?? 1),
            commit: head,
            diffstat,
            consecutiveFailures: nextStreak(prev, failed),
            outputTail: String(last?.outputTail ?? "no check output recorded"),
          };
          if (!failed) artifacts["candidate"] = { commit: head, diffstat };
        } else if (a.stage === "implement") {
          const run = ((await one(ctx, `modelName == "agent-codex-${a.workItem}" && name == "run-${a.key}"`)) ??
            (await one(ctx, `modelName == "agent-codex" && name == "run-${a.key}"`)))?.attributes;
          const commit = await committedFor(ctx, a);
          const o = run ? implementOutcome(String(run.output)) : { outcome: "error" as const, text: "no agent output" };
          const outcome = run && Number(run.exitCode) !== 0 ? "error" : o.outcome;
          evidence.status = outcome === "error" ? "failed" : "succeeded";
          evidence.outcome = outcome;
          artifacts["change-summary"] = {
            outcome,
            ...(commit ? { commit } : {}),
            ...(outcome === "blocked" ? { question: o.text } : { summary: o.text }),
          };
        } else if (a.stage === "consult") {
          const run = ((await ctx.queryData(`modelType == "@swamp/agent-runner" && specName == "runResult" && workflowRunId == "${a.runId}"`)) as Rec[])[0]?.attributes;
          const j = run ? firstJson(String(run.output)) : null;
          const q = (await envelope(ctx, a.workItem, "change-summary"))?.payload.question;
          if (j && typeof j.answer === "string") {
            evidence.status = "succeeded";
            artifacts["consult-answer"] = { question: String(q ?? ""), answer: j.answer, needsPerson: j.needsPerson === true };
          }
        } else if (a.stage === "review") {
          const rv = ((await ctx.queryData(`modelType == "@swamp/agent-runner" && specName == "reviewResult" && workflowRunId == "${a.runId}"`)) as Rec[])[0]?.attributes;
          // agent-runner reports an agent that wrote no result as a critical "N/A"
          // finding; that is a failed review run (retry), not a finding to rework.
          const noResult = ((rv?.findings ?? []) as { file?: string; description?: string }[])
            .some((f) => f.file === "N/A" && /did not produce a structured result/i.test(String(f.description)));
          if (rv && !noResult) {
            evidence.status = "succeeded";
            artifacts["code-review"] = { summary: String(rv.body ?? ""), findings: toFactoryFindings((rv.findings ?? []) as never[]) };
          }
        }

        for (const [name, payload] of Object.entries(artifacts)) {
          await call(ctx, { definition: f, method: "record_artifact", arguments: { workItem: a.workItem, name, payload } });
        }
        await call(ctx, { definition: f, method: "record_evidence", arguments: { workItem: a.workItem, name: evName, payload: evidence } });
        ctx.logger.info("{key}: recorded {n} artifacts, {ev} {status}", { key: a.key, n: Object.keys(artifacts).length, ev: evName, status: evidence.status });
        const h = await ctx.writeResource("report", `report-${a.key}`, {
          key: a.key, runId: a.runId, status: String(evidence.status), artifacts: Object.keys(artifacts), skipped: false,
        });
        return { dataHandles: [h] };
      },
    },
  },
};
