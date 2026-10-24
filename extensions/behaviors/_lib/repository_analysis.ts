// Consumer validation for the delivered tasks.analyze contract. Repository rules
// and extraction stay in bin/task-analyze; this module never scans a checkout.
import { z } from "npm:zod@4";

const text = z.string().refine((v) => v.trim().length > 0, "nonempty text required");
const digest = z.string().regex(/^[a-f0-9]{64}$/);
const oid = z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/);
const positive = z.number().int().positive().max(Number.MAX_SAFE_INTEGER);
const snapshot = z.strictObject({ head: oid, tree: oid });
const encoder = new TextEncoder();
export const REPORT_BYTES = 65536;

export async function sha256(value: string): Promise<string> {
  return Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", encoder.encode(value))), (b) => b.toString(16).padStart(2, "0")).join("");
}

/** Closed-schema canonical JSON: ASCII keys, safe integers, well-formed strings. */
export function canonical(value: unknown): string {
  if (Array.isArray(value)) return `[${value.map(canonical).join(",")}]`;
  if (value !== null && typeof value === "object") {
    const r = value as Record<string, unknown>;
    return `{${Object.keys(r).sort().map((k) => `${JSON.stringify(k)}:${canonical(r[k])}`).join(",")}}`;
  }
  if (typeof value === "number" && !Number.isSafeInteger(value)) throw new Error("unsafe manifest number");
  if (typeof value === "string" && !value.isWellFormed()) throw new Error("invalid Unicode");
  return JSON.stringify(value);
}

/** Parse whole JSON and reject duplicate keys, excess nesting and nonfinite numbers. */
export function strictJson(raw: string, maxBytes: number): unknown {
  if (encoder.encode(raw).length > maxBytes || !raw.isWellFormed()) throw new Error("oversized or invalid JSON bytes");
  let i = 0;
  const whitespace = () => {
    while (/[\x20\t\r\n]/.test(raw[i] ?? "!") && i < raw.length) i++;
  };
  const string = (): string => {
    const start = i++;
    while (i < raw.length) {
      if (raw[i++] === '"') {
        const value = JSON.parse(raw.slice(start, i));
        if (!value.isWellFormed()) throw new Error("invalid Unicode");
        return value;
      }
      if (raw[i - 1] === "\\") i++;
    }
    throw new Error("unterminated JSON string");
  };
  const value = (depth: number): unknown => {
    whitespace();
    if (depth > 24) throw new Error("JSON nesting exceeds 24");
    if (raw[i] === '"') return string();
    if (raw[i] === "{") {
      i++;
      whitespace();
      const result: Record<string, unknown> = Object.create(null);
      if (raw[i] === "}") {
        i++;
        return result;
      }
      while (true) {
        whitespace();
        if (raw[i] !== '"') throw new Error("missing JSON key");
        const key = string();
        whitespace();
        if (Object.hasOwn(result, key) || raw[i++] !== ":") throw new Error("duplicate key or missing colon");
        result[key] = value(depth + 1);
        whitespace();
        if (raw[i] === "}") {
          i++;
          return result;
        }
        if (raw[i++] !== ",") throw new Error("missing JSON comma");
      }
    }
    if (raw[i] === "[") {
      i++;
      whitespace();
      const result: unknown[] = [];
      if (raw[i] === "]") {
        i++;
        return result;
      }
      while (true) {
        result.push(value(depth + 1));
        whitespace();
        if (raw[i] === "]") {
          i++;
          return result;
        }
        if (raw[i++] !== ",") throw new Error("missing JSON comma");
      }
    }
    const token = /^(?:true|false|null|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)/.exec(raw.slice(i))?.[0];
    if (!token) throw new Error("missing JSON value");
    i += token.length;
    const result = JSON.parse(token);
    if (typeof result === "number" && !Number.isFinite(result)) throw new Error("nonfinite JSON number");
    return result;
  };
  const result = value(0);
  whitespace();
  if (i !== raw.length) throw new Error("trailing JSON bytes");
  return result;
}

export const ManifestSchema = z.strictObject({
  id: z.string().regex(/^[A-Za-z0-9_-]{1,64}$/),
  revision: positive,
  source: z.strictObject({ brief_id: text, revision_sha256: digest }),
  asserted_by: text.refine((v) => [...v].length <= 128, "actor exceeds 128 characters"),
  complete: z.literal(true),
  paths: z.array(z.discriminatedUnion("change", [
    z.strictObject({ change: z.enum(["add", "modify", "delete"]), path: z.string() }),
    z.strictObject({ change: z.literal("rename"), from: z.string(), path: z.string() }),
  ])).min(1).max(256),
});
export type Manifest = z.infer<typeof ManifestSchema>;
export const PlanSchema = z.strictObject({
  manifest: ManifestSchema,
  plan_sha256: digest,
  base: oid,
  repository: text,
  branch: text,
  docsSubtask: positive,
});
export type AuthorizedPlan = z.infer<typeof PlanSchema>;

export function paths(manifest: Manifest): string[] {
  return manifest.paths.flatMap((p) => p.change === "rename" ? [p.from, p.path] : [p.path]);
}
function byteCompare(a: string, b: string): number {
  const x = encoder.encode(a), y = encoder.encode(b);
  for (let i = 0; i < Math.min(x.length, y.length); i++) if (x[i] !== y[i]) return x[i] - y[i];
  return x.length - y.length;
}
function path(value: string): void {
  if (
    !value.isWellFormed() || !value || encoder.encode(value).length > 512 || value.trim() !== value ||
    /[\p{Cc}\\*?\[\]{}]/u.test(value) || /^[A-Za-z]:|^\//.test(value) || value.split("/").some((s) => ["", ".", "..", ".git"].includes(s))
  ) {
    throw new Error("unsafe repository-relative file path");
  }
}

/** Brief bytes are explicit and stable across board status changes. */
export function briefSource(
  group: { title: string; brief: string; tasks: { id: number; position: number; title: string; brief: string; deliverables?: unknown[] }[] },
): string {
  return canonical({
    title: group.title,
    brief: group.brief,
    tasks: [...group.tasks].sort((a, b) => a.position - b.position).map((t) => ({
      id: t.id,
      position: t.position,
      title: t.title,
      brief: t.brief,
      deliverables: t.deliverables ?? [],
    })),
  });
}

export async function validatePlan(raw: unknown, brief: string, workItem: string, previous?: AuthorizedPlan): Promise<AuthorizedPlan> {
  const plan = PlanSchema.parse(raw);
  const m = plan.manifest;
  const all = paths(m);
  all.forEach(path);
  if (new Set(all).size !== all.length) throw new Error("duplicate/conflicting path endpoints");
  const order = m.paths.map((p) => [p.path, p.change, p.change === "rename" ? p.from : ""].join("\0"));
  if (order.some((p, i) => i > 0 && byteCompare(order[i - 1], p) >= 0)) throw new Error("manifest paths are not in UTF-8 byte order");
  const encoded = canonical(m);
  if (
    encoder.encode(encoded).length > 32768 || await sha256(encoded) !== plan.plan_sha256 ||
    m.source.brief_id !== workItem || m.source.revision_sha256 !== await sha256(brief)
  ) throw new Error("manifest digest/source mismatch or oversize");
  if (previous) {
    if (
      m.id !== previous.manifest.id || plan.base !== previous.base || plan.repository !== previous.repository || plan.branch !== previous.branch ||
      (m.revision === previous.manifest.revision && plan.docsSubtask !== previous.docsSubtask) ||
      m.revision < previous.manifest.revision || (m.revision === previous.manifest.revision && plan.plan_sha256 !== previous.plan_sha256) ||
      paths(previous.manifest).some((p) => !all.includes(p))
    ) throw new Error("plan revision is not cumulative or fixed workspace identity changed");
  }
  return plan;
}

const status = z.enum(["ready", "action_required", "blocked"]);
const generatorStatus = z.enum(["passed", "failed", "stale", "missing"]);
const DocsReport = z.strictObject({
  base: oid,
  paths: z.array(text),
  impacted_pages: z.array(z.strictObject({ page: text, reasons: z.array(text).min(1) })),
  surfaces: z.array(z.strictObject({ path: text, kind: text, owner: text, reason: text, generator_status: generatorStatus.nullable() })),
  surfaces_handled_by_generator: z.array(z.strictObject({ generator: text, paths: z.array(text), status: generatorStatus, current: z.boolean() })),
  errors: z.array(text),
  verdict: z.enum(["no_docs_change", "docs_required"]),
});
const Envelope = z.strictObject({
  schema: z.literal(1),
  execution_id: z.string().regex(/^[a-f0-9]{32}$/),
  plan_sha256: digest,
  base: oid,
  head: oid,
  tree: oid,
  status,
  diagnostics: z.array(z.strictObject({ code: text, message: z.string() })),
  report: z.strictObject({ schema: z.literal("orbit.docs-impact.v1"), data: DocsReport }),
});
export type ReportEnvelope = z.infer<typeof Envelope>;
const Result = z.strictObject({
  outcome: z.enum(["ready", "action_required", "blocked", "changed"]),
  artifact: z.strictObject({ sha256: digest, bytes: positive, content: z.string() }),
  provenance: z.strictObject({
    operation_id: positive,
    group: positive,
    subtask: positive,
    repository: text,
    branch: text,
    plan_sha256: digest,
    base: oid,
    instance_id: positive,
    node_id: positive,
    lease_id: positive,
    execution_id: z.string().regex(/^[a-f0-9]{32}$/),
    entrypoint_sha256: digest,
    plan_id: text,
    plan_revision: positive,
    before: snapshot,
    after: snapshot,
    started_at: z.string().datetime({ offset: true }),
    finished_at: z.string().datetime({ offset: true }),
    duration_ms: z.number().int().nonnegative(),
    exit_code: z.number().int(),
    mechanical_llm_calls: z.literal(0),
  }),
});
export type AnalysisResult = z.infer<typeof Result>;
export type Candidate = z.infer<typeof snapshot>;

/** Preserve the entire result even when unusable. Never manufacture a reduced report. */
export async function validateAnalysis(raw: unknown, expected: {
  operation: number;
  group: number;
  subtask: number;
  plan: AuthorizedPlan;
  candidate: Candidate;
  instanceId: number;
  nodeId: number;
}): Promise<{ result: AnalysisResult; outcome: string; omitted: string[] }> {
  const r = Result.parse(raw), p = r.provenance, a = r.artifact;
  if (a.bytes > REPORT_BYTES || encoder.encode(a.content).length !== a.bytes || await sha256(a.content) !== a.sha256) {
    throw new Error("analysis artifact size/digest mismatch");
  }
  const e = Envelope.parse(strictJson(a.content, REPORT_BYTES));
  const { plan, candidate } = expected;
  if (
    p.operation_id !== expected.operation || p.group !== expected.group || p.subtask !== expected.subtask ||
    p.instance_id !== expected.instanceId || p.node_id !== expected.nodeId || p.repository !== plan.repository || p.branch !== plan.branch ||
    p.plan_id !== plan.manifest.id || p.plan_revision !== plan.manifest.revision || p.plan_sha256 !== plan.plan_sha256 || p.base !== plan.base ||
    p.before.head !== candidate.head || p.before.tree !== candidate.tree || e.execution_id !== p.execution_id ||
    e.plan_sha256 !== p.plan_sha256 || e.base !== p.base || e.head !== candidate.head || e.tree !== candidate.tree || e.report.data.base !== plan.base ||
    Date.parse(p.finished_at) < Date.parse(p.started_at)
  ) throw new Error("analysis provenance differs from requested workspace/plan/candidate");
  const d = e.report.data;
  d.paths.forEach(path);
  if (
    new Set(d.paths).size !== d.paths.length || d.paths.some((v, i) => i > 0 && byteCompare(d.paths[i - 1], v) >= 0) ||
    paths(plan.manifest).some((v) => !d.paths.includes(v))
  ) throw new Error("analysis path union is invalid");
  if (d.surfaces_handled_by_generator.some((g) => g.current !== (g.status === "passed"))) throw new Error("contradictory generator status");
  const blocked = d.errors.length > 0 || d.surfaces.some((s) => s.owner === "unowned" || (s.generator_status !== null && s.generator_status !== "passed")) ||
    d.surfaces_handled_by_generator.some((g) => g.status !== "passed") || (d.verdict === "no_docs_change" && d.impacted_pages.length > 0);
  const computed = blocked ? "blocked" : d.verdict === "no_docs_change" ? "ready" : "action_required";
  const changed = p.before.head !== p.after.head || p.before.tree !== p.after.tree;
  if (e.status !== computed || r.outcome !== (changed ? "changed" : computed) || (!changed && p.exit_code !== 0)) {
    throw new Error("contradictory analysis outcome");
  }
  const omitted = d.paths.filter((v) => !paths(plan.manifest).includes(v));
  return { result: r, outcome: omitted.length ? "plan_required" : r.outcome, omitted };
}
