import { z } from "npm:zod@4.4.3";
import { globToRegExp } from "jsr:@std/path@1/glob-to-regexp";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  directOperation,
  DirectOperationCancelled,
  GatewayOperation,
  Globals,
  type Result,
} from "../_lib/direct.ts";
import { type ScriptExecutor, workspaceProgram } from "./script.ts";
import { CheckProgram } from "./check_program.ts";
import { withTaskCancellation } from "./cancellation.ts";

const Id = z.number().int().positive();
const Sha = z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/);
const Deliverable = z.object({
  id: z.string(),
  type: z.enum(["file", "command", "review"]),
  path: z.string().default(""),
  change: z.string().default("any"),
  command: z.string().default(""),
  directory: z.string().default("."),
  fails_on_base: z.boolean().default(false),
  paths: z.array(z.string()).default([]),
});
export const CheckInput = z.object({
  group_id: Id,
  instance_id: Id,
  node_id: Id,
  subtask: Id,
  check_id: Id,
  checkout_path: z.string().startsWith("/"),
  ssr_port: z.number().int().min(1).max(65535).nullable(),
  phase: z.enum(["baseline", "handoff"]),
  command: z.string().nullable(),
  setup: z.array(
    z.object({
      name: z.string(),
      command: z.string(),
      timeout_seconds: z.number().int().positive(),
    }),
  ),
  deliverables: z.array(Deliverable),
  base: z.string(),
  message: z.string(),
  repository: z.string().nullable(),
  check_name: z.string().nullable(),
  branch: z.string().regex(/^task-[1-9][0-9]*-handoff$/),
});
const Evidence = z.object({
  start: z.string().nullable(),
  diff: z.array(z.object({ path: z.string(), status: z.string() })).nullable(),
  commands: z.record(
    z.string(),
    z.object({
      exit_code: z.number().int(),
      output: z.string(),
      base_started: z.boolean().optional(),
      base_exit_code: z.number().int().optional(),
      base_timed_out: z.boolean().optional(),
    }).passthrough(),
  ),
});
const Local = z.object({
  outcome: z.enum(["passed", "failed", "changed"]),
  exit_code: z.number().int(),
  output: z.string(),
  head_before: Sha,
  tree_before: Sha,
  head: Sha,
  tree: Sha,
  changed_paths: z.array(z.string()),
  failed_step: z.string().nullable(),
  evidence: Evidence.nullable(),
  subtask_start_commit: Sha,
});
const ErrorResult = z.object({ error_code: z.string().regex(/^[a-z0-9_.]+$/) });
export type CheckIntent = z.infer<typeof CheckInput>;
export type LocalCheck = z.infer<typeof Local>;
const invokeLocal: ScriptExecutor = (input, signal) =>
  workspaceProgram(CheckProgram, input, signal);

export function deliverableResults(input: CheckIntent, local: LocalCheck) {
  return input.deliverables.map((item) => {
    const failures: string[] = [];
    const checked = input.phase === "handoff" && item.type !== "review";
    if (checked && item.type === "file") {
      const pattern = globToRegExp(item.path.replace(/^(?:\.\/|\/)+/, ""), {
        globstar: true,
        extended: true,
      });
      const wanted = item.change === "created"
        ? ["A"]
        : item.change === "modified"
        ? ["M"]
        : ["A", "M"];
      if (
        !local.evidence?.diff?.some((row) =>
          pattern.test(row.path) && wanted.includes(row.status)
        )
      ) {
        failures.push(
          `${item.id}: the checked diff does not contain the required file change.`,
        );
      }
    } else if (checked) {
      const run = local.evidence?.commands[item.id];
      if (!run || run.exit_code !== 0) {
        failures.push(`${item.id}: the required command did not pass.`);
      }
      if (
        item.fails_on_base &&
        (!run?.base_started || run.base_exit_code === undefined ||
          [0, 126, 127].includes(run.base_exit_code))
      ) {
        failures.push(
          `${item.id}: the command did not reproduce the failure on the start commit.`,
        );
      }
    }
    return {
      id: item.id,
      outcome: !checked ? "not_checked" : failures.length ? "failed" : "passed",
      failures,
    };
  });
}

type CiRead = {
  state: "passed" | "pending" | "missing" | "failed";
  fault: "change" | "configuration" | "infrastructure" | null;
  runs: unknown[];
  suites: unknown[];
};
export function classifyCi(
  runs: unknown[],
  suites: unknown[],
  commit: string,
  name: string,
): CiRead {
  const rows = runs.map((row) =>
    z.object({
      id: Id,
      name: z.string(),
      head_sha: z.string(),
      status: z.string(),
      conclusion: z.string().nullable(),
      app: z.object({ slug: z.string() }),
    }).parse(row)
  );
  const named = rows.filter((row) => row.name === name);
  const safeRuns = rows.map((row) => ({
    id: row.id,
    name: row.name.slice(0, 256),
    head_sha: row.head_sha,
    status: row.status,
    conclusion: row.conclusion,
    app_slug: row.app.slug,
  }));
  const suiteRows = suites.map((row) =>
    z.object({
      id: Id,
      status: z.string(),
      conclusion: z.string().nullable(),
      app: z.object({ slug: z.string() }),
    }).parse(row)
  ).filter((row) => row.app.slug === "github-actions");
  const safeSuites = suiteRows.map((row) => ({
    id: row.id,
    status: row.status,
    conclusion: row.conclusion,
    app_slug: row.app.slug,
  }));
  const reply = (state: CiRead["state"], fault: CiRead["fault"]): CiRead => ({
    state,
    fault,
    runs: safeRuns,
    suites: safeSuites,
  });
  const runFault = (): CiRead["fault"] =>
    rows.some((row) =>
        row.app.slug === "github-actions" && row.head_sha === commit &&
        ["failure", "timed_out", "action_required"].includes(
          row.conclusion ?? "",
        )
      )
      ? "change"
      : rows.some((row) =>
          ["cancelled", "startup_failure"].includes(row.conclusion ?? "")
        )
      ? "infrastructure"
      : "configuration";
  const suiteFault = (): CiRead["fault"] =>
    suiteRows.some((row) =>
        ["failure", "startup_failure"].includes(row.conclusion ?? "")
      )
      ? "change"
      : suiteRows.some((row) => row.conclusion === "cancelled")
      ? "infrastructure"
      : "configuration";
  if (!named.length) {
    if (
      suiteRows.length && suiteRows.every((row) => row.status === "completed")
    ) return reply("failed", suiteFault());
    return reply("missing", null);
  }
  if (
    named.some((row) =>
      row.app.slug !== "github-actions" || row.head_sha !== commit
    )
  ) return reply("failed", "configuration");
  if (named.some((row) => row.status !== "completed")) {
    return reply("pending", null);
  }
  if (named.every((row) => row.conclusion === "success")) {
    return reply("passed", null);
  }
  return reply("failed", runFault());
}

export async function check(
  input: CheckIntent,
  operationId: number,
  invoke: ScriptExecutor = invokeLocal,
  signal?: AbortSignal,
): Promise<Result> {
  const call = (mode: string, extra: Result = {}) =>
    invoke({
      ...input,
      execution_key: `operation-${operationId}`,
      mode,
      ...extra,
    }, mode === "commit" ? undefined : signal);
  const raw = await call("run");
  if (z.object({ cancelled: z.literal(true) }).safeParse(raw).success) {
    throw new DirectOperationCancelled({ effectRunning: false });
  }
  if (ErrorResult.safeParse(raw).success) return ErrorResult.parse(raw);
  const local = Local.parse(raw);
  const deliverables = deliverableResults(input, local);
  const result: Result = {
    ...local,
    deliverables,
    check_id: input.check_id,
    commit: null,
    ci: null,
  };
  if (deliverables.some((row) => row.outcome === "failed")) {
    result.outcome = "failed";
  }
  if (result.outcome !== "passed" || input.phase !== "handoff") return result;
  if (signal?.aborted) {
    throw new DirectOperationCancelled({ effectRunning: false });
  }
  const committed = await call("commit", {
    head: local.head,
    tree: local.tree,
    message: input.message,
  });
  if (z.object({ changed: z.literal(true) }).safeParse(committed).success) {
    return { ...result, outcome: "changed" };
  }
  if (ErrorResult.safeParse(committed).success) {
    return ErrorResult.parse(committed);
  }
  const candidate = z.object({ commit: Sha, tree: Sha }).parse(committed);
  if (candidate.tree !== local.tree) return { ...result, outcome: "changed" };
  return { ...result, commit: candidate.commit };
}

/** Existing repository App token type; tokens stay inside trusted Git/GitHub calls. */
export async function withRepositoryTokens<T>(
  gateway: Pick<GatewayOperation, "withPrivateMaterial">,
  repository: string,
  action: (
    tokens: { write_token: string; checks_token?: string; read_token?: string },
  ) => Promise<T>,
  request = fetch,
): Promise<T> {
  let result: T | undefined;
  let cancelled = false;
  await gateway.withPrivateMaterial(async (material) => {
    const token = z.string().regex(/^[A-Za-z0-9_-]+$/);
    const tokens = z.object({
      repository: z.literal(repository),
      write_token: token,
      checks_token: token.optional(),
      read_token: token.optional(),
    }).parse(material.credentials);
    const secrets = [tokens.write_token, tokens.checks_token, tokens.read_token]
      .filter((secret): secret is string => typeof secret === "string");
    try {
      result = await action(tokens);
      if (
        secrets.some((
          secret,
        ) => JSON.stringify(result).includes(secret))
      ) throw new Error("Task result contains a private credential");
    } catch (error) {
      if (error instanceof DirectOperationCancelled) cancelled = true;
      else throw error;
    } finally {
      for (
        const secret of new Set(secrets)
      ) {
        try {
          await (await request("https://api.github.com/installation/token", {
            method: "DELETE",
            redirect: "error",
            signal: AbortSignal.timeout(10000),
            headers: {
              Authorization: `Bearer ${secret}`,
              Accept: "application/vnd.github+json",
            },
          })).body?.cancel();
        } catch {
          console.warn("operation.github_token_revoke_failed");
        }
      }
    }
  });
  if (cancelled) throw new DirectOperationCancelled({ effectRunning: false });
  return result!;
}

async function github(
  path: string,
  token: string,
  signal?: AbortSignal,
  request: typeof fetch = fetch,
): Promise<Record<string, unknown>> {
  const response = await request(`https://api.github.com${path}`, {
    redirect: "error",
    signal: signal
      ? AbortSignal.any([signal, AbortSignal.timeout(30000)])
      : AbortSignal.timeout(30000),
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/vnd.github+json",
      "X-GitHub-Api-Version": "2022-11-28",
    },
  });
  if (!response.ok) {
    await response.body?.cancel();
    throw new Error("GitHub check observation unavailable");
  }
  return z.record(z.string(), z.unknown()).parse(await response.json());
}
function waitForCi(ms: number, signal: AbortSignal): Promise<void> {
  return new Promise((resolve) => {
    const done = () => {
      clearTimeout(timer);
      signal.removeEventListener("abort", done);
      resolve();
    };
    const timer = setTimeout(done, ms);
    signal.addEventListener("abort", done, { once: true });
    if (signal.aborted) done();
  });
}
export async function hosted(
  input: CheckIntent,
  operationId: number,
  result: Result,
  gateway: Pick<GatewayOperation, "withPrivateMaterial" | "read">,
  signal: AbortSignal,
  runtime = {
    invoke: invokeLocal,
    request: fetch,
    now: Date.now,
    wait: waitForCi,
  },
): Promise<Result> {
  if (
    result.outcome !== "passed" || input.check_name === null ||
    !input.repository
  ) return result;
  const commit = Sha.parse(result.commit);
  return await withRepositoryTokens(
    gateway,
    input.repository,
    async (tokens) => {
      const checksToken = z.string().min(1).parse(tokens.checks_token);
      if (signal.aborted) {
        throw new DirectOperationCancelled({
          effectRunning: false,
        });
      }
      // A Git push must finish or remain uncertain; cancellation cannot interrupt its client.
      const pushed = await runtime.invoke({
        ...input,
        execution_key: `operation-${operationId}`,
        mode: "push",
        commit,
        token: tokens.write_token,
      });
      if (
        !z.object({ pushed: z.literal(true) }).safeParse(pushed).success
      ) throw new Error("Handoff push outcome unknown");
      const pushed_at = new Date(runtime.now()).toISOString();
      const deadline = runtime.now() + 55 * 60 * 1000;
      let ci: Result = {};
      try {
        while (runtime.now() < deadline) {
          if (signal.aborted) {
            throw new DirectOperationCancelled({
              effectRunning: false,
            });
          }
          const runs: unknown[] = [], suites: unknown[] = [];
          for (let page = 1; page <= 20; page++) {
            const batch = z.array(z.unknown()).parse(
              (await github(
                `/repos/${input.repository}/commits/${commit}/check-runs?filter=latest&per_page=100&page=${page}`,
                checksToken,
                signal,
                runtime.request,
              )).check_runs,
            );
            runs.push(...batch);
            if (batch.length < 100) break;
            if (page === 20) throw new Error("Too many handoff check runs");
          }
          for (let page = 1; page <= 20; page++) {
            const batch = z.array(z.unknown()).parse(
              (await github(
                `/repos/${input.repository}/commits/${commit}/check-suites?per_page=100&page=${page}`,
                checksToken,
                signal,
                runtime.request,
              )).check_suites,
            );
            suites.push(...batch);
            if (batch.length < 100) break;
            if (page === 20) throw new Error("Too many handoff check suites");
          }
          const reading = classifyCi(runs, suites, commit, input.check_name!);
          ci = {
            ...reading,
            branch: input.branch,
            commit,
            check_name: input.check_name,
            pushed_at,
            observed_at: new Date(runtime.now()).toISOString(),
          };
          if (
            new TextEncoder().encode(JSON.stringify(ci)).length > 49152
          ) throw new Error("Handoff evidence exceeds its bound");
          if (reading.state === "passed" || reading.fault === "change") {
            const observed = z.object({ cancel_requested: z.boolean() }).parse(
              await gateway.read(),
            );
            if (
              signal.aborted || observed.cancel_requested
            ) throw new DirectOperationCancelled({ effectRunning: false });
            const current = z.object({ head: Sha, tree: Sha }).parse(
              await runtime.invoke({
                ...input,
                execution_key: `operation-${operationId}`,
                mode: "snapshot",
              }),
            );
            if (
              current.head !== commit || current.tree !== result.tree
            ) return { ...result, outcome: "changed", ci };
            return {
              ...result,
              outcome: reading.state === "passed" ? "passed" : "failed",
              failed_step: reading.fault === "change" ? "ci" : null,
              ci,
            };
          }
          if (
            reading.fault ||
            runtime.now() - Date.parse(pushed_at) >= 600000 &&
              reading.state === "missing"
          ) {
            return {
              ...result,
              error_code: "tasks.handoff_ci_unavailable",
              ci,
            };
          }
          await runtime.wait(10000, signal);
        }
        return { ...result, error_code: "tasks.handoff_ci_timeout", ci };
      } catch (error) {
        if (
          signal.aborted || error instanceof DirectOperationCancelled
        ) throw new DirectOperationCancelled({ effectRunning: false });
        return {
          ...result,
          error_code: "tasks.handoff_ci_unavailable",
          ci: {
            state: "unreadable",
            fault: "infrastructure",
            branch: input.branch,
            commit,
            check_name: input.check_name,
            pushed_at,
            observed_at: new Date(runtime.now()).toISOString(),
            runs: [],
            suites: [],
          },
        };
      }
    },
    runtime.request,
  );
}

const Args = DirectArgs.extend({ node_id: Id, kind: z.literal("tasks.check") });
export const checkMethod = {
  description:
    "Run setup, check, deliverables and exact-head CI in the owned task VM",
  arguments: Args,
  execute: (
    args: z.infer<typeof Args>,
    ctx: { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal },
  ) => {
    const gateway = new GatewayOperation(
      gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
      args,
    );
    return directOperation(
      args,
      gateway,
      async (raw) => {
        const input = CheckInput.parse(raw);
        if (input.node_id !== args.node_id) {
          throw new Error("Wrong task VM Node");
        }
        return await withTaskCancellation(gateway, async (signal) =>
          hosted(
            input,
            args.operation_id,
            await check(input, args.operation_id, invokeLocal, signal),
            gateway,
            signal,
          ), ctx.signal);
      },
      (result) => result,
      ctx.signal,
    );
  },
};
