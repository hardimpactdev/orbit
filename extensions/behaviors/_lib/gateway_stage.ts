// The stage work of an externally orchestrated Orbit task group (ADR 0229).
// Each call has ensure semantics for one driver key `<workItem>.<stage>.c<cycle>`:
//   - no job yet: plan the request, store it, start the Gateway operation with
//     an Idempotency-Key, and store the operation id;
//   - a job with an operation: read the operation once and map a terminal one
//     to the factory products (artifacts + resultEvidence).
// It never waits. The first call returns once the operation id is stored, and
// later calls read the operation only when its poll_after_seconds is due. The
// stored request is replayed unchanged, so a lost reply or a killed tick starts
// no second operation: the Gateway returns the same one. A call for the same key
// with other input is refused as a conflict.

export const STAGES = ["provision", "baseline", "implement", "handoff", "review", "publish", "merged"] as const;
export type Stage = (typeof STAGES)[number];

export interface HttpResponse {
  status: number;
  body: Record<string, unknown> | null;
}
export type Http = (
  method: "GET" | "POST" | "PATCH",
  path: string,
  body?: Record<string, unknown>,
  idempotencyKey?: string,
) => Promise<HttpResponse>;

export interface Envelope {
  payload: Record<string, unknown>;
  recordedAt: string;
}

export interface PlannedRequest {
  path: string;
  body: Record<string, unknown>;
  idempotencyKey: string;
}

export interface Job {
  key: string;
  workItem: string;
  stage: Stage;
  group: number;
  subtask: number | null;
  state: "running" | "succeeded" | "failed";
  launches: number;
  launchedNow: boolean;
  request: PlannedRequest | null;
  operationId: number | null;
  operationStatus: string | null;
  lastError: string | null;
  inputIdentity: string | null;
  nextReadAt: string | null;
  artifacts: Record<string, Record<string, unknown>>;
  evidence: Record<string, unknown> | null;
}

export interface StageArgs {
  workItem: string;
  stage: Stage;
  key: string;
  change?: unknown;
  handoff?: unknown;
  review?: unknown;
}

export interface Deps {
  http: Http;
  readJob: () => Promise<Job | null>;
  writeJob: (job: Job) => Promise<void>;
  now?: () => number;
}

interface TaskRow {
  id: number;
  position: number;
  title: string;
  brief: string;
  status: string;
  deliverables?: { id: string; type: string; description: string }[];
}
interface GroupRow {
  id: number;
  title: string;
  brief: string;
  status: string;
  watched_pr_state?: string | null;
  watched_pr_url?: string | null;
  pr_url?: string | null;
  tasks: TaskRow[];
}

const OPEN_SUBTASK = ["todo", "running", "reviewing"];
const TERMINAL_OPERATION = ["succeeded", "failed", "cancelled"];

/** The Orbit group id of a work item named `tg-<id>`. */
export function groupOf(workItem: string): number {
  const m = /^tg-(\d+)$/.exec(workItem);
  if (!m) throw new Error(`work item ${workItem} is not tg-<group id>`);
  return Number(m[1]);
}

/** Stage and cycle of a driver key `<workItem>.<stage>.c<cycle>`. */
export function keyParts(key: string): { workItem: string; stage: string; cycle: number } {
  const m = /^(.+)\.([a-z]+)\.c(\d+)$/.exec(key);
  if (!m) throw new Error(`key ${key} is not <workItem>.<stage>.c<cycle>`);
  return { workItem: m[1], stage: m[2], cycle: Number(m[3]) };
}

/** The Gateway Idempotency-Key: `<group>.<stage>.s<subtask>.c<cycle>`, without the subtask for group stages. */
export function idempotencyKey(key: string, subtask: number | null): string {
  const { workItem, stage, cycle } = keyParts(key);
  return subtask === null ? `${workItem}.${stage}.c${cycle}` : `${workItem}.${stage}.s${subtask}.c${cycle}`;
}

/** The open subtask with the lowest position, as the Gateway defines "current". */
export function currentSubtask(group: GroupRow): TaskRow | null {
  return [...group.tasks].sort((a, b) => a.position - b.position).find((t) => OPEN_SUBTASK.includes(t.status)) ?? null;
}

/** A bound `data.latest(...)` record → its envelope, or null. */
export function envelope(rec: unknown): Envelope | null {
  if (rec === null || rec === undefined || typeof rec !== "object") return null;
  const r = rec as Record<string, unknown>;
  const env = (r.attributes ?? r) as Record<string, unknown>;
  if (typeof env.payload !== "object" || env.payload === null) return null;
  return { payload: env.payload as Record<string, unknown>, recordedAt: String(env.recordedAt ?? "") };
}

/**
 * What a stage entry's request depends on besides the Gateway's own state. A stage's own
 * products are left out, so a crash after recording them still matches on the next call.
 */
export function inputIdentity(args: StageArgs): string {
  const ref = (rec: unknown) => {
    const e = envelope(rec);
    return e ? { recordedAt: e.recordedAt, payload: e.payload } : null;
  };
  switch (args.stage) {
    case "implement":
      return JSON.stringify({ handoff: ref(args.handoff), review: ref(args.review) });
    case "review":
    case "publish":
      return JSON.stringify({ handoff: ref(args.handoff) });
    default:
      return "{}";
  }
}

const DEFAULT_POLL_SECONDS = 5;
const READ_RETRY_SECONDS = 30;

function nextRead(now: number, seconds: unknown): string {
  const s = typeof seconds === "number" && seconds > 0 ? seconds : DEFAULT_POLL_SECONDS;
  return new Date(now + s * 1000).toISOString();
}

function newer(a: Envelope | null, ...others: (Envelope | null)[]): boolean {
  return a !== null && others.every((o) => o === null || a.recordedAt > o.recordedAt);
}

function deliverableLines(t: TaskRow): string {
  return (t.deliverables ?? []).map((d) => `- ${d.id} (${d.type}): ${d.description}`).join("\n") || "- none";
}

/** The implementer prompt: the subtask, plus the newest feedback that came after the last change. */
export function implementerPrompt(group: GroupRow, t: TaskRow, change: Envelope | null, handoff: Envelope | null, review: Envelope | null): string {
  const parts = [
    `Task: ${group.title}`,
    group.brief,
    `Subtask ${t.position}: ${t.title}`,
    t.brief,
    `Deliverables:\n${deliverableLines(t)}`,
  ];
  const sameSubtask = (e: Envelope | null) => e !== null && e.payload.subtask === t.id ? e : null;
  const h = sameSubtask(handoff);
  const r = sameSubtask(review);
  if (newer(h, change, r) && h!.payload.outcome !== "passed") {
    parts.push(`The handoff check of your last change ended ${h!.payload.outcome}. Fix it. Output tail:\n${String(h!.payload.output ?? "")}`);
  } else if (newer(r, change, h) && r!.payload.outcome === "changes_requested") {
    parts.push(`The reviewer requested changes:\n${String(r!.payload.summary ?? "")}`);
  }
  parts.push("Commit nothing yourself and do not fetch or push; the Gateway checks and commits your working tree. End the turn with the turn command: ready_for_review, or blocked with one specific question.");
  return parts.filter((p) => p !== "").join("\n\n");
}

export function reviewerPrompt(group: GroupRow, t: TaskRow, handoff: Envelope): string {
  return [
    `Task: ${group.title}`,
    group.brief,
    `Review subtask ${t.position}: ${t.title}`,
    t.brief,
    `Deliverables:\n${deliverableLines(t)}`,
    `The handoff check passed and committed ${String(handoff.payload.commit)}. Review that commit against the brief and the deliverables. Read the diff; change nothing.`,
    "End the turn with the turn command: approved, changes_requested with the findings in the summary, or blocked with one specific question. If the command asks for pull request flags, supply them.",
  ].join("\n\n");
}

function errorCode(r: HttpResponse): string {
  const e = (r.body?.error ?? {}) as { code?: string; message?: string };
  return `${r.status} ${e.code ?? "unknown"}${e.message ? `: ${e.message}` : ""}`;
}

function data(r: HttpResponse): Record<string, unknown> {
  return (r.body?.data ?? {}) as Record<string, unknown>;
}

async function readGroup(http: Http, group: number): Promise<GroupRow> {
  const r = await http("GET", `/api/v1/task-groups/${group}`);
  if (r.status !== 200) throw new Error(`reading task group ${group}: ${errorCode(r)}`);
  return data(r) as unknown as GroupRow;
}

/** Board-state moves are idempotent on the Gateway (setting the current status changes nothing). */
async function setSubtaskStatus(http: Http, group: number, t: TaskRow, status: string): Promise<void> {
  if (t.status === status) return;
  const r = await http("PATCH", `/api/v1/task-groups/${group}/tasks/${t.id}`, { status });
  if (r.status !== 200) throw new Error(`moving subtask ${t.id} to ${status}: ${errorCode(r)}`);
}

/** Plan the operation for a stage. Returns null for `merged`, which starts no operation. */
async function plan(args: StageArgs, group: number, http: Http): Promise<{ subtask: number | null; request: PlannedRequest | null }> {
  const g = await readGroup(http, group);
  const base = `/api/v1/task-groups/${group}`;
  const req = (path: string, body: Record<string, unknown>, subtask: number | null) => ({
    subtask,
    request: { path: `${base}${path}`, body, idempotencyKey: idempotencyKey(args.key, subtask) },
  });
  switch (args.stage) {
    case "provision":
      if (g.status === "backlog") {
        const r = await http("PATCH", base, { status: "todo" });
        if (r.status !== 200) throw new Error(`moving group ${group} to todo: ${errorCode(r)}`);
      }
      return req("/provision", {}, null);
    case "publish": {
      const h = envelope(args.handoff);
      if (!h || h.payload.outcome !== "passed") throw new Error("publish needs a passed handoff check");
      return req("/publish", {
        check: h.payload.checkId,
        pull_request: { title: g.title, body: `${g.brief}\n\nDriven by Swamp through ADR 0229 Gateway operations (group ${group}).` },
      }, null);
    }
    case "merged":
      return { subtask: null, request: null };
  }
  const t = currentSubtask(g);
  if (!t) throw new Error(`group ${group} has no open subtask`);
  switch (args.stage) {
    case "baseline":
      await setSubtaskStatus(http, group, t, "running");
      return req("/check", { subtask: t.id, phase: "baseline" }, t.id);
    case "handoff":
      return req("/check", { subtask: t.id, phase: "handoff" }, t.id);
    case "implement": {
      await setSubtaskStatus(http, group, t, "running");
      const change = envelope(args.change);
      const thread = change && change.payload.subtask === t.id && change.payload.threadId ? String(change.payload.threadId) : "new";
      const prompt = implementerPrompt(g, t, change, envelope(args.handoff), envelope(args.review));
      return req("/turn", { subtask: t.id, role: "implementer", prompt, thread }, t.id);
    }
    case "review": {
      const h = envelope(args.handoff);
      if (!h || h.payload.subtask !== t.id || h.payload.outcome !== "passed") throw new Error(`review needs a passed handoff check of subtask ${t.id}`);
      await setSubtaskStatus(http, group, t, "reviewing");
      return req("/turn", { subtask: t.id, role: "reviewer", prompt: reviewerPrompt(g, t, h), thread: "new", check: h.payload.checkId }, t.id);
    }
  }
  throw new Error(`unknown stage ${args.stage}`);
}

/** Map a succeeded operation's result to the factory products of its stage. */
async function products(job: Job, result: Record<string, unknown>, http: Http): Promise<Pick<Job, "artifacts" | "evidence">> {
  const ev = (extra: Record<string, unknown> = {}) => ({ status: "succeeded", runId: String(job.operationId), operationId: job.operationId, ...extra });
  switch (job.stage) {
    case "provision":
      return {
        artifacts: { workspace: { instanceId: result.instance_id, nodeId: result.node_id, startingCommit: result.starting_commit } },
        evidence: ev(),
      };
    case "baseline":
    case "handoff": {
      const name = job.stage;
      const outcome = String(result.outcome);
      return {
        artifacts: {
          [name]: {
            subtask: job.subtask,
            outcome,
            checkId: result.check_id,
            commit: result.commit ?? null,
            deliverables: result.deliverables ?? [],
            output: String(result.output ?? "").slice(-4000),
          },
        },
        evidence: ev({ outcome }),
      };
    }
    case "implement":
    case "review": {
      const receipt = (result.receipt ?? null) as Record<string, unknown> | null;
      const outcome = receipt ? String(receipt.outcome) : "none";
      // The Gateway returns the thread as its id (TaskTurnOperation).
      const artifact: Record<string, unknown> = {
        subtask: job.subtask,
        outcome,
        summary: receipt ? String(receipt.summary ?? "") : "",
        question: receipt?.question ?? null,
        threadId: typeof result.thread === "number" ? result.thread : null,
        threadState: result.state ?? null,
      };
      if (job.stage === "review" && outcome === "approved") {
        // Approval ends the subtask on the board; the next stage needs to know whether another remains.
        const g = await readGroup(http, job.group);
        const t = g.tasks.find((x) => x.id === job.subtask);
        if (t) await setSubtaskStatus(http, job.group, t, "completed");
        artifact.hasNext = g.tasks.some((x) => x.id !== job.subtask && OPEN_SUBTASK.includes(x.status));
      }
      return { artifacts: { [job.stage === "implement" ? "change" : "review"]: artifact }, evidence: ev({ outcome }) };
    }
    case "publish":
      return {
        artifacts: {
          "pull-request": {
            branch: result.branch,
            commit: result.commit,
            number: result.pull_request_number ?? null,
            url: result.pull_request_url ?? null,
          },
        },
        evidence: ev(),
      };
  }
  return { artifacts: {}, evidence: ev() };
}

/** `merged`: complete the group once its published pull request merged. No operation. */
async function merged(job: Job, http: Http): Promise<Job> {
  const g = await readGroup(http, job.group);
  const state = g.watched_pr_state ?? null;
  if (g.status === "completed") return { ...job, state: "succeeded", evidence: { status: "succeeded", runId: job.key, completed: true } };
  if (state === "merged") {
    const r = await http("POST", `/api/v1/task-groups/${job.group}/complete`);
    if (r.status !== 200) return { ...job, lastError: `completing group: ${errorCode(r)}` };
    return { ...job, state: "succeeded", evidence: { status: "succeeded", runId: job.key, completed: true } };
  }
  if (state === "closed") return { ...job, state: "failed", evidence: { status: "failed", runId: job.key, error: "pull request closed without merge" } };
  return { ...job, operationStatus: `pull request ${state ?? "not observed yet"}` };
}

/** One ensure call for one driver key. */
export async function runStage(args: StageArgs, deps: Deps): Promise<Job> {
  const group = groupOf(args.workItem);
  // Deadlines are taken from a fresh clock reading after each response, so a slow
  // request does not use up the poll interval the Gateway asked for.
  const clock = deps.now ?? Date.now;
  const now = clock();
  const identity = inputIdentity(args);
  const prev = await deps.readJob();
  if (prev && prev.inputIdentity !== null && prev.inputIdentity !== undefined && prev.inputIdentity !== identity) {
    throw new Error(`input conflict: ${args.key} was started for other input; a new input needs a new stage entry`);
  }
  if (prev && prev.state !== "running") return { ...prev, launchedNow: false };

  let job: Job = prev
    ? { ...prev, inputIdentity: identity, launchedNow: false }
    : {
      key: args.key, workItem: args.workItem, stage: args.stage, group, subtask: null, state: "running",
      launches: 0, launchedNow: false, request: null, operationId: null, operationStatus: null,
      lastError: null, inputIdentity: identity, nextReadAt: null, artifacts: {}, evidence: null,
    };

  if (args.stage === "merged") {
    job = await merged(job, deps.http);
    await deps.writeJob(job);
    return job;
  }

  if (job.operationId === null) {
    if (job.request === null) {
      // Plan once and store the request before starting it, so every replay sends the same input.
      const p = await plan(args, group, deps.http);
      job = { ...job, subtask: p.subtask, request: p.request };
      await deps.writeJob(job);
    }
    const req = job.request!;
    const r = await deps.http("POST", req.path, req.body, req.idempotencyKey);
    if (r.status === 202) {
      const op = data(r);
      job = {
        ...job, operationId: Number(op.id), operationStatus: String(op.status), launches: job.launches + 1,
        launchedNow: true, lastError: null, nextReadAt: nextRead(clock(), op.poll_after_seconds),
      };
    } else if (r.status === 409 && (r.body?.error as { code?: string } | undefined)?.code === "operation.target_busy") {
      job = { ...job, lastError: errorCode(r) };
    } else if (r.status >= 400 && r.status < 500) {
      job = { ...job, state: "failed", lastError: errorCode(r), evidence: { status: "failed", runId: job.key, error: errorCode(r) } };
    } else {
      job = { ...job, lastError: errorCode(r) };
    }
    // The first call ends once the accepted operation id is stored; a later call reads it.
    await deps.writeJob(job);
    return job;
  }

  if (job.nextReadAt !== null && job.nextReadAt !== undefined && now < Date.parse(job.nextReadAt)) return job;

  const r = await deps.http("GET", `/api/v1/operations/${job.operationId}`);
  if (r.status !== 200) {
    job = { ...job, lastError: `reading operation ${job.operationId}: ${errorCode(r)}`, nextReadAt: nextRead(clock(), READ_RETRY_SECONDS) };
    await deps.writeJob(job);
    return job;
  }
  const op = data(r);
  const status = String(op.status);
  job = { ...job, operationStatus: status, lastError: null, nextReadAt: nextRead(clock(), op.poll_after_seconds) };
  if (TERMINAL_OPERATION.includes(status)) {
    if (status === "succeeded") {
      job = { ...job, state: "succeeded", ...(await products(job, (op.result ?? {}) as Record<string, unknown>, deps.http)) };
    } else {
      const error = `${status}${op.error_code ? ` ${op.error_code}` : ""}${op.failed_step ? ` at ${op.failed_step}` : ""}`;
      job = { ...job, state: "failed", lastError: error, evidence: { status: "failed", runId: String(job.operationId), operationId: job.operationId, error } };
    }
  }
  await deps.writeJob(job);
  return job;
}
