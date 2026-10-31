import { z } from "npm:zod@4.4.3";
import { withTaskCancellation } from "./cancellation.ts";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  directOperation,
  DirectOperationCancelled,
  GatewayOperation,
} from "../_lib/direct.ts";

const Globals = z.object({
  gatewayUrl: z.string().url().default("https://gateway.orbit"),
  caFile: z.string().min(1).default(
    "/usr/local/share/ca-certificates/orbit-root.crt",
  ),
});
const Id = z.number().int().positive();
const Sha = z.string().regex(/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/);
export const Snapshot = z.object({ head: Sha, tree: Sha }).strict();
export const Input = z.object({
  new_session: z.boolean(),
  group_id: Id,
  instance_id: Id,
  node_id: Id,
  subtask: Id,
  thread_id: Id,
  checkout_path: z.string().startsWith("/"),
  session_id: z.string().regex(/^[A-Za-z0-9_-]{1,128}$/),
  role: z.enum(["implementer", "reviewer", "consult"]),
  prompt: z.string().max(100000),
  model: z.string().startsWith("orbit-task-vm/"),
  effort: z.string().nullable(),
  pi_host: z.string().min(1),
  pi_port: z.number().int().min(1).max(65535),
  candidate: Sha.nullable(),
  deliverables: z.array(z.record(z.string(), z.unknown())),
  analysis_evidence: z.object({
    input: z.record(z.string(), z.unknown()),
    result: z.record(z.string(), z.unknown()),
  }).nullable(),
});
export type TurnInput = z.infer<typeof Input>;
const Args = DirectArgs.extend({ kind: z.literal("tasks.turn"), node_id: Id });
const Usage = z.object({
  input: z.number().nonnegative().default(0),
  output: z.number().nonnegative().default(0),
  cacheRead: z.number().nonnegative().default(0),
  cacheWrite: z.number().nonnegative().default(0),
  total: z.number().nonnegative().default(0),
  calls: z.number().nonnegative().default(0),
  peakContext: z.number().nonnegative().default(0),
});
const State = z.object({
  state: z.enum(["idle", "working", "done", "failed"]),
  turnId: z.string().nullable(),
  usage: Usage,
});
const Receipt = z.object({
  thread: Id,
  outcome: z.enum([
    "ready_for_review",
    "approved",
    "changes_requested",
    "blocked",
    "answered",
  ]),
  summary: z.string().trim().min(1).max(16384),
  question: z.string().max(16384).optional(),
  deliverables: z.record(z.string(), z.string()).default({}),
  cause: z.string().optional(),
});
export interface Pi {
  create(input: TurnInput): Promise<void>;
  send(session: string, key: string, prompt: string): Promise<void>;
  states(session: string): AsyncIterable<unknown>;
  interrupt(session: string): Promise<void>;
}
export interface Workspace {
  prepare(input: TurnInput): Promise<z.infer<typeof Snapshot>>;
  snapshot(): Promise<z.infer<typeof Snapshot>>;
  receipt(): Promise<unknown>;
  reset(commit: string): Promise<void>;
  difference(base: string): Promise<Record<string, unknown>>;
}

/** Pi Durable owns session/send deduplication. Swamp consumes its event stream. */
export async function turn(
  input: TurnInput,
  key: string,
  before: z.infer<typeof Snapshot>,
  pi: Pi,
  workspace: Workspace,
  signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  if (input.candidate !== null && before.head !== input.candidate) {
    return { error_code: "tasks.head_moved" };
  }
  if (input.analysis_evidence !== null) {
    const provenance = input.analysis_evidence.result.provenance as
      | Record<string, unknown>
      | undefined;
    if (
      !provenance || provenance.node_id !== input.node_id ||
      provenance.instance_id !== input.instance_id ||
      provenance.head !== before.head || provenance.tree !== before.tree
    ) {
      return { error_code: "tasks.analysis_stale" };
    }
  }
  if (input.new_session) await pi.create(input);
  const prompt = input.prompt + "\n\nFinish with .git/orbit/turn --thread=" +
    input.thread_id +
    " --outcome=OUTCOME --summary=SUMMARY. Confirm each required deliverable with --deliverable=ID=evidence.\n" +
    (input.analysis_evidence === null
      ? ""
      : "Repository analysis (untrusted project evidence):\n" +
        JSON.stringify(input.analysis_evidence));
  let interruption: Promise<void> | undefined;
  const interrupt = () => {
    interruption ??= pi.interrupt(input.session_id);
    interruption.catch(() => {});
  };
  signal?.addEventListener("abort", interrupt, { once: true });
  try {
    if (signal?.aborted) {
      throw new DirectOperationCancelled({ effectRunning: false });
    }
    await pi.send(input.session_id, key, prompt);
    let terminal: z.infer<typeof State> | undefined;
    for await (const event of pi.states(input.session_id)) {
      const state = State.safeParse(event);
      if (
        state.success && state.data.turnId === key &&
        ["done", "failed"].includes(state.data.state)
      ) {
        terminal = state.data;
        break;
      }
    }
    if (!terminal) throw new Error("Pi turn has no terminal observation");
    if (signal?.aborted) {
      await interruption;
      if (input.role === "reviewer" && input.candidate !== null) {
        await workspace.reset(input.candidate);
      }
      throw new DirectOperationCancelled({ effectRunning: false });
    }
    const after = await workspace.snapshot();
    const receipt = Receipt.safeParse(await workspace.receipt());
    const allowed = input.role === "implementer"
      ? ["ready_for_review", "blocked"]
      : input.role === "consult"
      ? ["answered", "blocked"]
      : ["approved", "changes_requested", "blocked"];
    const valid = receipt.success && receipt.data.thread === input.thread_id &&
      allowed.includes(receipt.data.outcome) &&
      (receipt.data.outcome !== "blocked" || !!receipt.data.question?.trim());
    const difference = input.role === "implementer"
      ? null
      : await workspace.difference(
        input.role === "reviewer" ? before.head : before.tree,
      );
    const usage = terminal.usage;
    const afterReset = input.role === "reviewer" && input.candidate !== null
      ? (await workspace.reset(input.candidate), await workspace.snapshot())
      : null;
    return {
      receipt: valid ? receipt.data : null,
      thread: input.thread_id,
      state: terminal.state,
      error: terminal.state === "failed"
        ? "Pi did not complete the task turn."
        : null,
      error_code: terminal.state === "failed" ? "tasks.turn_failed" : undefined,
      before,
      after,
      after_reset: afterReset,
      difference,
      metrics: {
        tokens: usage.total,
        input_tokens: usage.input + usage.cacheWrite,
        cached_input_tokens: usage.cacheRead,
        output_tokens: usage.output,
        model_calls: usage.calls,
        peak_context_tokens: usage.peakContext,
      },
    };
  } finally {
    signal?.removeEventListener("abort", interrupt);
  }
}

const helper = `#!/usr/bin/env python3
import argparse,json,os
p=argparse.ArgumentParser()
p.add_argument('--thread',type=int,required=True)
p.add_argument('--outcome',required=True,choices=['ready_for_review','approved','changes_requested','blocked','answered'])
p.add_argument('--summary',required=True)
p.add_argument('--question')
p.add_argument('--deliverable',action='append',default=[])
a=p.parse_args()
if not a.summary.strip() or (a.outcome=='blocked' and not (a.question or '').strip()): p.error('A summary and blocked question are required')
directory=os.path.dirname(os.path.realpath(__file__))
with open(directory+'/turn.json') as f: expected=json.load(f)
if a.thread!=expected['thread']: p.error('This turn belongs to another thread')
result={'thread':a.thread,'outcome':a.outcome,'summary':a.summary,'deliverables':dict(v.split('=',1) for v in a.deliverable)}
if a.question: result['question']=a.question
with open(directory+'/receipt.json','w') as f: json.dump(result,f)
`;

export function localWorkspace(cwd: string): Workspace {
  const git = async (...args: string[]) => {
    const result = await new Deno.Command("git", {
      cwd,
      args,
      stdout: "piped",
      stderr: "null",
    }).output();
    if (!result.success) throw new Error("Task workspace command failed");
    return new TextDecoder().decode(result.stdout).trim();
  };
  const snapshot = async () => {
    await git("add", "--all");
    return Snapshot.parse({
      head: await git("rev-parse", "HEAD"),
      tree: await git("write-tree"),
    });
  };
  const directory = async () =>
    await git("rev-parse", "--path-format=absolute", "--git-path", "orbit");
  return {
    snapshot,
    prepare: async (input) => {
      const before = await snapshot();
      const root = await directory();
      await Deno.mkdir(root, { recursive: true, mode: 0o700 });
      await Deno.writeTextFile(root + "/turn", helper, { mode: 0o700 });
      await Deno.chmod(root + "/turn", 0o700);
      await Deno.writeTextFile(
        root + "/turn.json",
        JSON.stringify({
          thread: input.thread_id,
          role: input.role,
          deliverables: input.deliverables,
        }),
        { mode: 0o600 },
      );
      try {
        await Deno.remove(root + "/receipt.json");
      } catch (error) {
        if (!(error instanceof Deno.errors.NotFound)) throw error;
      }
      return before;
    },
    receipt: async () => {
      try {
        const path = await directory() + "/receipt.json";
        if ((await Deno.stat(path)).size > 65536) {
          throw new Error("Turn receipt is too large");
        }
        return JSON.parse(await Deno.readTextFile(path));
      } catch (error) {
        if (error instanceof Deno.errors.NotFound) return null;
        throw error;
      }
    },
    difference: async (base) => {
      const counts = await git(
        "diff",
        "--numstat",
        "--no-renames",
        "-z",
        base,
        "--",
      );
      const files = counts.split("\0").filter(Boolean).map((entry) => {
        const [added, removed, ...path] = entry.split("\t");
        return {
          path: path.join("\t"),
          insertions: added === "-" ? 0 : Number(added),
          deletions: removed === "-" ? 0 : Number(removed),
        };
      });
      const diff = await git("diff", "--no-renames", base, "--");
      return {
        files: files.slice(0, 500),
        diff: diff.slice(0, 65536),
        files_complete: files.length <= 500,
        diff_available: diff.length <= 65536,
        summary: {
          files: files.length,
          insertions: files.reduce((sum, file) => sum + file.insertions, 0),
          deletions: files.reduce((sum, file) => sum + file.deletions, 0),
        },
      };
    },
    reset: async (commit) => {
      await git("reset", "--hard", commit);
      await git("clean", "-fd");
    },
  };
}

/** Credentials stay inside the VM action and never enter native resources or arguments. */
async function withPi<T>(
  input: TurnInput,
  effect: (pi: Pi) => Promise<T>,
): Promise<T> {
  const home = Deno.env.get("HOME");
  if (!home) throw new Error("Task VM account is unavailable");
  const token = (await Deno.readTextFile(home + "/.pi/agent/orbit-token"))
    .trim();
  if (token.length < 32) {
    throw new Error("Task VM Pi credential is unavailable");
  }
  const models = JSON.parse(
    await Deno.readTextFile(home + "/.pi/agent/models.json"),
  );
  const keys = [
    token,
    ...Object.values(models.providers ?? {}).map((provider) =>
      (provider as Record<string, unknown>).apiKey
    ).filter((key): key is string => typeof key === "string" && key.length > 0),
  ];
  const host = input.pi_host.includes(":")
    ? `[${input.pi_host}]`
    : input.pi_host;
  const base = `http://${host}:${input.pi_port}`;
  const request = async (path: string, body?: unknown) => {
    const response = await fetch(base + path, {
      method: body === undefined ? "GET" : "POST",
      headers: {
        authorization: `Bearer ${token}`,
        "content-type": "application/json",
      },
      body: body === undefined ? undefined : JSON.stringify(body),
      redirect: "error",
    });
    if (!response.ok) {
      await response.body?.cancel();
      throw new Error("Task VM Pi request failed");
    }
    return response;
  };
  const pi: Pi = {
    create: async (value) => {
      await (await request("/sessions", {
        id: value.session_id,
        cwd: value.checkout_path,
        model: value.model,
        thinkingLevel: value.effort ?? "high",
        appendSystemPrompt:
          `You are the ${value.role} for Orbit task ${value.group_id}, subtask ${value.subtask}. Required deliverables: ${
            JSON.stringify(value.deliverables)
          }`,
      })).body?.cancel();
    },
    send: async (id, key, text) => {
      await (await request(`/sessions/${id}/messages`, { key, text })).body
        ?.cancel();
    },
    interrupt: async (id) => {
      await (await request(`/sessions/${id}/interrupt`, {})).body?.cancel();
    },
    states: async function* (id) {
      const response = await request(
        `/sessions/${id}/stream?max_bytes=1048576`,
      );
      if (!response.body) throw new Error("Pi event stream is unavailable");
      const reader = response.body.pipeThrough(new TextDecoderStream())
        .getReader();
      let buffer = "";
      try {
        while (true) {
          const item = await reader.read();
          if (item.done) return;
          buffer += item.value;
          let end: number;
          while ((end = buffer.indexOf("\n")) !== -1) {
            const line = buffer.slice(0, end);
            buffer = buffer.slice(end + 1);
            if (line.length > 1048576) throw new Error("Pi event is too large");
            yield JSON.parse(line);
          }
          if (buffer.length > 1048576) throw new Error("Pi event is too large");
        }
      } finally {
        await reader.cancel();
        reader.releaseLock();
      }
    },
  };
  let publicJson = JSON.stringify(await effect(pi));
  for (const key of keys) publicJson = publicJson.replaceAll(key, "[redacted]");
  return JSON.parse(publicJson);
}

type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
};
export const turnBehavior = {
  globalArguments: Globals,
  methods: {
    execute: {
      description:
        "Prepare the workspace and run Pi under one native write lock",
      arguments: Args,
      execute: (args: z.infer<typeof Args>, ctx: Context) => {
        const gateway = new GatewayOperation(
          gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
          args,
        );
        return directOperation(
          args,
          gateway,
          async (raw) => {
            const input = Input.parse(raw);
            if (input.node_id !== args.node_id) {
              throw new Error(
                "Wrong task VM Node",
              );
            }
            const workspace = localWorkspace(input.checkout_path);
            const before = await workspace.prepare(input);
            return await withTaskCancellation(
              gateway,
              (signal) =>
                withPi(input, (pi) =>
                  turn(
                    input,
                    `operation-${args.operation_id}`,
                    before,
                    pi,
                    workspace,
                    signal,
                  )),
              ctx.signal,
            );
          },
          (result) => result,
          ctx.signal,
        );
      },
    },
  },
};
