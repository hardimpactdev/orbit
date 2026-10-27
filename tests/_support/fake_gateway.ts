import { WEBSITE_PROJECT } from "./dependency_projects.ts";
// An in-memory fake of the Orbit Gateway task routes for routing tests.
// Each group follows a scenario. Operations are terminal at once; a start
// answers 202 with poll_after_seconds 1, so the client reads it on a later tick.
import { analysisFixture, HEAD, TREE } from "./analysis_fixture.ts";
import { sha256 } from "../../extensions/behaviors/_lib/repository_analysis.ts";

export interface Scenario {
  projectId?: number;
  prStaysOpen?: boolean;
  scriptNoChange?: boolean;
  scriptFails?: boolean;
  scriptFailsOnce?: boolean;
  scriptInfrastructureFailure?: boolean;
  scriptInterrupted?: boolean;
  handoffFailOnce?: boolean;
  repairBlocked?: boolean;
  subtasks?: number;
  provisionFails?: boolean;
  provisionCancelOnce?: boolean;
  baselineFailOnce?: boolean;
  handoffAlwaysFails?: boolean;
  handoffCancelOnce?: boolean;
  implementFailOnce?: boolean;
  firstReview?: "changes_requested" | "approved";
  reviewAlways?: "changes_requested";
  publishFailOnce?: boolean;
  summaryFails?: boolean;
  summaryTruncated?: boolean;
  summaryGarbage?: boolean;
  prClosedOnce?: boolean;
  provisionIdentity?: Record<string, unknown>;
  analysisFailure?: { schema: number; error: { code: string; required_bytes: number | null } };
}

interface Group {
  project_id: number;
  taskable_id: number | null;
  vm_power: string | null;
  id: number;
  title: string;
  brief: string;
  status: string;
  watched_pr_state: string | null;
  orchestration: string;
  assistance_requested: boolean;
  assistance_kind: string | null;
  assistance_reason: string | null;
  assistance_question: string | null;
  tasks: {
    id: number; position: number; title: string; brief: string; status: string; deliverables: unknown[];
    assistance_requested: boolean; assistance_kind: string | null; assistance_question: string | null; assistance_reason: string | null;
  }[];
  scenario: Scenario;
  counts: Record<string, number>;
  head: string;
  tree: string;
}

const SUMMARY = [
  "Update dependencies for October 2026",
  "",
  "Updated the declared dependencies to versions that still fit their ranges.",
  "",
  "Nothing changes for users of the app.",
  "",
  "| Package | From | To | note |",
  "| --- | --- | --- | --- |",
  "| livewire/livewire | 4.2.0 | 4.2.1 | |",
  "",
  "Tested: compared the lockfiles with the base commit.",
].join("\n");

export function startFakeGateway(scenarios: Record<number, Scenario>) {
  const projects = new Map([[20, {
    id: 20, repository_url: WEBSITE_PROJECT.repository,
    task_check: WEBSITE_PROJECT.check, default_branch: WEBSITE_PROJECT.defaultBranch,
  }]]);
  const groups = new Map<number, Group>();
  for (const [id, scenario] of Object.entries(scenarios)) {
    const gid = Number(id);
    groups.set(gid, {
      project_id: scenario.projectId ?? 20, taskable_id: null, vm_power: null, id: gid, title: `Group ${gid}`, brief: "Routing test.", status: "backlog", watched_pr_state: null, scenario, counts: {}, head: HEAD, tree: TREE,
      orchestration: "external", assistance_requested: false, assistance_kind: null, assistance_reason: null, assistance_question: null,
      tasks: Array.from({ length: scenario.subtasks ?? 1 }, (_, i) => ({
        id: gid * 100 + i + 1, position: i + 1, title: `Subtask ${i + 1}`, brief: "Do it.", status: "todo", deliverables: [], assistance_requested: false, assistance_kind: null, assistance_question: null, assistance_reason: null,
      })),
    });
  }
  const ops = new Map<number, Record<string, unknown>>();
  const byKey = new Map<string, number>();
  const starts = new Map<string, number>();
  const comments: { id: number; group: number; subtask: number | null; task_group_id: number; task_id: number; type: string; body: string; author: string }[] = [];
  let nextOp = 1000;

  const bump = (g: Group, k: string) => (g.counts[k] = (g.counts[k] ?? 0) + 1);

  async function run(g: Group, kind: string, body: Record<string, unknown>, operation: number): Promise<{ status: string; result?: unknown; error_code?: string; failed_step?: string }> {
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
        g.taskable_id = s.projectId ? g.id : 1; g.vm_power = "running";
        if (s.provisionIdentity) return { status: "succeeded", result: s.provisionIdentity };
        return { status: "succeeded", result: { instance_id: g.taskable_id, node_id: 2, starting_commit: HEAD,
          analysis_base: { base: HEAD, instance_id: 1, repository: "https://example.test/repo.git", branch: `task-${g.id}` } } };
      case "analyze": {
        if (s.analysisFailure) return { status: "failed", error_code: s.analysisFailure.error.code, failed_step: "analysis", result: s.analysisFailure };
        if (body.head !== g.head || body.tree !== g.tree) return { status: "failed", error_code: "tasks.analysis_stale" };
        const f = await analysisFixture(g);
        const e = JSON.parse(f.result.artifact.content);
        const manifest = body.plan as typeof f.plan.manifest;
        e.head = body.head; e.tree = body.tree; e.base = body.base; e.report.data.base = body.base; e.plan_sha256 = body.plan_sha256;
        e.report.data.paths = manifest.paths.flatMap((p) => p.change === "rename" ? [p.from, p.path] : [p.path]).sort();
        const content = JSON.stringify(e);
        const result = { ...f.result, artifact: { content, bytes: new TextEncoder().encode(content).length, sha256: await sha256(content) },
          provenance: { ...f.result.provenance, operation_id: operation, subtask: body.subtask, base: body.base,
            instance_id: s.provisionIdentity?.instance_id ?? 1, node_id: s.provisionIdentity?.node_id ?? 2,
            plan_sha256: body.plan_sha256, plan_id: manifest.id, plan_revision: manifest.revision,
            before: { head: body.head, tree: body.tree }, after: { head: body.head, tree: body.tree } } };
        return { status: "succeeded", result };
      }
      case "check": {
        const n = bump(g, `check.${body.phase}`);
        if (body.phase === "baseline") {
          if (s.baselineFailOnce && n === 1) return { status: "failed", error_code: "tasks.check_unreachable" };
          return { status: "succeeded", result: { outcome: "passed", check_id: nextOp, commit: null, head: g.head, tree: g.tree, deliverables: [], output: "ok" } };
        }
        if (s.handoffCancelOnce && n === 1) return { status: "cancelled" };
        const outcome = s.handoffAlwaysFails || (s.handoffFailOnce && n === 1) ? "failed" : "passed";
        if (outcome === "passed") g.head = operation.toString(16).padStart(40, "0");
        return { status: "succeeded", result: { outcome, check_id: nextOp, commit: outcome === "passed" ? g.head : null, head: g.head, tree: g.tree, deliverables: [], output: outcome } };
      }
      case "turn": {
        const thread = body.thread === "new" ? nextOp : Number(body.thread);
        if (body.role === "reviewer") {
          const n = bump(g, `review.${body.subtask}`);
          const outcome = s.reviewAlways ?? (n === 1 && s.firstReview ? s.firstReview : "approved");
          return { status: "succeeded", result: { receipt: { outcome, summary: `review ${n}` }, thread, state: "done", after_reset: { head: g.head, tree: g.tree } } };
        }
        const n = bump(g, `implement.${body.subtask}`);
        if (s.implementFailOnce && n === 1) {
          g.tree = "c".repeat(40);
          return { status: "failed", error_code: "tasks.turn_failed", result: { receipt: null, thread, state: "failed", after: { head: g.head, tree: g.tree }, after_reset: null } };
        }
        return { status: "succeeded", result: { receipt: { outcome: s.repairBlocked ? "blocked" : "ready_for_review", summary: "done" }, thread, state: "done", after: { head: g.head, tree: g.tree } } };
      }
      case "publish": {
        const n = bump(g, "publish");
        if (s.publishFailOnce && n === 1) return { status: "failed", error_code: "tasks.push_failed" };
        const ok = (g.counts.published = (g.counts.published ?? 0) + 1);
        g.watched_pr_state = s.prClosedOnce && ok === 1 ? "closed" : s.prStaysOpen ? "open" : "merged";
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
    const project = /^\/api\/v1\/projects\/(\d+)$/.exec(p);
    if (req.method === "GET" && project && projects.has(Number(project[1]))) return json(200, projects.get(Number(project[1])));
    const instance = /^\/api\/v1\/instances\/(\d+)$/.exec(p);
    if (req.method === "GET" && instance) {
      const g = [...groups.values()].find((g) => g.taskable_id === Number(instance[1]));
      if (g) return json(200, { id: g.taskable_id, project_id: g.project_id, checkout_path: "/vm/repo", starting_commit: HEAD });
    }
    const maintenance = /^\/fixture\/maintenance\/(\d+)\/(routine|package|summary|detect)$/.exec(p);
    if (req.method === "GET" && maintenance) {
      const g = groups.get(Number(maintenance[1]))!;
      const s = g.scenario;
      const mode = maintenance[2];
      if (s.scriptInfrastructureFailure || s.scriptInterrupted) return json(200, { lastError: "Swamp maintenance requires investigation" });
      const run = JSON.parse(await Deno.readTextFile(new URL("../fixtures/maintenance-worker/run.json", import.meta.url)));
      const steps = run.jobs[0].steps;
      steps[0].dataArtifacts[0].attributes = { head: g.head, tree: g.tree };
      if (mode === "summary") {
        bump(g, "summary");
        steps.find((step: { name: string }) => step.name === "summary").dataArtifacts = [{ name: "summary", attributes: { text: s.summaryGarbage ? "not a pull request" : SUMMARY } }];
        if (s.summaryFails) steps[1].dataArtifacts = [{ name: "failure", attributes: { exitCode: 1, message: "summary failed" } }];
        if (s.summaryTruncated) steps.find((step: { name: string }) => step.name === "summary").dataArtifacts = [];
      } else {
        const count = bump(g, "maintenance");
        if (!s.scriptNoChange) g.tree = "e".repeat(40);
        if (s.scriptFails || (s.scriptFailsOnce && count === 1)) steps[1].dataArtifacts = [{ name: "failure", attributes: { exitCode: 23, message: "fixture command failed" } }];
      }
      steps.at(-1).dataArtifacts[0].attributes = { head: g.head, tree: g.tree };
      return json(200, run);
    }
    if (p === "/api/v1/task-groups" && req.method === "GET") return json(200,[...groups.values()].map(g=>({...g,scenario:undefined,counts:undefined})));
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
      if (rest === "/comments" && req.method === "GET") {
        return json(200, comments.filter((c) => c.group === g.id && c.subtask === null).toReversed());
      }
      if (rest === "/comments" && req.method === "POST") {
        if (g.orchestration !== "external") return json(409, { error: { code: "tasks.not_orchestrated" } });
        if (["completed", "failed", "cancelled"].includes(g.status)) return json(409, { error: { code: "tasks.group_closed" } });
        if (g.tasks.some((t) => !["completed", "failed", "cancelled"].includes(t.status))) return json(409, { error: { code: "tasks.assistance_subtask_open" } });
        const c = { id: comments.length + 1, group: g.id, subtask: null, task_group_id: g.id, task_id: g.id, type: body.type, body: body.body, author: body.author };
        comments.push(c);
        if (body.type === "assistance_requested") {
          g.assistance_requested = true;
          g.assistance_kind = "direction";
          g.assistance_question = g.assistance_reason = body.body;
        } else if (body.type === "resolution" && g.assistance_kind === "direction") {
          g.assistance_requested = false;
          g.assistance_kind = g.assistance_question = g.assistance_reason = null;
        }
        return json(201, c);
      }
      const sub = /^\/tasks\/(\d+)$/.exec(rest);
      if (req.method === "PATCH" && sub) {
        const t = g.tasks.find((x) => x.id === Number(sub[1]));
        if (!t) return json(404, { error: { code: "http.404" } });
        t.status = body.status;
        return json(200, t);
      }
      const comment = /^\/tasks\/(\d+)\/comments$/.exec(rest);
      if (req.method === "POST" && comment) {
        const t = g.tasks.find((x) => x.id === Number(comment[1]));
        if (!t) return json(404, { error: { code: "http.404" } });
        comments.push({ id: comments.length + 1, group: g.id, subtask: t.id, task_group_id: g.id, task_id: t.id, type: body.type, body: body.body, author: body.author });
        // Orbit's TaskAssistance rules for an external group:
        // - a request on an open subtask flags the subtask and mirrors it on the group;
        // - a request on an ended subtask is refused for the subtask and raises an independent
        //   group request;
        // - a resolution clears the subtask, and clears the group only when that subtask owned the
        //   group's request; the group then shows the first sibling still asking, or clears.
        const ended = ["completed", "failed", "cancelled"].includes(t.status);
        if (body.type === "assistance_requested") {
          if (!ended) {
            t.assistance_requested = true;
            t.assistance_kind = "direction";
            t.assistance_question = t.assistance_reason = body.body;
          }
          g.assistance_requested = true;
          g.assistance_kind = "direction";
          g.assistance_question = g.assistance_reason = body.body;
        } else if (body.type === "resolution") {
          const owns = t.assistance_requested && g.assistance_requested && t.assistance_kind === g.assistance_kind && t.assistance_question === g.assistance_question && t.assistance_reason === g.assistance_reason;
          t.assistance_requested = false;
          t.assistance_kind = t.assistance_question = t.assistance_reason = null;
          if (owns) {
            const pending = [...g.tasks].sort((a, b) => a.position - b.position).find((x) => x.assistance_requested);
            g.assistance_requested = pending !== undefined;
            g.assistance_question = g.assistance_reason = pending?.assistance_question ?? null;
            g.assistance_kind = pending ? "direction" : null;
          }
        }
        return json(201, { id: comments.length, type: body.type });
      }
      if (req.method === "POST" && rest === "/complete") {
        g.status = "completed";
        g.vm_power = "destroyed";
        return json(200, view());
      }
      const op = /^\/(provision|check|analyze|turn|publish)$/.exec(rest);
      if (req.method === "POST" && op) {
        const key = req.headers.get("idempotency-key") ?? "";
        starts.set(key, (starts.get(key) ?? 0) + 1);
        if (byKey.has(key)) return json(202, { ...ops.get(byKey.get(key)!), status: "queued", poll_after_seconds: 1 });
        if (typeof body.subtask === "number") {
          const open = g.tasks
            .filter((t) => !["completed", "failed", "cancelled"].includes(t.status))
            .sort((a, b) => a.position - b.position || a.id - b.id);
          if (open[0]?.id !== body.subtask) {
            return json(409, { error: { code: "tasks.subtask_not_current", message: "Only the current open subtask can run." } });
          }
        }
        const id = nextOp++;
        const out = await run(g, op[1], body, id);
        // StartTaskTurnAction persists TaskTurnData::toArray(), including nullable defaults.
        const input = op[1] === "turn" ? { model: null, check: null, analysis: null, ...body } : body;
        const rec = { id, kind: `tasks.${op[1]}`, target_type: "task_group", target_id: g.id, input, ...out };
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
    projects,
    operations: ops,
    comments,
    commentsOf: (gid: number) => comments.filter((c) => c.group === gid),
    /** Starts per stage name for a group, from the Idempotency-Keys. */
    startsOf: (gid: number, stage: string) => [...starts.keys()].filter((k) => k.startsWith(`tg-${gid}.${stage}.`)).length,
    close: () => server.shutdown(),
  };
}
