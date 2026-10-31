// Pure stage logic for @nckrtl/orbit-stage; unit-tested in stage_logic_test.ts.

export interface Envelope {
  payload: Record<string, unknown>;
  recordedAt: string;
  stageId?: string;
  cycle?: number;
}

export function cycleOf(key: string): number {
  const m = key.match(/\.c(\d+)$/);
  if (!m) throw new Error(`key ${key} does not end in .c<cycle>`);
  return Number(m[1]);
}

function newer(a: Envelope | null, ...others: (Envelope | null)[]): boolean {
  return a !== null && others.every((o) => o === null || a.recordedAt > o.recordedAt);
}

/** What the implementer must address now: the newest of failing handoff, review findings, consult answer. */
export function implementContext(fb: Envelope | null, rv: Envelope | null, ca: Envelope | null) {
  return {
    feedback: fb && fb.payload.status === "failed" && newer(fb, rv) ? fb.payload : null,
    findings: newer(rv, fb) ? rv!.payload : null,
    consult: newer(ca, fb, rv) ? ca!.payload : null,
  };
}

/** Orbit's rule: consecutive failed handoffs, reset by a pass. `prev` is the last recorded handoff-result. */
export function nextStreak(prev: Envelope | null, failed: boolean): number {
  if (!failed) return 0;
  return Number(prev?.payload.consecutiveFailures ?? 0) + 1;
}

/** Last line of an implementer reply: DONE: <summary> or BLOCKED: <question>. */
export function implementOutcome(reply: string): { outcome: "done" | "blocked" | "error"; text: string } {
  const last = reply.trim().split("\n").pop()?.trim() ?? "";
  if (last.startsWith("BLOCKED:")) return { outcome: "blocked", text: last.slice(8).trim() };
  if (last.startsWith("DONE:")) return { outcome: "done", text: last.slice(5).trim() };
  return { outcome: reply.trim() === "" ? "error" : "done", text: last.slice(0, 200) };
}

interface AgentFinding {
  severity: string;
  file: string;
  line?: number;
  description: string;
  suggestion?: string;
}

/** agent-runner review findings → the factory's findings contract (stable REV-n ids). */
export function toFactoryFindings(findings: AgentFinding[]) {
  return findings.map((f, i) => ({
    id: `REV-${i + 1}`,
    severity: f.severity,
    category: "review",
    description: `${f.file}${f.line ? `:${f.line}` : ""}: ${f.description}${f.suggestion ? ` Suggestion: ${f.suggestion}` : ""}`,
  }));
}

/** First JSON object in a model reply, tolerating a code fence. */
export function firstJson(text: string): Record<string, unknown> | null {
  const start = text.indexOf("{");
  const end = text.lastIndexOf("}");
  if (start < 0 || end <= start) return null;
  try {
    return JSON.parse(text.slice(start, end + 1));
  } catch {
    return null;
  }
}
