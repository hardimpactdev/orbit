// Post-child assistance uses PR1500's comments, never an effect or approval call.
import type { AssistanceArgs, AssistanceDeps, AssistanceRecord, GroupRow } from "./gateway_stage.ts";

export interface GroupRequest {
  body: string;
  stage: string;
  cycle: number;
  gateId: string;
  era: string;
}

const gates: Record<string, string> = { publish: "publish-retry", published: "republish" };
const ended = ["completed", "failed", "cancelled"];
const supportedGate = (stage: string, gateId: string) =>
  gates[stage] === gateId || (stage === "driver" && /^driver-retry-g[1-9]\d*$/.test(gateId));

interface Comment {
  id: number;
  task_group_id: number;
  task_id: number;
  type: string;
  author: string;
  body: string;
}

function identity(workItem: string, r: GroupRequest): string {
  return `Gate: ${r.gateId}; work item: ${workItem}; stage: ${r.stage}; cycle: ${r.cycle}; run: ${r.era}`;
}

/** The body is human-readable; its final line lets history recover a lost local record. */
function fromComment(c: Comment, group: number, workItem: string): GroupRequest | null {
  if (c.type !== "assistance_requested" || c.author !== "swamp" || c.task_group_id !== group || c.task_id !== group) return null;
  const m = /\nGate: ([\w-]+); work item: (tg-\d+); stage: (\w+); cycle: ([1-9]\d*); run: ([^\n]+)$/.exec(c.body);
  if (!m || m[2] !== workItem || !supportedGate(m[3], m[1]) || !Number.isFinite(Date.parse(m[5]))) return null;
  return { body: c.body, gateId: m[1], stage: m[3], cycle: Number(m[4]), era: m[5] };
}

function approved(raw: unknown, workItem: string, r: GroupRequest): boolean {
  if (raw === null || typeof raw !== "object") return false;
  const a = raw as Record<string, unknown>;
  return a.workItem === workItem && a.gateId === r.gateId && a.stageId === r.stage && a.cycle === r.cycle &&
    a.decision === "approved" && typeof a.actor === "string" && a.actor.trim() !== "" &&
    typeof a.decidedAt === "string" && Date.parse(a.decidedAt) >= Date.parse(r.era);
}

export async function syncGroupAssistance(
  args: AssistanceArgs,
  deps: AssistanceDeps,
  rec: AssistanceRecord,
  group: number,
  readGroup: () => Promise<GroupRow>,
  baseBody: () => Promise<string>,
): Promise<AssistanceRecord> {
  const save = async (record: AssistanceRecord) => {
    await deps.writeRecord(record);
    return record;
  };
  const skip = (why: string) => save({ ...rec, skipped: why });
  const run = await deps.readRun?.();
  if (!run?.startedAt || run.workItem !== args.workItem || !Number.isFinite(Date.parse(run.startedAt))) return skip("group assistance needs the current factory run identity");

  // No type/limit filter: the complete history is needed to notice replacements
  // and answers. Read the current request afterwards; never trust the local flag.
  const path = `/api/v1/task-groups/${group}/comments`;
  const response = await deps.http("GET", path);
  if (response.status !== 200 || !Array.isArray(response.body?.data)) throw new Error(`reading group assistance history: ${response.status}`);
  const history = response.body.data as Comment[];
  if (!history.every((c) => c && Number.isInteger(c.id) && typeof c.body === "string" && typeof c.type === "string" && typeof c.author === "string" && c.task_group_id === group && c.task_id === group)) {
    throw new Error("group assistance history has an invalid or foreign comment");
  }
  const newest = [...history].sort((a, b) => b.id - a.id)[0];
  const g = await readGroup();
  if (g.orchestration !== "external" || ended.includes(g.status)) return skip("group is not open and externally orchestrated");
  if (typeof g.assistance_requested !== "boolean") return skip("current group assistance is unreadable");
  if (g.tasks.some((t) => !ended.includes(t.status))) {
    // A resolved/obsolete local identity must not capture future child gates.
    // Release only local state; active or inconsistent group requests still block
    // the child path, which could otherwise replace them through its comments.
    if (!g.assistance_requested && g.assistance_kind === null && g.assistance_question === null && g.assistance_reason === null) {
      return save({ ...rec, subtask: null, key: null, raisedAt: null, groupRequest: null, skipped: null });
    }
    return skip("an open child prevents group assistance writes");
  }

  const post = async (type: "assistance_requested" | "resolution", body: string) => {
    const r = await deps.http("POST", path, { type, body, author: "swamp" });
    if (r.status !== 201) throw new Error(`posting group ${type}: ${r.status}; reread next tick`);
  };

  if (g.assistance_requested) {
    if (g.assistance_kind !== "direction") return skip("preserved group safety assistance");
    const own = newest ? fromComment(newest, group, args.workItem) : null;
    if (!own || g.assistance_question !== own.body || g.assistance_reason !== own.body) return skip("preserved operator replacement or ambiguous group request");
    if (own.era !== run.startedAt) return skip("preserved group request from another run era");
    if (rec.groupRequest && rec.groupRequest.body !== own.body) return skip("group request differs from the stored identity");
    rec = await save({ ...rec, subtask: null, key: `${own.stage}.c${own.cycle}`, groupRequest: own, skipped: null });
    const decision = await deps.readApproval?.(own.gateId);
    if (!approved(decision, args.workItem, own)) return rec;
    // A persisted official approval is consent; parked:false, a stage move or a
    // board resolution is not. This POST changes no factory state or effect.
    await post("resolution", `Swamp gate decision recorded for ${args.workItem}.\n${identity(args.workItem, own)}`);
    return save({ ...rec, key: null, raisedAt: null, groupRequest: null, skipped: null });
  }

  if (g.assistance_kind !== null || g.assistance_question !== null || g.assistance_reason !== null) return skip("preserved inconsistent inactive group request");
  if (!args.parked) return save({ ...rec, key: null, raisedAt: null, groupRequest: null, skipped: null });
  const gateId = args.gateIds?.length === 1 && supportedGate(args.stage, args.gateIds[0]) ? args.gateIds[0] : gates[args.stage];
  if (!gateId || args.gateIds?.length !== 1 || args.gateIds[0] !== gateId) return skip("no single supported post-child gate");
  const r: GroupRequest = { body: "", stage: args.stage, cycle: args.cycle, gateId, era: run.startedAt };
  // A board answer stays an answer even if no factory approval followed. Never
  // re-raise the same request on every tick or after losing the local record.
  if (history.some((c) => {
    const old = fromComment(c, group, args.workItem);
    return old !== null && identity(args.workItem, old) === identity(args.workItem, r);
  })) return skip("group request already recorded; board resolution is not gate approval");
  if (rec.groupRequest) {
    if (identity(args.workItem, rec.groupRequest) !== identity(args.workItem, r)) return skip("pending group request has another identity");
    r.body = rec.groupRequest.body;
  } else {
    r.body = `${await baseBody()}\n${identity(args.workItem, r)}`;
  }
  rec = await save({ ...rec, subtask: null, key: `${r.stage}.c${r.cycle}`, raisedAt: null, groupRequest: r, skipped: null });
  await post("assistance_requested", r.body);
  return save({ ...rec, raisedAt: new Date((deps.now ?? Date.now)()).toISOString() });
}
