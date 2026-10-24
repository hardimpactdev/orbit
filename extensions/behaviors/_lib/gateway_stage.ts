import { type GroupRequest, syncGroupAssistance } from "./group_assistance.ts";
import { type AuthorizedPlan, briefSource, type Candidate, validateAnalysis, validatePlan } from "./repository_analysis.ts";

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

export const STAGES = ["provision", "baseline", "analyze", "implement", "handoff", "review", "publish", "merged"] as const;
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
  analysisContext?: { plan: AuthorizedPlan; candidate: Candidate; purpose: "implement" | "review"; instanceId: number; nodeId: number };
  invalidated?: boolean;
}

export interface StageArgs {
  workItem: string;
  stage: Stage;
  key: string;
  change?: unknown;
  handoff?: unknown;
  review?: unknown;
  plan?: unknown;
  analysis?: unknown;
  workspace?: unknown;
  baseline?: unknown;
}

export interface Deps {
  http: Http;
  readJob: () => Promise<Job | null>;
  writeJob: (job: Job) => Promise<void>;
  now?: () => number;
}

interface AssistanceFields {
  assistance_requested?: boolean;
  assistance_kind?: string | null;
  assistance_question?: string | null;
  assistance_reason?: string | null;
}
interface TaskRow extends AssistanceFields {
  id: number;
  position: number;
  title: string;
  brief: string;
  status: string;
  deliverables?: { id: string; type: string; description: string }[];
}
export interface GroupRow extends AssistanceFields {
  orchestration?: string;
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
      return JSON.stringify({ handoff: ref(args.handoff), review: ref(args.review), plan: envelope(args.plan)?.payload ?? null, analysis: envelope(args.analysis)?.payload ?? null });
    case "review":
      return JSON.stringify({ handoff: ref(args.handoff), plan: envelope(args.plan)?.payload ?? null, analysis: envelope(args.analysis)?.payload ?? null });
    case "analyze": {
      let candidate: unknown;
      try { candidate = analysisCandidate(args); } catch { candidate = null; }
      return JSON.stringify({ plan: envelope(args.plan)?.payload ?? null, candidate, workspace: envelope(args.workspace)?.payload ?? null });
    }
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
    "Repository analysis is mechanical evidence only. Review documentation meaning, coverage, and any waiver under repository policy. A no-docs verdict never approves a waiver or removes semantic review.",
    "End the turn with the turn command: approved, changes_requested with the findings in the summary, or blocked with one specific question. If the command asks for pull request flags, supply them.",
  ].join("\n\n");
}

/** Latest supported whole-workspace snapshot; never obtain VM files from the host. */
export function analysisCandidate(args: StageArgs): { candidate: Candidate; purpose: "implement" | "review" } {
  const records = (["baseline", "change", "handoff", "review"] as const).flatMap((kind) => {
    const record = envelope(args[kind]);
    return record ? [{ kind, ...record }] : [];
  });
  records.sort((a, b) => a.recordedAt.localeCompare(b.recordedAt));
  const latest = records.at(-1);
  const c = latest?.payload.candidate as Candidate | undefined;
  if (!c || !/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/.test(c.head) || !/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/.test(c.tree)) throw new Error("analysis requires a complete native candidate snapshot");
  const h = envelope(args.handoff);
  const retryReview = latest?.kind === "review" && !["approved", "changes_requested"].includes(String(latest.payload.outcome)) &&
    h?.payload.subtask === latest.payload.subtask && h?.payload.outcome === "passed";
  return { candidate: c, purpose: retryReview || (latest?.kind === "handoff" && latest.payload.outcome === "passed" && latest.payload.commit) ? "review" : "implement" };
}

async function authorized(args: StageArgs, g: GroupRow): Promise<AuthorizedPlan> {
  const previous = envelope(args.analysis)?.payload.plan as AuthorizedPlan | undefined;
  return await validatePlan(envelope(args.plan)?.payload, briefSource(g), args.workItem, previous);
}

async function turnAnalysis(args: StageArgs, g: GroupRow, t: TaskRow, http: Http): Promise<{ operation: number; plan_sha256: string }> {
  const plan = await authorized(args, g);
  const a = envelope(args.analysis)?.payload;
  if (!a || a.subtask !== t.id || a.plan_sha256 !== plan.plan_sha256 || !["ready", "action_required"].includes(String(a.outcome)) || a.purpose !== (args.stage === "review" ? "review" : "implement")) throw new Error("turn requires usable current-plan analysis for this child and role");
  // Revalidate complete stored evidence, not a green summary or an arbitrary operation reference.
  const checked = await validateAnalysis(a.result, { operation: Number(a.operationId), group: g.id, subtask: t.id, plan, candidate: a.candidate as Candidate,
    instanceId: Number(envelope(args.workspace)?.payload.instanceId), nodeId: Number(envelope(args.workspace)?.payload.nodeId) });
  if (checked.outcome !== a.outcome || !["ready", "action_required"].includes(checked.outcome)) throw new Error("analysis summary does not identify usable complete evidence");
  const docs = g.tasks.find((x) => x.id === plan.docsSubtask);
  const reviewed = envelope(args.review)?.payload.docsReview as { plan_sha256?: string; subtask?: number; operationId?: number } | undefined;
  if (!docs || docs.position > t.position || (t.id !== docs.id && (docs.status !== "completed" || reviewed?.subtask !== docs.id || reviewed?.plan_sha256 !== plan.plan_sha256))) throw new Error("feature author requires existing semantic docs review for the current plan; revise the plan/docs child when scope changes");
  if (t.id !== docs.id) {
    if (!Number.isSafeInteger(reviewed?.operationId)) throw new Error("missing existing documentation reviewer operation");
    const response = await http("GET", `/api/v1/operations/${reviewed!.operationId}`);
    const operation = data(response);
    const input = operation.input as Record<string, unknown> | undefined;
    const reference = input?.analysis as Record<string, unknown> | undefined;
    const receipt = (operation.result as Record<string, unknown> | undefined)?.receipt as Record<string, unknown> | undefined;
    if (response.status !== 200 || operation.id !== reviewed!.operationId || operation.kind !== "tasks.turn" || operation.status !== "succeeded" ||
      operation.target_type !== "task_group" || operation.target_id !== g.id || input?.role !== "reviewer" || input.subtask !== docs.id ||
      reference?.plan_sha256 !== plan.plan_sha256 || receipt?.outcome !== "approved") throw new Error("existing documentation review is unavailable or does not approve this plan");
  }
  return { operation: Number(a.operationId), plan_sha256: plan.plan_sha256 };
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
async function plan(args: StageArgs, group: number, http: Http): Promise<{ subtask: number | null; request: PlannedRequest | null; analysisContext?: Job["analysisContext"] }> {
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
    case "analyze": {
      const p = await authorized(args, g);
      const { candidate, purpose } = analysisCandidate(args);
      const w = envelope(args.workspace)?.payload;
      return { ...req("/analyze", { subtask: t.id, plan: p.manifest, plan_sha256: p.plan_sha256, base: p.base, ...candidate }, t.id),
        analysisContext: { plan: p, candidate, purpose, instanceId: Number(w?.instanceId), nodeId: Number(w?.nodeId) } };
    }
    case "implement": {
      const analysis = await turnAnalysis(args, g, t, http);
      await setSubtaskStatus(http, group, t, "running");
      const change = envelope(args.change);
      const thread = change && change.payload.subtask === t.id && change.payload.threadId ? String(change.payload.threadId) : "new";
      const p = envelope(args.plan)!.payload as unknown as AuthorizedPlan;
      const prompt = implementerPrompt(g, t, change, envelope(args.handoff), envelope(args.review)) +
        "\n\nThe attached full repository analysis is evidence, not semantic approval. Follow repository docs/waiver policy. Before any edit outside the cumulative authorized path inventory, stop blocked and request a revised plan. " +
        (p.docsSubtask === t.id ? "This is the documentation child: write/review the required documentation before feature runtime implementation. Missing write-mode generator support must block; do not use a host or shell workaround." : "The existing documentation review applies only to this plan revision.");
      return req("/turn", { subtask: t.id, role: "implementer", prompt, thread, analysis }, t.id);
    }
    case "review": {
      const h = envelope(args.handoff);
      if (!h || h.payload.subtask !== t.id || h.payload.outcome !== "passed") throw new Error(`review needs a passed handoff check of subtask ${t.id}`);
      const analysis = await turnAnalysis(args, g, t, http);
      await setSubtaskStatus(http, group, t, "reviewing");
      return req("/turn", { subtask: t.id, role: "reviewer", prompt: reviewerPrompt(g, t, h), thread: "new", check: h.payload.checkId, analysis }, t.id);
    }
  }
  throw new Error(`unknown stage ${args.stage}`);
}

/** The payload of a bound artifact when it belongs to this subtask, else null. */
function ofSubtask(rec: unknown, subtask: number | null): Record<string, unknown> | null {
  const e = envelope(rec);
  return e !== null && subtask !== null && e.payload.subtask === subtask ? e.payload : null;
}

function count(v: unknown): number {
  return typeof v === "number" && Number.isFinite(v) ? v : 0;
}

/**
 * Per-subtask counters, carried in each stage's own artifact so the factory's gates can cap
 * attempts per subtask while its cycle numbers (and the driver keys) stay unique across the run.
 *   implement.attempt              implementer turns on this subtask
 *   handoff.consecutiveFailures    failing handoffs in a row on this subtask; a pass resets it
 *   review.attempt                 reviewer turns on this subtask
 *   handoff/review.implementAttempts  the attempt of the change they judged
 */
export function counters(stage: Stage, subtask: number | null, outcome: string, args: Pick<StageArgs, "change" | "handoff" | "review">): Record<string, number> {
  const change = ofSubtask(args.change, subtask);
  switch (stage) {
    case "implement":
      return { attempt: count(change?.attempt) + 1 };
    case "handoff": {
      const prev = ofSubtask(args.handoff, subtask);
      const failing = outcome !== "passed";
      const prevFailing = prev !== null && prev.outcome !== "passed";
      return {
        consecutiveFailures: failing ? (prevFailing ? Math.max(count(prev!.consecutiveFailures), 1) : 0) + 1 : 0,
        implementAttempts: count(change?.attempt),
      };
    }
    case "review":
      return { attempt: count(ofSubtask(args.review, subtask)?.attempt) + 1, implementAttempts: count(change?.attempt) };
  }
  return {};
}

/** Map a succeeded operation's result to the factory products of its stage. */
async function products(job: Job, result: Record<string, unknown>, http: Http, args: StageArgs): Promise<Pick<Job, "artifacts" | "evidence">> {
  const ev = (extra: Record<string, unknown> = {}) => ({ status: "succeeded", runId: String(job.operationId), operationId: job.operationId, ...extra });
  switch (job.stage) {
    case "analyze": {
      const context = job.analysisContext!;
      const { plan: p, candidate, purpose } = context;
      let outcome: string;
      let omitted: string[] = [];
      let invalid: string | null = null;
      try {
        ({ outcome, omitted } = await validateAnalysis(result, { operation: job.operationId!, group: job.group, subtask: job.subtask!, plan: p, candidate,
          instanceId: context.instanceId, nodeId: context.nodeId }));
        const current = await authorized(args, await readGroup(http, job.group));
        if (current.plan_sha256 !== p.plan_sha256 || job.invalidated) outcome = "stale";
      } catch (e) {
        outcome = "invalid";
        invalid = e instanceof Error ? e.message : String(e);
      }
      return { artifacts: { analysis: { subtask: job.subtask, outcome, purpose, operationId: job.operationId, plan_sha256: p.plan_sha256, plan: p,
        candidate, omitted, invalid, result } }, evidence: ev({ outcome }) };
    }
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
            candidate: { head: result.commit ?? result.head, tree: result.tree },
            deliverables: result.deliverables ?? [],
            output: String(result.output ?? "").slice(-4000),
            ...counters(job.stage, job.subtask, outcome, args),
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
        outcome: job.invalidated ? "blocked" : outcome,
        summary: receipt ? String(receipt.summary ?? "") : "",
        question: receipt?.question ?? null,
        threadId: typeof result.thread === "number" ? result.thread : null,
        threadState: result.state ?? null,
        candidate: (job.stage === "review" ? result.after_reset : result.after) ?? null,
        docsReview: envelope(args.review)?.payload.docsReview ?? null,
        ...(job.invalidated ? { invalidated: true, originalResult: result, question: "The plan changed during this operation. Reconcile its terminal result and authorize a fresh analysis." } : {}),
        ...counters(job.stage, job.subtask, outcome, args),
      };
      if (job.stage === "review" && outcome === "approved" && !job.invalidated) {
  const p = envelope(args.plan)?.payload as AuthorizedPlan | undefined;
        if (p?.docsSubtask === job.subtask) artifact.docsReview = { subtask: job.subtask, plan_sha256: p.plan_sha256, operationId: job.operationId };
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

const ENDED_GROUP = ["failed", "cancelled", "completed"];

/**
 * A failed or cancelled provision ends the run only when it ended the Orbit group. A
 * cancelled queued provision leaves the group open (todo), where a new provision is valid.
 */
async function withGroupOutcome(job: Job, http: Http): Promise<Job> {
  if (job.stage !== "provision" || job.state !== "failed" || job.evidence === null) return job;
  const g = await readGroup(http, job.group);
  return { ...job, evidence: { ...job.evidence, groupStatus: g.status, groupEnded: ENDED_GROUP.includes(g.status) } };
}

/** `merged`: complete the group once its published pull request merged. No operation. */
async function merged(job: Job, http: Http): Promise<Job> {
  const g = await readGroup(http, job.group);
  const state = g.watched_pr_state ?? null;
  if (g.status === "completed") return { ...job, state: "succeeded", lastError: null, evidence: { status: "succeeded", runId: job.key, completed: true } };
  if (state === "merged") {
    const r = await http("POST", `/api/v1/task-groups/${job.group}/complete`);
    if (r.status !== 200) return { ...job, lastError: `completing group: ${errorCode(r)}` };
    return { ...job, state: "succeeded", lastError: null, evidence: { status: "succeeded", runId: job.key, completed: true } };
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
  const changedInput = prev && prev.inputIdentity !== null && prev.inputIdentity !== undefined && prev.inputIdentity !== identity;
  const recoverBound = prev?.state === "running" && ["analyze", "implement", "review"].includes(args.stage) && prev.request !== null;
  if (changedInput && !recoverBound) {
    throw new Error(`input conflict: ${args.key} was started for other input; a new input needs a new stage entry`);
  }
  if (prev && prev.state !== "running") return { ...prev, launchedNow: false };

  let job: Job = prev
    ? { ...prev, invalidated: prev.invalidated || Boolean(changedInput), launchedNow: false }
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
      try {
        const p = await plan(args, group, deps.http);
        job = { ...job, subtask: p.subtask, request: p.request, ...(p.analysisContext ? { analysisContext: p.analysisContext } : {}) };
      } catch (e) {
        if (!["analyze", "implement", "review"].includes(args.stage)) throw e;
        const error = e instanceof Error ? e.message : String(e);
        job = { ...job, state: "failed", lastError: error, evidence: { status: "failed", runId: job.key, error, code: "swamp.analysis_input_invalid" } };
        await deps.writeJob(job);
        return job;
      }
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
      job = await withGroupOutcome(
        { ...job, state: "failed", lastError: errorCode(r), evidence: { status: "failed", runId: job.key, error: errorCode(r), response: r.body } },
        deps.http,
      );
    } else {
      job = { ...job, lastError: errorCode(r) };
    }
    // The first call ends once the accepted operation id is stored; a later call reads it.
    await deps.writeJob(job);
    return job;
  }

  if (job.nextReadAt !== null && job.nextReadAt !== undefined && now < Date.parse(job.nextReadAt)) {
    if (changedInput) await deps.writeJob(job);
    return job;
  }

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
      job = { ...job, state: "succeeded", ...(await products(job, (op.result ?? {}) as Record<string, unknown>, deps.http, args)) };
    } else {
      const error = `${status}${op.error_code ? ` ${op.error_code}` : ""}${op.failed_step ? ` at ${op.failed_step}` : ""}`;
      job = await withGroupOutcome(
        { ...job, state: "failed", lastError: error, evidence: { status: "failed", runId: String(job.operationId), operationId: job.operationId, error, code: op.error_code ?? null,
          // Public OperationData does not expose private analysis failure metadata.
          // Keep the whole supported read. Never reconstruct required_bytes from a tail.
          operation: op, requiredBytes: null, diagnosticsAvailability: "public operation fields only" } },
        deps.http,
      );
    }
  }
  await deps.writeJob(job);
  return job;
}

// ---- Assistance on the board -------------------------------------------------------------
// Open children retain the original comment path. Post-child publish gates use
// the group route and conservative history/approval reconciliation in group_assistance.ts.

export interface AssistanceArgs {
  workItem: string;
  parked: boolean;
  stage: string;
  cycle: number;
  reason: string;
  factory?: string;
  gateIds?: string[];
}

export interface AssistanceRecord {
  workItem: string;
  subtask: number | null;
  key: string | null;
  raisedAt: string | null;
  /** Why a parked entry raised nothing, such as no open subtask to own the request. */
  skipped?: string | null;
  groupRequest?: GroupRequest | null;
}

export interface AssistanceDeps {
  http: Http;
  readRecord: () => Promise<AssistanceRecord | null>;
  writeRecord: (r: AssistanceRecord) => Promise<void>;
  /** The stage job of a driver key, to explain why the stage parked. */
  readJob: (key: string) => Promise<Job | null>;
  now?: () => number;
  readRun?: () => Promise<{ workItem?: string; startedAt?: string } | null>;
  readApproval?: (gateId: string) => Promise<unknown>;
}

/** Why a stage entry parked, from its job: the failed operation, or the outcome it ended with. */
export function parkedWhy(job: Job | null): string {
  if (job === null) return "";
  if (job.state === "failed") return `the ${job.stage} operation${job.operationId !== null ? ` ${job.operationId}` : ""} failed (${job.lastError ?? "no error recorded"})`;
  const products = Object.values(job.artifacts)[0] as Record<string, unknown> | undefined;
  if (!products) return "";
  const outcome = String(products.outcome ?? "");
  if (job.stage === "analyze") return `the repository analysis ended ${outcome}${products.omitted && (products.omitted as unknown[]).length ? `; omitted actual paths: ${JSON.stringify(products.omitted)}` : ""}${products.invalid ? `: ${String(products.invalid)}` : ""}`;
  if (job.stage === "baseline" || job.stage === "handoff") {
    return outcome === "passed" ? "" : `the ${job.stage} check ${String(products.checkId ?? "")} ended ${outcome}`;
  }
  if (job.stage === "implement" || job.stage === "review") {
    const q = products.question ? `: ${String(products.question)}` : "";
    return `the ${job.stage === "implement" ? "implementer" : "reviewer"} turn ended ${outcome}${q}`;
  }
  return "";
}

export function assistanceBody(args: AssistanceArgs, why: string): string {
  const text = [
    `Swamp parked ${args.workItem} at ${args.stage}, cycle ${args.cycle}${why ? `: ${why}` : ""}.`,
    `Waiting for: ${args.reason}`,
  ].join(" ");
  return text.slice(0, 2000);
}

async function postComment(http: Http, group: number, subtask: number, type: "assistance_requested" | "resolution", body: string) {
  const r = await http("POST", `/api/v1/task-groups/${group}/tasks/${subtask}/comments`, { type, body, author: "swamp" });
  if (r.status !== 200 && r.status !== 201) throw new Error(`posting ${type} on subtask ${subtask}: ${errorCode(r)}`);
}

function inactiveRequest(r: AssistanceFields): boolean {
  return r.assistance_requested === false && r.assistance_kind === null && r.assistance_question === null && r.assistance_reason === null;
}

function directionRequest(r: AssistanceFields): boolean {
  return r.assistance_requested === true && r.assistance_kind === "direction" &&
    typeof r.assistance_question === "string" && typeof r.assistance_reason === "string";
}

/** Match the Gateway's complete request comparison, never just the reason. */
function mirroredDirection(g: GroupRow, t: TaskRow): boolean {
  return directionRequest(g) && directionRequest(t) &&
    g.assistance_question === t.assistance_question && g.assistance_reason === t.assistance_reason;
}

/** One assistance sync for one work item: raise it when parked, clear it when not. */
export async function syncAssistance(args: AssistanceArgs, deps: AssistanceDeps): Promise<AssistanceRecord> {
  const group = groupOf(args.workItem);
  let rec = (await deps.readRecord()) ?? { workItem: args.workItem, subtask: null, key: null, raisedAt: null, skipped: null };
  const groupView = await readGroup(deps.http, group);
  const hasOpenChild = currentSubtask(groupView) !== null;
  if (!hasOpenChild || rec.groupRequest) {
    rec = await syncGroupAssistance(args, deps, rec, group, () => readGroup(deps.http, group), async () => {
      const why = parkedWhy(await deps.readJob(`${args.workItem}.${args.stage}.c${args.cycle}`));
      return assistanceBody(args, why);
    });
    if (!hasOpenChild || rec.groupRequest) return rec;
    // The current group request is resolved; continue with the ordinary child path.
  }
  const key = `${args.stage}.c${args.cycle}`;
  const skip = async (why: string): Promise<AssistanceRecord> => {
    const skipped = { ...rec, skipped: why };
    await deps.writeRecord(skipped);
    return skipped;
  };
  const clear = async (): Promise<AssistanceRecord> => {
    if (rec.subtask === null) return rec;
    const g = await readGroup(deps.http, group);
    const t = g.tasks.find((x) => x.id === rec.subtask);
    if (t?.assistance_requested) {
      if (!OPEN_SUBTASK.includes(t.status) || !directionRequest(t) || (!inactiveRequest(g) && !mirroredDirection(g, t))) {
        return skip("preserved independent, safety or unreadable request before child resolution");
      }
      await postComment(deps.http, group, t.id, "resolution", `Swamp resumed ${args.workItem} at ${args.stage}, cycle ${args.cycle}.`);
    }
    const cleared = { workItem: args.workItem, subtask: null, key: null, raisedAt: null, skipped: null };
    await deps.writeRecord(cleared);
    return cleared;
  };
  if (!args.parked) return clear();
  if (rec.key === key && rec.subtask !== null) return rec;
  if (rec.subtask !== null) {
    rec = await clear();
    if (rec.subtask !== null) return rec; // Refusal must not fall through to a new request.
  }
  const g = await readGroup(deps.http, group);
  const t = currentSubtask(g);
  if (t === null) return rec; // The child ended between reads; reconcile afresh next tick.
  if (!t.assistance_requested) {
    if (!inactiveRequest(g) || !inactiveRequest(t)) {
      return skip("preserved independent, safety or unreadable request before child assistance");
    }
    const why = parkedWhy(await deps.readJob(`${args.workItem}.${args.stage}.c${args.cycle}`));
    await postComment(deps.http, group, t.id, "assistance_requested", assistanceBody(args, why));
  }
  const raised = { workItem: args.workItem, subtask: t.id, key, raisedAt: new Date((deps.now ?? Date.now)()).toISOString(), skipped: null };
  await deps.writeRecord(raised);
  return raised;
}
