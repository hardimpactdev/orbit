// An in-memory fake of the Orbit Gateway task routes for routing tests.
// Each group follows a scenario. Operations are terminal at once; a start
// answers 202 with poll_after_seconds 1, so the client reads it on a later tick.

export interface Scenario {
  subtasks?: number;
  provisionFails?: boolean;
  provisionCancelOnce?: boolean;
  baselineFailOnce?: boolean;
  handoffAlwaysFails?: boolean;
  handoffCancelOnce?: boolean;
  firstReview?: "changes_requested" | "approved";
  reviewAlways?: "changes_requested";
  publishFailOnce?: boolean;
  prClosedOnce?: boolean;
}

interface Group {
  id: number;
  title: string;
  brief: string;
  status: string;
  watched_pr_state: string | null;
  tasks: { id: number; position: number; title: string; brief: string; status: string; deliverables: unknown[] }[];
  scenario: Scenario;
  counts: Record<string, number>;
}

export function startFakeGateway(scenarios: Record<number, Scenario>) {
  const groups = new Map<number, Group>();
  for (const [id, scenario] of Object.entries(scenarios)) {
    const gid = Number(id);
    groups.set(gid, {
      id: gid, title: `Group ${gid}`, brief: "Routing test.", status: "backlog", watched_pr_state: null, scenario, counts: {},
      tasks: Array.from({ length: scenario.subtasks ?? 1 }, (_, i) => ({
        id: gid * 100 + i + 1, position: i + 1, title: `Subtask ${i + 1}`, brief: "Do it.", status: "todo", deliverables: [],
      })),
    });
  }
  const ops = new Map<number, Record<string, unknown>>();
  const byKey = new Map<string, number>();
  const starts = new Map<string, number>();
  let nextOp = 1000;

  const bump = (g: Group, k: string) => (g.counts[k] = (g.counts[k] ?? 0) + 1);

  function run(g: Group, kind: string, body: Record<string, unknown>): { status: string; result?: unknown; error_code?: string } {
    const s = g.scenario;
    switch (kind) {
      case "provision":
        // A cancelled queued provision leaves the group open in todo.
        if (s.provisionCancelOnce && bump(g, "provision") === 1) return { status: "cancelled" };
        if (s.provisionFails) {
          g.status = "failed";
          return { status: "failed", error_code: "task_vm.invalid_config" };
        }
        g.status = "running";
        return { status: "succeeded", result: { instance_id: 1, node_id: 2, starting_commit: "base" } };
      case "check": {
        const n = bump(g, `check.${body.phase}`);
        if (body.phase === "baseline") {
          if (s.baselineFailOnce && n === 1) return { status: "failed", error_code: "tasks.check_unreachable" };
          return { status: "succeeded", result: { outcome: "passed", check_id: nextOp, commit: null, deliverables: [], output: "ok" } };
        }
        if (s.handoffCancelOnce && n === 1) return { status: "cancelled" };
        const outcome = s.handoffAlwaysFails ? "failed" : "passed";
        return { status: "succeeded", result: { outcome, check_id: nextOp, commit: outcome === "passed" ? `c${nextOp}` : null, deliverables: [], output: outcome } };
      }
      case "turn": {
        const thread = body.thread === "new" ? nextOp : Number(body.thread);
        if (body.role === "reviewer") {
          const n = bump(g, `review.${body.subtask}`);
          const outcome = s.reviewAlways ?? (n === 1 && s.firstReview ? s.firstReview : "approved");
          return { status: "succeeded", result: { receipt: { outcome, summary: `review ${n}` }, thread, state: "done" } };
        }
        return { status: "succeeded", result: { receipt: { outcome: "ready_for_review", summary: "done" }, thread, state: "done" } };
      }
      case "publish": {
        const n = bump(g, "publish");
        if (s.publishFailOnce && n === 1) return { status: "failed", error_code: "tasks.push_failed" };
        const ok = (g.counts.published = (g.counts.published ?? 0) + 1);
        g.watched_pr_state = s.prClosedOnce && ok === 1 ? "closed" : "merged";
        return { status: "succeeded", result: { branch: `task-${g.id}`, commit: "head", pull_request_number: ok, pull_request_url: `https://example.test/pull/${ok}` } };
      }
    }
    return { status: "failed", error_code: "fake.unknown_kind" };
  }

  const server = Deno.serve({ port: 0, hostname: "127.0.0.1", onListen: () => {} }, async (req) => {
    const p = new URL(req.url).pathname;
    const body = req.method === "GET" ? {} : await req.json().catch(() => ({}));
    const json = (status: number, data: unknown) =>
      new Response(JSON.stringify(status < 400 ? { data, meta: {} } : data), { status, headers: { "content-type": "application/json" } });
    const m = /^\/api\/v1\/task-groups\/(\d+)(\/.*)?$/.exec(p);
    if (m) {
      const g = groups.get(Number(m[1]));
      if (!g) return json(404, { error: { code: "http.404" } });
      const rest = m[2] ?? "";
      const view = () => ({ ...g, scenario: undefined, counts: undefined });
      if (req.method === "GET" && rest === "") return json(200, view());
      if (req.method === "PATCH" && rest === "") {
        g.status = body.status;
        return json(200, view());
      }
      const sub = /^\/tasks\/(\d+)$/.exec(rest);
      if (req.method === "PATCH" && sub) {
        const t = g.tasks.find((x) => x.id === Number(sub[1]));
        if (!t) return json(404, { error: { code: "http.404" } });
        t.status = body.status;
        return json(200, t);
      }
      if (req.method === "POST" && rest === "/complete") {
        g.status = "completed";
        return json(200, view());
      }
      const op = /^\/(provision|check|turn|publish)$/.exec(rest);
      if (req.method === "POST" && op) {
        const key = req.headers.get("idempotency-key") ?? "";
        starts.set(key, (starts.get(key) ?? 0) + 1);
        if (byKey.has(key)) return json(202, { ...ops.get(byKey.get(key)!), status: "queued", poll_after_seconds: 1 });
        const id = nextOp++;
        const out = run(g, op[1], body);
        const rec = { id, kind: `tasks.${op[1]}`, input: body, ...out };
        ops.set(id, rec);
        byKey.set(key, id);
        return json(202, { ...rec, status: "queued", poll_after_seconds: 1 });
      }
    }
    const read = /^\/api\/v1\/operations\/(\d+)$/.exec(p);
    if (req.method === "GET" && read && ops.has(Number(read[1]))) {
      return json(200, { ...ops.get(Number(read[1])), poll_after_seconds: 1, steps: [] });
    }
    return json(404, { error: { code: "http.404", message: `${req.method} ${p}` } });
  });

  return {
    url: `http://127.0.0.1:${(server.addr as Deno.NetAddr).port}`,
    starts,
    groups,
    /** Starts per stage name for a group, from the Idempotency-Keys. */
    startsOf: (gid: number, stage: string) => [...starts.keys()].filter((k) => k.startsWith(`tg-${gid}.${stage}.`)).length,
    close: () => server.shutdown(),
  };
}
