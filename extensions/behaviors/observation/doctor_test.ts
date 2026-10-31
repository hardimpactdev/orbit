import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { doctor, summarize } from "./doctor.ts";
import { issue, type NodeIntent, report, type Row } from "./doctor_contract.ts";
import type { Http } from "../automatic-deploy/gateway.ts";
import type { NativeReadCall } from "./doctor_native.ts";

const args = {
  operation_id: 7,
  kind: "doctor.inspect" as const,
  node_id: 14,
  run_id: "123e4567-e89b-42d3-a456-426614174099",
};
const node = (id: number, values: Row = {}): NodeIntent => ({
  id,
  name: `dev-${id}`,
  platform: "linux",
  architecture: "x86_64",
  user: "orbit",
  address: "10.8.0.9",
  managed: true,
  parked: false,
  roles: [],
  resources: {},
  agent_sha256: "a".repeat(64),
  ...values,
});
function fixture(nodes: NodeIntent[] = [node(9)], families: string[] = []) {
  const events: string[] = [];
  const projects: Row[] = [];
  const agent: NativeReadCall[] = [];
  const localCalls: string[][] = [];
  const current = {
    id: args.operation_id,
    kind: args.kind,
    node_id: args.node_id,
    status: "queued",
    result: null,
    cancel_requested: false,
    input: {
      nodes,
      families,
      collect_problems: false,
      logs: undefined as Row | undefined,
    },
  };
  let unbound = false;
  let privateMaterial: Row = {};
  const http: Http = (_method, path, body) => {
    if (path.includes("/private-material")) {
      events.push("private");
      return Promise.resolve({ status: 200, body: { data: privateMaterial } });
    }
    if (path.endsWith("/result")) {
      events.push("project");
      projects.push(body as Row);
      return Promise.resolve({ status: 200, body: { data: {} } });
    }
    if (unbound) {
      unbound = false;
      events.push("unbound");
      return Promise.resolve({
        status: 409,
        body: { error: { code: "operation.run_unbound" } },
      });
    }
    events.push("bound-read");
    return Promise.resolve({
      status: 200,
      body: { data: structuredClone(current) },
    });
  };
  const context = {
    globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/unused" },
  };
  const run = (call: NativeReadCall) => {
    events.push(`agent:${call.name}`);
    agent.push(call);
    return Promise.resolve(
      call.name === "account.lookup"
        ? {
          user: "orbit",
          group: "orbit",
          home: "/home/orbit",
          shell: "/bin/bash",
        }
        : call.name === "doctor.inspect-schedule"
        ? {
          artifacts_present: true,
          permissions_match: true,
          specification_match: true,
          timer_state_match: true,
          calendar_match: true,
          execution_context_match: true,
          completion_callback_match: true,
        }
        : {
          reachable: true,
          platform: "linux",
          architecture: "x86_64",
          wireguard: true,
        },
    );
  };
  const local = (argv: string[]) => {
    events.push("local");
    localCalls.push(argv);
    return Promise.resolve("");
  };
  let logCalls = 0;
  const behavior = doctor(() => http, run, local, () => {
    logCalls++;
    return Promise.resolve({ cursor: null, signals: [] });
  });
  return {
    behavior,
    context,
    events,
    projects,
    agent,
    localCalls,
    current,
    logs: () => logCalls,
    delayBinding: () => {
      unbound = true;
    },
    setPrivate: (material: Row) => {
      privateMaterial = material;
    },
  };
}

Deno.test("root read waits for start-response binding before planning or any observation", async () => {
  const f = fixture();
  f.delayBinding();
  const result = await f.behavior.methods.prepare.execute(args, f.context);
  assertEquals(result, { node_ids: [9] });
  assertEquals(f.events, ["unbound", "bound-read", "project"]);
  assertEquals(f.agent, []);
  assertEquals(f.localCalls, []);
  assertEquals(f.projects[0].run_id, args.run_id);
});

Deno.test("an inspect step rejects a Node outside immutable admitted input before local reads", async () => {
  const f = fixture();
  await assertRejects(
    () =>
      f.behavior.methods.inspect.execute(
        { ...args, target_node_id: 209 },
        f.context,
      ),
    Error,
    "doctor.node_not_admitted",
  );
  assertEquals(f.events, ["bound-read"]);
  assertEquals(f.agent, []);
});

Deno.test("private Schedule commands stay within the observation callback and never enter outputs", async () => {
  const f = fixture([
    node(9, {
      resources: {
        schedules: [{
          id: "schedule-uuid",
          name: "dev-check",
          placement_node_id: 9,
          user: "orbit",
          input: { id: "schedule-uuid" },
        }],
      },
    }),
  ], ["schedule"]);
  f.setPrivate({ schedules: { "schedule-uuid": "private-sentinel-command" } });
  const result = await f.behavior.methods.inspect.execute({
    ...args,
    target_node_id: 9,
  }, f.context);
  assert("healthy" in result);
  assertEquals(result.healthy, true);
  assert(
    f.agent.some((call) =>
      call.name === "doctor.inspect-schedule" &&
      call.input.command === "private-sentinel-command"
    ),
  );
  assert(!JSON.stringify(result).includes("private-sentinel-command"));
  assert(!JSON.stringify(f.projects).includes("private-sentinel-command"));
  assertEquals(f.events[0], "bound-read");
  assert(
    f.agent.every((call) => call.node.id === 9),
  );
});

Deno.test("a missing worker produces unverifiable reports without recovery scope", async () => {
  const f = fixture([node(9)], ["node", "process", "role"]);
  const result = await f.behavior.methods.finish.execute({
    ...args,
    node_results: {},
  }, f.context);
  assertEquals(result.status, "succeeded");
  const projected = f.projects.at(-1)!.result as Row;
  const resultReport = projected.report as Row;
  const families = (resultReport.nodes as Row[])[0].families as Row[];
  assertEquals(resultReport.healthy, false);
  assertEquals(families.map((family) => family.status), [
    "unverifiable",
    "unverifiable",
    "unverifiable",
  ]);
  assertEquals(families.map((family) => family.observed_resources), [
    [],
    [],
    [],
  ]);
  assertEquals(families.map((family) => family.observed_checks), [{}, {}, {}]);
  assertEquals(families.map((family) => family.observed_inventories), [
    {},
    {},
    {},
  ]);
  assertEquals(f.agent, []);
});

Deno.test("parked and unmanaged Nodes are not scheduled for reads", async () => {
  const parked = node(10, { parked: true });
  const unmanaged = node(11, {
    managed: false,
    resources: { node_checks: [{ managed: false, status: "active" }] },
  });
  const f = fixture([parked, unmanaged], ["node", "role"]);
  assertEquals(await f.behavior.methods.prepare.execute(args, f.context), {
    node_ids: [],
  });
  await f.behavior.methods.finish.execute(
    { ...args, node_results: {} },
    f.context,
  );
  const result = (f.projects.at(-1)!.result as Row).report as Row;
  assertEquals(result.healthy, true);
  assertEquals(result.summary, {
    nodes: 2,
    families: 4,
    checks: 2,
    drift: 0,
    unverifiable: 0,
    informational: 1,
  });
  assertEquals(f.agent, []);
  assertEquals(f.localCalls, []);
});

Deno.test("summary treats informational issues as healthy and counts all issue classes", () => {
  const informational = report("node", 1, [
    issue(
      "node",
      "node.task_vm_parked",
      node(9),
      "Parked.",
      null,
      "parked",
      "informational",
    ),
  ]);
  const drift = report("role", 2, [
    issue("role", "role.services_inactive", 1, "Inactive."),
  ]);
  const unverifiable = report("process", 1, [
    issue(
      "process",
      "process.inspection_failed",
      2,
      "Unverifiable.",
      null,
      null,
      "unverifiable",
    ),
  ]);
  const result = summarize([{
    node_id: 9,
    node_name: "dev",
    healthy: false,
    families: [informational, drift, unverifiable],
  }]);
  assertEquals(result.summary, {
    nodes: 1,
    families: 3,
    checks: 4,
    drift: 1,
    unverifiable: 1,
    informational: 1,
  });
  assertEquals(result.healthy, false);
  assertEquals(
    summarize([{
      node_id: 9,
      node_name: "dev",
      healthy: true,
      families: [informational],
    }]).healthy,
    true,
  );
});

Deno.test("cancellation avoids every Node and log read and records cancellation", async () => {
  const f = fixture([node(9)], ["node"]);
  f.current.cancel_requested = true;
  f.current.input.collect_problems = true;
  f.current.input.logs = {
    log_directory: "/unused",
    base_directory: "/unused",
  };
  assertEquals(await f.behavior.methods.prepare.execute(args, f.context), {
    node_ids: [],
  });
  assertEquals(
    await f.behavior.methods.inspect.execute(
      { ...args, target_node_id: 9 },
      f.context,
    ),
    { cancelled: true },
  );
  assertEquals(
    await f.behavior.methods.finish.execute(
      { ...args, node_results: {} },
      f.context,
    ),
    { status: "cancelled" },
  );
  assertEquals(f.agent, []);
  assertEquals(f.localCalls, []);
  assertEquals(f.logs(), 0);
  assertEquals(f.projects.at(-1)!.status, "cancelled");
});
