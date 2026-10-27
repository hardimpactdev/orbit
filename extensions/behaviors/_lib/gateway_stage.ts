import { type MaintenanceResult } from "./swamp_maintenance.ts";
import { dependencyProject, type DependencyProject } from "./dependency_projects.ts";
import { packageArgs, packageDescription, PackageTargetSchema, type PackageTarget } from "./package_target.ts";
import { type GroupRequest, syncGroupAssistance } from "./group_assistance.ts";
import { type AuthorizedPlan, briefSource, canonical, type Candidate, validateAnalysis, validateAnalysisFailure, validatePlan, validateProvisionResult } from "./repository_analysis.ts";

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
// A routine publish first runs the repository summary script, then publishes
// with that title and body. The script and the publish each have their own key.
// A failed or unreadable summary fails the stage and does not publish.
// A later publish cycle for the same checked commit reuses that summary. It does
// not start another script: the child is already completed.

export const STAGES = ["provision", "baseline", "analyze", "implement", "handoff", "review", "publish", "merged", "script", "repair", "finish"] as const;
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
  script?: unknown;
  routine?: boolean;
  packageTarget?: PackageTarget;
  dependencyProject?: DependencyProject;
}

export interface Deps {
  http: Http;
  projects?: DependencyProject[];
  maintenance?: (args: StageArgs, group: number, request: Record<string, unknown>) => Promise<MaintenanceResult>;
  revokeWorker?: (group: number) => Promise<void>;
  readJob: () => Promise<Job | null>;
  writeJob: (job: Job) => Promise<void>;
  /** Earlier publish jobs for this work item, so a later cycle can reuse a summary. */
  readPriorPublish?: () => Promise<Job[]>;
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
  taskable_id?: number;
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
  if (args.routine) return JSON.stringify({ routine: true, dependencyProject: args.dependencyProject, ...(args.packageTarget ? { packageTarget: args.packageTarget } : {}),
    ...(args.stage === "handoff" ? { script: ref(args.script), change: ref(args.change) } : {}),
    ...(["repair", "publish", "finish"].includes(args.stage) ? { handoff: ref(args.handoff) } : {}),
    ...(["repair", "finish"].includes(args.stage) ? { script: ref(args.script) } : {}) });
  switch (args.stage) {
    case "implement":
      return JSON.stringify({ handoff: ref(args.handoff), review: ref(args.review), plan: envelope(args.plan)?.payload ?? null, analysis: envelope(args.analysis)?.payload ?? null, workspace: envelope(args.workspace)?.payload ?? null });
    case "review":
      return JSON.stringify({ handoff: ref(args.handoff), plan: envelope(args.plan)?.payload ?? null, analysis: envelope(args.analysis)?.payload ?? null, workspace: envelope(args.workspace)?.payload ?? null });
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
  const p = await validatePlan(envelope(args.plan)?.payload, briefSource(g), args.workItem, previous);
  const w = envelope(args.workspace)?.payload;
  const result = validateProvisionResult({ instance_id: w?.instanceId, node_id: w?.nodeId, starting_commit: w?.startingCommit, analysis_base: w?.analysisBase });
  const pin = result.analysis_base;
  if (w?.group !== g.id || w.outcome !== "ready" || !Number.isSafeInteger(w.operationId) || Number(w.operationId) < 1 ||
    pin.base !== p.base || pin.repository !== p.repository || pin.branch !== p.branch) throw new Error("plan differs from the scoped immutable public provision identity");
  return p;
}

/** TaskTurnData::toArray() persists these nullable defaults even when omitted. */
function turnInputIdentity(input: unknown): string {
  return canonical(input !== null && typeof input === "object" && !Array.isArray(input)
    ? { model: null, check: null, analysis: null, ...input }
    : input);
}

function requireOperation(job: Job, op: Record<string, unknown>): void {
  if (op.id !== job.operationId || op.kind !== `tasks.${job.stage}` || op.target_type !== "task_group" || op.target_id !== job.group ||
    (job.stage === "analyze" && canonical(op.input) !== canonical(job.request?.body))) throw new Error("operation differs from the admitted group/request identity");
}

function failureMetadata(job: Job, op: Record<string, unknown>): Record<string, unknown> {
  if (job.stage !== "analyze") return {};
  try {
    requireOperation(job, op);
    if (op.status !== "failed" || op.result == null) return { requiredBytes: null, failure: null, diagnosticsAvailability: "no public failure metadata" };
    if (op.failed_step !== "analysis") throw new Error("analysis failure metadata has a different failed step");
    const failure = validateAnalysisFailure(op.result, op.error_code);
    return { failure, requiredBytes: failure.error.required_bytes, diagnosticsAvailability: "validated public failure result" };
  } catch (e) {
    return { failure: null, requiredBytes: null, diagnosticsAvailability: "invalid public failure metadata", metadataInvalid: e instanceof Error ? e.message : String(e) };
  }
}

async function turnAnalysis(args: StageArgs, g: GroupRow, t: TaskRow, http: Http): Promise<{ operation: number; plan_sha256: string }> {
  const plan = await authorized(args, g);
  const a = envelope(args.analysis)?.payload;
  if (!a || a.subtask !== t.id || a.plan_sha256 !== plan.plan_sha256 || !["ready", "action_required"].includes(String(a.outcome)) || a.purpose !== (args.stage === "review" ? "review" : "implement")) throw new Error("turn requires usable current-plan analysis for this child and role");
  if ([args.baseline, args.change, args.handoff, args.review].some((r) => envelope(r) !== null)) {
    const latest = analysisCandidate(args).candidate;
    const analyzed = a.candidate as Candidate | undefined;
    if (analyzed?.head !== latest.head || analyzed?.tree !== latest.tree) throw new Error("turn analysis does not cover the latest native candidate; obtain fresh analysis before retry");
  }
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

class RetryableCompletion extends Error {}

/**
 * Complete the publish child. A lost reply is ambiguous: re-read the child and
 * treat one that is already completed as success. If the move did not apply, the
 * caller retries it.
 */
async function completePublishChild(http: Http, group: number, subtask: number): Promise<void> {
  const ready = async (): Promise<TaskRow> => {
    const g = await readGroup(http, group);
    const child = g.tasks.find((t) => t.id === subtask);
    if (g.tasks.length !== 1 || !child || hasAssistance(g) || g.tasks.some(hasAssistance)) {
      throw new Error("publish summary preserves the open child and operator assistance");
    }
    return child;
  };
  const child = await ready();
  if (child.status === "completed") return;
  if (["failed", "cancelled"].includes(child.status)) {
    throw new Error("publish has no saved summary for this checked commit, and its child has ended");
  }
  const r = await http("PATCH", `/api/v1/task-groups/${group}/tasks/${child.id}`, { status: "completed" });
  if (r.status === 200) return;
  if (r.status === 0) {
    const current = await ready();
    if (current.status === "completed") return;
    throw new RetryableCompletion(`moving subtask ${child.id} to completed: ${errorCode(r)}`);
  }
  throw new Error(`moving subtask ${child.id} to completed: ${errorCode(r)}`);
}

async function maintenanceRequest(args: StageArgs, g: GroupRow, http: Http, mode: string): Promise<PlannedRequest> {
  if (!Number.isSafeInteger(g.taskable_id)) throw new Error("maintenance requires the group's ready VM Instance");
  const response = await http("GET", `/api/v1/instances/${g.taskable_id}`);
  const instance = data(response);
  if (response.status !== 200 || instance.id !== g.taskable_id || instance.project_id !== args.dependencyProject!.projectId ||
    typeof instance.checkout_path !== "string" || !instance.checkout_path.startsWith("/")) throw new Error("maintenance workspace identity differs");
  const base = envelope(args.workspace)?.payload.startingCommit ?? instance.starting_commit;
  if (typeof base !== "string" || !/^[a-f0-9]{40}$/.test(base)) throw new Error("maintenance requires the immutable provision starting commit");
  return { path: "swamp:maintenance", body: { cwd: instance.checkout_path, mode, base }, idempotencyKey: mode === "summary" ? `${args.key}.summary` : args.key };
}

/** Plan the operation for a stage. Returns null for `merged`, which starts no operation. */
async function plan(args: StageArgs, group: number, http: Http, deps: Deps): Promise<{ subtask: number | null; request: PlannedRequest | null; analysisContext?: Job["analysisContext"] }> {
  const g = await readGroup(http, group);
  const base = `/api/v1/task-groups/${group}`;
  if (args.routine) {
    if (g.orchestration !== "external" || g.tasks.length !== 1) throw new Error("routine requires one external group with exactly one child");
    if (hasAssistance(g) || g.tasks.some(hasAssistance)) throw new Error("routine preserves current operator and safety assistance");
  }
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
      if (!args.routine) {
        return req("/publish", {
          check: h.payload.checkId,
          pull_request: { title: g.title, body: `${g.brief}${args.packageTarget ? `\n\nSingle package: ${packageDescription(args.packageTarget)}.` : ""}\n\nDriven by Swamp through ADR 0229 Gateway operations (group ${group}).` },
        }, null);
      }
      const subtask = h.payload.subtask;
      if (!Number.isSafeInteger(subtask) || Number(subtask) < 1) throw new Error("publish summary needs the handoff subtask");
      if (!args.dependencyProject) throw new Error("publish summary needs the configured dependency project");
      const saved = await savedSummary(args, h.payload.checkId as number, deps);
      if (saved) return req("/publish", { check: h.payload.checkId, pull_request: saved }, null);
      const child = g.tasks.find((t) => t.id === Number(subtask));
      if (!child || ["completed", "failed", "cancelled"].includes(child.status)) {
        throw new Error("publish has no saved summary for this checked commit, and its child has ended");
      }
      if (args.dependencyProject.worker) return { subtask: Number(subtask), request: await maintenanceRequest(args, g, http, "summary") };
      return {
        subtask: Number(subtask),
        request: {
          path: `${base}/script`,
          body: { subtask: Number(subtask), script: args.dependencyProject.script, args: summaryArguments(args) },
          idempotencyKey: `${args.key}.summary`,
        },
      };
    }
    case "merged":
      return { subtask: null, request: null };
  }
  const t = currentSubtask(g);
  if (!t) throw new Error(`group ${group} has no open subtask`);
  switch (args.stage) {
    case "script":
      if (!args.routine) throw new Error("script stage requires the routine method");
      await setSubtaskStatus(http, group, t, "running");
      if (args.dependencyProject!.worker) return { subtask: t.id, request: await maintenanceRequest(args, g, http, args.packageTarget ? "package" : "routine") };
      return req("/script", { subtask: t.id, script: args.dependencyProject!.script, args: args.packageTarget ? packageArgs(args.packageTarget) : ["routine"] }, t.id);
    case "repair": {
      if (!args.routine) throw new Error("repair stage requires the routine method");
      const h = ofSubtask(args.handoff, t.id);
      const script = ofSubtask(args.script, t.id);
      if (!(h && ["failed", "changed"].includes(String(h.outcome))) && !(script && script.outcome === "failed")) throw new Error("repair requires failed script or local check evidence");
      const workerRepair = args.dependencyProject!.worker ? "The Swamp worker owns dependency commands. Fix their cause and the failing checks only; do not run scripts/dependencies or make unrelated dependency updates. Swamp will rerun the extension after this turn. " : "";
      const scope = workerRepair
        ? `${args.packageTarget ? `Only ${packageDescription(args.packageTarget)} at path ${args.packageTarget.path ?? "."}. Swamp reruns this exact target and verifies its selected advisories. ` : ""}${workerRepair}`
        : args.packageTarget ? `Only ${packageDescription(args.packageTarget)}. Finish ${args.dependencyProject!.script} ${packageArgs(args.packageTarget).join(" ")}; do not upgrade another direct package. A security fix must clear the selected package advisories. ` : "";
      return req("/turn", { subtask: t.id, role: "implementer", thread: "new", prompt:
        implementerPrompt(g, t, null, envelope(args.handoff), null) +
        "\n\n" + scope + "This is the single routine dependency repair turn. Preserve manifest ranges, overrides and release-age/security policy. " +
        (workerRepair ? "" : args.packageTarget ? "If the selected package command failed, fix its cause and rerun that exact command in this VM; do not claim ready until it completes. " : `If the routine command failed, fix its cause and rerun ${args.dependencyProject!.script} routine in this VM; do not claim ready until it completes. `) +
        (args.packageTarget ? "Fix the failing checks. Do not add unrelated upgrades or changes. " : "Fix the failing checks. Do not add majors or unrelated changes. ") + "Stop blocked if you cannot finish within this turn. " +
        `\nRoutine command output: ${String(script?.output ?? "")}` }, t.id);
    }
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

/** A terminal failure can leave edits. Keep its public snapshot without approving
 * a receipt or implying success/lease release. A missing snapshot shadows older
 * artifacts, so reanalysis parks instead of silently reusing a stale baseline.
 */
function failureArtifacts(job: Job, op: Record<string, unknown>, args: StageArgs): Job["artifacts"] {
  const turn = job.stage === "implement" || job.stage === "review";
  const check = job.stage === "baseline" || job.stage === "handoff";
  if (!turn && !check) return {};
  const result = (op.result ?? {}) as Record<string, unknown>;
  const input = (op.input ?? {}) as Record<string, unknown>;
  const role = job.stage === "implement" ? "implementer" : "reviewer";
  const scoped = op.id === job.operationId && op.kind === (turn ? "tasks.turn" : "tasks.check") &&
    op.target_type === "task_group" && op.target_id === job.group && input.subtask === job.subtask &&
    (turn ? input.role === role && input.thread === job.request?.body.thread : input.phase === job.stage);
  const thread = turn && Number.isSafeInteger(result.thread) && Number(result.thread) > 0 &&
      (input.thread === "new" || input.thread === String(result.thread)) ? result.thread : null;
  const raw = turn ? (job.stage === "review" ? result.after_reset : result.after) : { head: result.commit ?? result.head, tree: result.tree };
  const snapshot = raw as Candidate | null | undefined;
  const oid = (value: unknown) => typeof value === "string" && /^(?:[a-f0-9]{40}|[a-f0-9]{64})$/.test(value);
  const resultIdentity = turn ? thread !== null : Number.isSafeInteger(result.check_id) && Number(result.check_id) > 0;
  const candidate = scoped && resultIdentity && snapshot && oid(snapshot.head) && oid(snapshot.tree)
    ? { head: snapshot.head, tree: snapshot.tree } : null;
  const common = {
    subtask: job.subtask, group: job.group, operationId: job.operationId, operationStatus: op.status,
    outcome: "blocked", candidate, snapshotUnavailable: candidate ? null : "no validated scoped terminal snapshot",
    ...counters(job.stage, job.subtask, "blocked", args),
  };
  if (turn) return { [job.stage === "implement" ? "change" : "review"]: {
    ...common, role, threadId: candidate ? thread : null, threadState: scoped ? result.state ?? null : null,
    summary: "The native turn ended without success. Retry requires the existing approval and fresh analysis.",
    docsReview: envelope(args.review)?.payload.docsReview ?? null,
  } };
  return { [job.stage]: { ...common, checkId: scoped ? result.check_id ?? null : null, commit: null, deliverables: [], output: "" } };
}

function scriptProducts(job: Job, result: Record<string, unknown>, args: StageArgs, evidence: Record<string, unknown>): Pick<Job, "artifacts" | "evidence"> {
  if (!["passed", "failed"].includes(String(result.outcome)) ||
    (result.exit_code !== null && !Number.isInteger(result.exit_code)) || typeof result.changed !== "boolean" ||
    typeof result.output !== "string" || typeof result.output_truncated !== "boolean" ||
    ![result.head, result.tree].every((v) => typeof v === "string" && /^(?:[a-f0-9]{40}|[a-f0-9]{64})$/.test(v)) ||
    (result.outcome === "passed") !== (result.exit_code === 0)) throw new Error("invalid scoped script result");
  const earlier = ofSubtask(args.script, job.subtask);
  const changed = result.changed || earlier?.changed === true || (earlier?.tree != null && earlier.tree !== result.tree);
  return { artifacts: { script: { subtask: job.subtask, ...result, changed: Boolean(changed), ...(args.packageTarget ? { commandChanged: result.changed } : {}) } }, evidence };
}

/** Map a succeeded operation's result to the factory products of its stage. */
async function products(job: Job, op: Record<string, unknown>, http: Http, args: StageArgs): Promise<Pick<Job, "artifacts" | "evidence"> & { state?: Job["state"]; lastError?: string }> {
  const result = (op.result ?? {}) as Record<string, unknown>;
  const ev = (extra: Record<string, unknown> = {}) => ({ status: "succeeded", runId: String(job.operationId), operationId: job.operationId, ...extra });
  switch (job.stage) {
    case "analyze": {
      const context = job.analysisContext!;
      const { plan: p, candidate, purpose } = context;
      let outcome: string;
      let omitted: string[] = [];
      let invalid: string | null = null;
      try {
        requireOperation(job, op);
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
    case "provision": {
      if (args.routine) {
        requireOperation(job, op);
        const starting = typeof result.starting_commit === "string" && /^[a-f0-9]{40}$/.test(result.starting_commit) ? result.starting_commit : null;
        if (args.packageTarget && starting === null) throw new Error("package provision lacks a fresh starting commit");
        if (![result.instance_id, result.node_id].every((n) => Number.isSafeInteger(n) && Number(n) > 0)) throw new Error("provision has no workspace identity");
        return { artifacts: { workspace: { group: job.group, operationId: job.operationId, outcome: "ready", instanceId: result.instance_id, nodeId: result.node_id, ...(starting ? { startingCommit: starting } : {}) } }, evidence: ev() };
      }
      try {
        requireOperation(job, op);
        const r = validateProvisionResult(result);
        return { artifacts: { workspace: { group: job.group, operationId: job.operationId, outcome: "ready", instanceId: r.instance_id,
          nodeId: r.node_id, startingCommit: r.starting_commit, analysisBase: r.analysis_base } }, evidence: ev({ operation: op }) };
      } catch (e) {
        const error = e instanceof Error ? e.message : String(e);
        return { state: "failed", lastError: "public provision analysis identity unavailable",
          artifacts: { workspace: { group: job.group, operationId: job.operationId, outcome: "blocked", instanceId: null, nodeId: null,
            startingCommit: null, analysisBase: null } },
          evidence: { status: "failed", runId: String(job.operationId), operationId: job.operationId,
            code: "swamp.analysis_workspace_unavailable", error, operation: op } };
      }
    }
    case "script": {
      requireOperation(job, op);
      if (canonical(op.input) !== canonical(job.request?.body)) throw new Error("script input differs");
      return scriptProducts(job, result, args, ev());
    }

    case "baseline":
    case "handoff": {
      if (args.routine) {
        if (op.id !== job.operationId || op.kind !== "tasks.check" || op.target_type !== "task_group" || op.target_id !== job.group ||
          canonical(op.input) !== canonical(job.request?.body) || !["passed", "failed", "changed", "lost"].includes(String(result.outcome)) ||
          !Number.isSafeInteger(result.check_id) || Number(result.check_id) < 1 ||
          ![result.head, result.tree].every((v) => typeof v === "string" && /^(?:[a-f0-9]{40}|[a-f0-9]{64})$/.test(v)) ||
          (result.outcome === "passed" && !(typeof result.commit === "string" && /^(?:[a-f0-9]{40}|[a-f0-9]{64})$/.test(result.commit)))) throw new Error("invalid scoped routine check");
        if (result.outcome === "passed") {
          const g = await readGroup(http, job.group);
          if (g.tasks.length !== 1 || g.tasks[0].id !== job.subtask || hasAssistance(g) || g.tasks.some(hasAssistance)) throw new Error("routine completion preserves independent children/assistance");
          const script = envelope(args.script)?.payload;
          const unchanged = script?.changed === false && script.tree === result.tree;
          // A later publish still has to run the summary script on this child. Completing it
          // here makes the Gateway reject that script. The no-change path completes now,
          // because finish requires a completed child and does not run a script.
          if (unchanged) await setSubtaskStatus(http, job.group, g.tasks[0], "completed");
        }
      }
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
    case "repair":
    case "review": {
      if (args.routine && (op.kind !== "tasks.turn" || op.id !== job.operationId || op.target_type !== "task_group" || op.target_id !== job.group || turnInputIdentity(op.input) !== turnInputIdentity(job.request?.body))) throw new Error("invalid scoped routine repair");
      const receipt = (result.receipt ?? null) as Record<string, unknown> | null;
      const outcome = receipt ? String(receipt.outcome) : "none";
      // The Gateway returns the thread as its id (TaskTurnOperation).
      const artifact: Record<string, unknown> = {
        subtask: job.subtask,
        ...(args.routine && args.dependencyProject?.worker ? { rerunMaintenance: true } : {}),
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
      return { artifacts: { [job.stage === "review" ? "review" : "change"]: artifact }, evidence: ev({ outcome }) };
    }
    case "publish":
      if (args.routine) {
        requireOperation(job, op);
        if (canonical(op.input) !== canonical(job.request?.body)) throw new Error("publish operation differs from its checked request");
      }
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
async function merged(job: Job, http: Http, routine = false): Promise<Job> {
  const g = await readGroup(http, job.group);
  if (routine && (hasAssistance(g) || g.tasks.some(hasAssistance))) return { ...job, lastError: "routine preserves operator/safety assistance during cleanup" };
  const state = g.watched_pr_state ?? null;
  if (g.status === "completed") return { ...job, state: "succeeded", lastError: null, evidence: { status: "succeeded", runId: job.key, completed: true } };
  if (state === "merged") {
    const r = await http("POST", `/api/v1/task-groups/${job.group}/complete`);
    if (r.status !== 200) return { ...job, lastError: `completing group: ${errorCode(r)}` };
    if (routine && (data(r).status !== "completed" || hasAssistance(data(r) as AssistanceFields))) return { ...job, lastError: "routine completion retains cleanup/safety failure" };
    return { ...job, state: "succeeded", lastError: null, evidence: { status: "succeeded", runId: job.key, completed: true } };
  }
  if (state === "closed") return { ...job, state: "failed", evidence: { status: "failed", runId: job.key, error: "pull request closed without merge" } };
  return { ...job, operationStatus: `pull request ${state ?? "not observed yet"}` };
}

function hasAssistance(r: AssistanceFields): boolean {
  return r.assistance_requested !== false || r.assistance_kind !== null || r.assistance_reason !== null || r.assistance_question !== null;
}

/** No-change cleanup uses existing board moves and completion; never invent a PR. */
async function finishRoutine(job: Job, args: StageArgs, http: Http): Promise<Job> {
  const h = envelope(args.handoff)?.payload;
  const script = envelope(args.script)?.payload;
  if (!args.routine || h?.outcome !== "passed" || script?.changed !== false ||
    (h.candidate as Candidate | undefined)?.tree !== script.tree) throw new Error("finish requires passed unchanged routine evidence");
  const g = await readGroup(http, job.group);
  if (g.status === "completed" && !hasAssistance(g) && !g.tasks.some(hasAssistance)) return { ...job, state: "succeeded", evidence: { status: "succeeded", runId: job.key, completed: true } };
  if (g.tasks.length !== 1 || g.tasks[0].id !== h.subtask || g.tasks[0].status !== "completed" || hasAssistance(g) || g.tasks.some(hasAssistance) || g.watched_pr_url) throw new Error("no-change cleanup requires its completed child and no assistance or PR");
  if (g.status !== "settling") {
    const moved = await http("PATCH", `/api/v1/task-groups/${job.group}`, { status: "settling" });
    if (moved.status !== 200) throw new Error(`no-change settling refused: ${errorCode(moved)}`);
  }
  const response = await http("POST", `/api/v1/task-groups/${job.group}/complete`);
  if (response.status !== 200) return { ...job, lastError: errorCode(response) };
  const done = data(response) as unknown as GroupRow;
  if (done.status !== "completed" || hasAssistance(done)) throw new Error("no-change completion requires clean cleanup evidence");
  return { ...job, state: "succeeded", evidence: { status: "succeeded", runId: job.key, completed: true } };
}



/** A summary already validated for this same checked commit, from an earlier publish cycle. */
async function savedSummary(args: StageArgs, checkId: number, deps: Deps): Promise<{ title: string; body: string } | null> {
  if (!deps.readPriorPublish) return null;
  const current = JSON.parse(inputIdentity(args)) as { handoff?: unknown; packageTarget?: unknown };
  const jobs = (await deps.readPriorPublish()).filter((job) => job.key !== args.key && job.stage === "publish" && job.inputIdentity !== null);
  jobs.sort((a, b) => keyParts(a.key).cycle - keyParts(b.key).cycle);
  for (const job of jobs.reverse()) {
    let stored: { handoff?: unknown; packageTarget?: unknown };
    try {
      stored = JSON.parse(job.inputIdentity!) as { handoff?: unknown; packageTarget?: unknown };
    } catch {
      continue;
    }
    if (JSON.stringify(stored.handoff ?? null) !== JSON.stringify(current.handoff ?? null)) continue;
    if (JSON.stringify(stored.packageTarget ?? null) !== JSON.stringify(current.packageTarget ?? null)) continue;
    const request = job.request;
    const pull = request?.body.pull_request as { title?: unknown; body?: unknown } | undefined;
    if (!request?.path.endsWith("/publish") || request.body.check !== checkId || typeof pull?.title !== "string" || typeof pull.body !== "string") continue;
    try {
      return pullRequestSummary(`${pull.title}\n${pull.body}`);
    } catch {
      continue;
    }
  }
  return null;
}

function summaryArguments(args: StageArgs): string[] {
  const summaryArgs = ["summary"];
  const start = envelope(args.workspace)?.payload.startingCommit;
  if (typeof start === "string" && /^[a-f0-9]{40}$/.test(start)) summaryArgs.push("--base", start);
  return summaryArgs;
}

function pullRequestSummary(output: string): { title: string; body: string } {
  const text = output.replace(/\r\n/g, "\n").trim();
  const split = text.indexOf("\n");
  if (split <= 0) throw new Error("dependency summary has no title and body");
  const title = text.slice(0, split).trim();
  const body = text.slice(split + 1).trim();
  if (title === "" || title.length > 240 || body === "") throw new Error("dependency summary has no title and body");
  if (!body.includes("| Package |") || !body.includes("| From |") || !body.includes("| To |") || !/^Tested: \S/m.test(body)) {
    throw new Error("dependency summary is not a pull request title and body");
  }
  return { title, body };
}

function readPullRequestSummary(job: Job, op: Record<string, unknown>): { title: string; body: string } {
  const result = (op.result ?? {}) as Record<string, unknown>;
  if (op.id !== job.operationId || op.kind !== "tasks.script" || op.target_type !== "task_group" || op.target_id !== job.group ||
    canonical(op.input) !== canonical(job.request?.body)) throw new Error("dependency summary is unreadable");
  if (result.output_truncated === true) throw new Error("dependency summary was truncated");
  if (result.outcome !== "passed" || result.exit_code !== 0 || typeof result.output !== "string") {
    throw new Error(`dependency summary failed (${String(result.outcome)}, exit ${String(result.exit_code)})`);
  }
  return pullRequestSummary(result.output);
}

/** One ensure call for one driver key. */
export async function runStage(args: StageArgs, deps: Deps): Promise<Job> {
  const group = groupOf(args.workItem);
  if (args.routine) args = { ...args, dependencyProject: await dependencyProject(deps.http, group, deps.projects ?? []) };
  if (args.packageTarget) {
    args.packageTarget = PackageTargetSchema.parse(args.packageTarget);
    if (args.packageTarget.workItem !== args.workItem || !args.routine) throw new Error("package target differs from work item");
  }
  if (args.routine) {
    const parsed = keyParts(args.key);
    if (parsed.workItem !== args.workItem || parsed.stage !== args.stage) throw new Error("routine key does not match work item and stage");
  }
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

  if (args.stage === "finish") {
    job = await finishRoutine(job, args, deps.http);
    await deps.writeJob(job);
    try { await deps.revokeWorker?.(group); } catch { /* Minute cleanup retries. */ }
    return job;
  }
  if (args.stage === "merged") {
    job = await merged(job, deps.http, args.routine);
    await deps.writeJob(job);
    try { await deps.revokeWorker?.(group); } catch { /* Minute cleanup retries. */ }
    return job;
  }

  const launch = async (current: Job): Promise<Job> => {
    if (current.request === null) {
      // Plan once and store the request before starting it, so every replay sends the same input.
      try {
        const p = await plan(args, group, deps.http, deps);
        current = { ...current, subtask: p.subtask, request: p.request, ...(p.analysisContext ? { analysisContext: p.analysisContext } : {}) };
      } catch (e) {
        if (!["analyze", "implement", "review"].includes(args.stage)) throw e;
        const error = e instanceof Error ? e.message : String(e);
        current = { ...current, state: "failed", lastError: error, evidence: { status: "failed", runId: current.key, error, code: "swamp.analysis_input_invalid" } };
        await deps.writeJob(current);
        return current;
      }
      await deps.writeJob(current);
    }
    const req = current.request!;
    if (args.routine) {
      // A retained request may outlive an operator change or a lost response.
      // Reconcile receipts under the same key only while the observed group is safe.
      const g = await readGroup(deps.http, group);
      if (g.orchestration !== "external" || ENDED_GROUP.includes(g.status) || g.tasks.length !== 1 ||
        (current.subtask !== null && g.tasks[0].id !== current.subtask) ||
        hasAssistance(g) || g.tasks.some(hasAssistance)) throw new Error("routine dispatch preserves current children/operator/safety assistance");
    }
    if (req.path === "swamp:maintenance") {
      if (!deps.maintenance) throw new Error("worker maintenance support is unavailable; script fallback is disabled for this project");
      const outcome = await deps.maintenance(args, group, req.body);
      if (outcome.state === "running") {
        current = { ...current, operationStatus: "Swamp worker maintenance", lastError: null };
      } else if (outcome.state === "failed" || !outcome.result) {
        current = { ...current, state: "failed", lastError: outcome.lastError ?? "Swamp maintenance requires investigation", evidence: { status: "failed", runId: outcome.runId ?? args.key, workerName: outcome.workerName, error: outcome.lastError ?? "Swamp maintenance requires investigation" } };
      } else if (args.stage === "script") {
        current = { ...current, state: "succeeded", ...scriptProducts(current, outcome.result, args, { status: "succeeded", runId: outcome.runId, workerName: outcome.workerName }) };
      } else {
        const result = outcome.result;
        const handoff = envelope(args.handoff)!.payload;
        if (result.outcome !== "passed" || result.changed !== false || result.head !== handoff.commit || result.tree !== (handoff.candidate as Candidate)?.tree) {
          current = { ...current, state: "failed", lastError: "dependency summary failed or changed the checked tree", evidence: { status: "failed", runId: outcome.runId } };
        } else {
          try {
            const text = pullRequestSummary(`${String(result.output)}\n\nTested: ${args.dependencyProject!.check} (Gateway check ${String(handoff.checkId)}).`);
            current = { ...current, request: { path: `/api/v1/task-groups/${group}/publish`, body: { check: handoff.checkId, pull_request: text }, idempotencyKey: idempotencyKey(args.key, null) } };
          } catch {
            current = { ...current, state: "failed", lastError: "dependency summary is unreadable", evidence: { status: "failed", runId: outcome.runId } };
          }
          await deps.writeJob(current);
          if (current.state === "running") return launch(current);
        }
      }
      await deps.writeJob(current);
      return current;
    }
    if (args.routine && req.path.endsWith("/publish")) {
      const subtask = Number(envelope(args.handoff)?.payload.subtask);
      try {
        await completePublishChild(deps.http, group, subtask);
      } catch (e) {
        if (e instanceof RetryableCompletion) {
          current = { ...current, lastError: e.message };
          await deps.writeJob(current);
          return current;
        }
        const message = e instanceof Error ? e.message : String(e);
        current = { ...current, state: "failed", lastError: message, evidence: { status: "failed", runId: current.key, error: message } };
        await deps.writeJob(current);
        return current;
      }
    }
    const r = await deps.http("POST", req.path, req.body, req.idempotencyKey);
    if (r.status === 202) {
      const op = data(r);
      current = {
        ...current, operationId: Number(op.id), operationStatus: String(op.status), launches: current.launches + 1,
        launchedNow: true, lastError: null, nextReadAt: nextRead(clock(), op.poll_after_seconds),
      };
    } else if (r.status === 409 && (r.body?.error as { code?: string } | undefined)?.code === "operation.target_busy") {
      current = { ...current, lastError: errorCode(r) };
    } else if (r.status >= 400 && r.status < 500) {
      current = await withGroupOutcome(
        { ...current, state: "failed", lastError: errorCode(r), evidence: { status: "failed", runId: current.key, error: errorCode(r), response: r.body } },
        deps.http,
      );
    } else {
      current = { ...current, lastError: errorCode(r) };
    }
    // The first call ends once the accepted operation id is stored; a later call reads it.
    await deps.writeJob(current);
    return current;
  };

  if (job.operationId === null) return launch(job);

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
    if (args.routine && args.stage === "publish" && op.kind === "tasks.script") {
      if (status !== "succeeded") {
        const message = `dependency summary failed (${status}${op.error_code ? ` ${String(op.error_code)}` : ""})`;
        job = { ...job, state: "failed", lastError: message, evidence: { status: "failed", runId: String(job.operationId), operationId: job.operationId, error: message, code: op.error_code ?? null } };
        await deps.writeJob(job);
        return job;
      }
      try {
        const text = readPullRequestSummary(job, op);
        const handoff = envelope(args.handoff);
        if (!handoff || !Number.isSafeInteger(handoff.payload.checkId)) throw new Error("publish needs a passed handoff check");
        const g = await readGroup(deps.http, group);
        const child = g.tasks[0];
        if (g.tasks.length !== 1 || child?.id !== Number(handoff.payload.subtask) || hasAssistance(g) || g.tasks.some(hasAssistance)) {
          throw new Error("publish summary preserves the open child and operator assistance");
        }
        if (["failed", "cancelled"].includes(child.status)) {
          throw new Error("publish summary preserves the open child and operator assistance");
        }
        // Keep the validated text and this checked candidate before the child ends.
        // A lost completion reply can then be reconciled, and a later cycle can reuse it.
        job = {
          ...job,
          state: "running",
          operationId: null,
          operationStatus: null,
          nextReadAt: null,
          lastError: null,
          launchedNow: false,
          request: {
            path: `/api/v1/task-groups/${group}/publish`,
            body: { check: handoff.payload.checkId, pull_request: { title: text.title, body: text.body } },
            idempotencyKey: idempotencyKey(args.key, null),
          },
        };
      } catch (e) {
        const message = e instanceof Error ? e.message : String(e);
        job = { ...job, state: "failed", lastError: message, evidence: { status: "failed", runId: String(job.operationId), operationId: job.operationId, error: message } };
        await deps.writeJob(job);
        return job;
      }
      await deps.writeJob(job);
      return launch(job);
    }
    if (status === "succeeded") {
      job = await withGroupOutcome({ ...job, state: "succeeded", ...(await products(job, op, deps.http, args)) }, deps.http);
    } else {
      const error = `${status}${op.error_code ? ` ${op.error_code}` : ""}${op.failed_step ? ` at ${op.failed_step}` : ""}`;
      job = await withGroupOutcome(
        { ...job, state: "failed", lastError: error, artifacts: failureArtifacts(job, op, args), evidence: { status: "failed", runId: String(job.operationId), operationId: job.operationId, error, code: op.error_code ?? null,
          operation: op, ...failureMetadata(job, op) } },
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
