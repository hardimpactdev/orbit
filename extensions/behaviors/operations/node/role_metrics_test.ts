import {
  assertEquals,
  assertRejects,
  assertStringIncludes,
} from "jsr:@std/assert@1.0.19";
import { roleMetrics } from "./role_metrics.ts";
import { roleMetricsJobs } from "./role_metrics_workflow.ts";
import { type NodePlan, Refused } from "../integrations/metrics_fleet_local.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

const args = {
  operation_id: 17,
  node_id: 13,
  kind: "node.role_add" as const,
  run_id: "46dff6be-7de2-4985-b891-6ebcd0897656",
};
const node = (id: number, worker = true): NodePlan => ({
  node_id: id,
  address: `10.44.0.${id}`,
  agent_sha256: "a".repeat(64),
  worker,
  selected: true,
  caddy_managed: false,
  fpm: false,
  service: { config: null },
  pools: [],
});
const ctx = {
  globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/ca.pem" },
  writeResource: (_spec: string, _name: string, _value: unknown) =>
    Promise.resolve({}),
};
function fixture() {
  const current = {
    id: 17,
    node_id: 13,
    kind: "node.role_add",
    status: "running",
    cancel_requested: false,
    input: {
      role: "database",
      metrics_fleet: {
        metrics_node_id: 9,
        metrics_address: "10.44.0.9",
        fleet: [node(1, false), node(9), node(13), node(18, false)],
      } as unknown,
    },
  };
  const resources: unknown[] = [], calls: string[] = [];
  const secret = "synthetic-private-password-no-resource";
  const http: Http = (method, path) => {
    calls.push(method + " " + path);
    return Promise.resolve({
      status: 200,
      body: {
        data: path.includes("private-material")
          ? {
            metrics_runtime: {
              runtime: {
                operation: "converge",
                configuration: {
                  "/etc/orbit/metrics/grafana/admin-password": secret,
                },
              },
              verify: { operation: "verify", password: secret },
            },
          }
          : current,
      },
    });
  };
  const stages: string[] = [];
  const local = (
    phase: string,
    plan: NodePlan,
    _address: string,
    _before?: unknown,
    signal?: AbortSignal,
  ) => {
    stages.push(phase + ":" + plan.node_id + ":" + String(signal?.aborted));
    return Promise.resolve({});
  };
  const behavior = roleMetrics(() => http, local);
  return {
    current,
    http,
    resources,
    secret,
    calls,
    stages,
    local,
    behavior,
    ctx: {
      ...ctx,
      writeResource: (_spec: string, _name: string, value: unknown) => {
        resources.push(value);
        return Promise.resolve({});
      },
    },
  };
}
Deno.test("role Metrics capture preserves full1/18 inventory and refuses fleet availability without effects", async () => {
  const f = fixture();
  const result = await f.behavior.methods.capture.execute(args, f.ctx) as {
    ids: number[];
    available: boolean;
  };
  assertEquals(result.ids, [1, 9, 13, 18]);
  assertEquals(result.available, false);
  assertEquals(f.stages, []);
  assertEquals(f.calls.length, 1);
});
Deno.test("no Metrics assignment is explicit no-contribution; Metrics removal never rebuilds the removed runtime", async () => {
  const f = fixture();
  f.current.input.metrics_fleet = null;
  const empty = await f.behavior.methods.capture.execute(args, f.ctx) as {
    plan: unknown;
    ids: number[];
    available: boolean;
    runtime_required: boolean;
  };
  assertEquals(empty, {
    plan: null,
    ids: [],
    available: true,
    runtime_required: false,
  });
  const removing = fixture();
  removing.current.kind = "node.role_remove";
  removing.current.input.role = "metrics";
  const result = await removing.behavior.methods.capture.execute({
    ...args,
    kind: "node.role_remove",
  }, removing.ctx) as { runtime_required: boolean };
  assertEquals(result.runtime_required, false);
});
Deno.test("role Metrics stage cancels before effects and restores confirmed state despite cancellation", async () => {
  const f = fixture();
  f.current.cancel_requested = true;
  const input = {
    ...args,
    phase: "apply" as const,
    node: node(13),
    metrics_address: "10.44.0.9",
  };
  assertEquals(await f.behavior.methods.stage.execute(input, f.ctx), {
    ok: false,
    error_code: "operation.cancelled",
  });
  await f.behavior.methods.stage.execute({
    ...input,
    phase: "restore",
    snapshot: {},
  }, { ...f.ctx, signal: AbortSignal.abort() });
  assertEquals(f.stages, ["restore:13:undefined"]);
});
Deno.test("role Metrics bounds confirmed lower refusals while preserving uncertain effects", async () => {
  const f = fixture();
  for (
    const failure of [
      new Refused("metrics.pool_unverified"),
      new Error("unknown outcome"),
    ]
  ) {
    const behavior = roleMetrics(() => f.http, () => Promise.reject(failure));
    const input = {
      ...args,
      phase: "service" as const,
      node: node(13),
      metrics_address: "10.44.0.9",
    };
    if (failure instanceof Refused) {
      assertEquals(await behavior.methods.stage.execute(input, f.ctx), {
        ok: false,
        error_code: failure.code,
      });
    } else {
      await assertRejects(
        () => behavior.methods.stage.execute(input, f.ctx),
        Error,
        failure.message,
      );
    }
  }
});
Deno.test("role Metrics runtime verifies credentials then commits in one callback without persisting material", async () => {
  const f = fixture();
  const order: string[] = [];
  const behavior = roleMetrics(() => f.http, f.local, {
    credential: ({ action }) => {
      assertEquals(action.password, f.secret);
      order.push("verify");
      return Promise.resolve({ verified: true });
    },
    runtime: ({ action }) => {
      assertStringIncludes(JSON.stringify(action), f.secret);
      order.push("runtime");
      return Promise.resolve({ changed: true });
    },
  });
  assertEquals(
    await behavior.methods.runtime.execute({ ...args, node: node(9) }, f.ctx),
    { ok: true, committed: true },
  );
  assertEquals(order, ["verify", "runtime"]);
  assertEquals(JSON.stringify(f.resources).includes(f.secret), false);
  assertEquals(f.calls.some((call) => call.includes("secret-output")), false);
});
Deno.test("runtime refusal is rollback eligible; effect uncertainty and postcommit cleanup are not", async () => {
  const f = fixture();
  const input = { ...args, node: node(9) };
  const refused = roleMetrics(() => f.http, f.local, {
    credential: () => Promise.resolve({ verified: false }),
    runtime: () => {
      throw new Error("not reached");
    },
  });
  assertEquals(await refused.methods.runtime.execute(input, f.ctx), {
    ok: false,
    committed: false,
    error_code: "metrics.credentials_unverified",
  });
  const uncertain = roleMetrics(() => f.http, f.local, {
    credential: () => Promise.resolve({ verified: true }),
    runtime: () => Promise.reject(new Error(f.secret)),
  });
  await assertRejects(
    () => uncertain.methods.runtime.execute(input, f.ctx),
    Error,
    "Private material callback failed",
  );
  const committed = roleMetrics(() => f.http, f.local, {
    credential: () => Promise.resolve({ verified: true }),
    runtime: () =>
      Promise.resolve({ error_code: "metrics.container_cleanup_failed" }),
  });
  assertEquals(await committed.methods.runtime.execute(input, f.ctx), {
    ok: false,
    committed: true,
    error_code: "metrics.container_cleanup_failed",
  });
  assertEquals(JSON.stringify(f.resources).includes(f.secret), false);
});
Deno.test("role Metrics generator keeps one root, exact placements, and rollback before runtime commit", () => {
  const generated = roleMetricsJobs("runtime_ready_job");
  const text = JSON.stringify(generated);
  assertStringIncludes(text, "@nckrtl/node-role-metrics");
  assertEquals(text.includes("node-agent"), false);
  assertEquals(text.includes("workflow.run"), false);
  assertStringIncludes(text, "committed");
  const jobs = generated.jobs as {
    name: string;
    steps: { target?: string; labels?: unknown; forEach?: unknown }[];
  }[];
  for (const job of jobs) {
    for (const step of job.steps) {
      if (step.forEach) {
        assertEquals(step.target, '${{ "orbit-node-" + string(self.id) }}');
        assertEquals(step.labels, { node: "${{ string(self.id) }}" });
      }
    }
  }
  assertEquals(jobs.at(-1)?.name, generated.lastJob);
});

Deno.test("own Metrics role initializes once before verifying its existing credential type", async () => {
  const f = fixture();
  f.current.input.role = "metrics";
  f.current.input.metrics_fleet = {
    metrics_node_id: 13,
    metrics_address: "10.44.0.13",
    fleet: [node(13)],
  };
  const order: string[] = [];
  const behavior = roleMetrics(() => f.http, f.local, {
    runtime: () => {
      order.push("runtime");
      return Promise.resolve({ changed: true });
    },
    credential: () => {
      order.push("verify");
      return Promise.resolve({ verified: true });
    },
  });
  assertEquals(
    await behavior.methods.runtime.execute({ ...args, node: node(13) }, f.ctx),
    { ok: true, committed: true },
  );
  assertEquals(order, ["runtime", "verify"]);
});

function enrollmentFixture() {
  const f = fixture();
  f.current.node_id = 14;
  f.current.kind = "node.add";
  Reflect.deleteProperty(f.current.input, "role");
  const enrollment = {
    schema_version: 1,
    node_id: 13,
    executor_node_id: 14,
    candidate: { user: "orbit", wireguard_ip: "10.44.0.13" },
    role_snapshots: [{
      schema_version: 1,
      node_id: 13,
      assignment_id: 27,
      role: "metrics",
      agent_sha256: "a".repeat(64),
      user: "orbit",
    }],
  };
  Object.assign(f.current.input, enrollment);
  f.current.input.metrics_fleet = {
    metrics_node_id: 13,
    metrics_address: "10.44.0.13",
    fleet: [node(13)],
  };
  return {
    ...f,
    enrollment,
    args: { ...args, kind: "node.add" as const, node_id: 14 },
  };
}

Deno.test("node.add initializes its captured Metrics guest while retaining the Gateway root", async () => {
  const f = enrollmentFixture();
  const order: string[] = [];
  const behavior = roleMetrics(() => f.http, f.local, {
    runtime: ({ address }) => {
      assertEquals(address, "10.44.0.13");
      order.push("runtime");
      return Promise.resolve({ changed: true });
    },
    credential: () => {
      order.push("verify");
      return Promise.resolve({ verified: order.includes("runtime") });
    },
  });
  assertEquals(
    await behavior.methods.runtime.execute(
      { ...f.args, node: node(13) },
      f.ctx,
    ),
    { ok: true, committed: true },
  );
  assertEquals(order, ["runtime", "verify"]);
  assertEquals(f.current.node_id, 14);
  assertEquals(f.current.kind, "node.add");
  assertEquals("role" in f.current.input, false);
  assertEquals(JSON.stringify(f.resources).includes(f.secret), false);
});

Deno.test("node.add without a captured Metrics assignment verifies the serving runtime despite a top-level role", async () => {
  const f = enrollmentFixture();
  f.enrollment.role_snapshots[0].role = "database";
  f.current.input.role = "metrics";
  f.current.input.metrics_fleet = {
    metrics_node_id: 9,
    metrics_address: "10.44.0.9",
    fleet: [node(9), node(13)],
  };
  let runtimeCalls = 0;
  const behavior = roleMetrics(() => f.http, f.local, {
    credential: () => Promise.resolve({ verified: false }),
    runtime: () => {
      runtimeCalls++;
      return Promise.resolve({ changed: true });
    },
  });
  assertEquals(
    await behavior.methods.runtime.execute({ ...f.args, node: node(9) }, f.ctx),
    {
      ok: false,
      committed: false,
      error_code: "metrics.credentials_unverified",
    },
  );
  assertEquals(runtimeCalls, 0);
});

Deno.test("node.add refuses foreign or incomplete Metrics enrollment bindings before private reads", async () => {
  const mutations = [
    (f: ReturnType<typeof enrollmentFixture>) => {
      f.enrollment.schema_version = 2;
      Object.assign(f.current.input, f.enrollment);
    },
    (f: ReturnType<typeof enrollmentFixture>) => {
      Object.assign(f.current.input, { node_id: 99 });
    },
    (f: ReturnType<typeof enrollmentFixture>) => {
      Object.assign(f.current.input, { executor_node_id: 99 });
    },
    (f: ReturnType<typeof enrollmentFixture>) => {
      f.enrollment.candidate.wireguard_ip = "10.44.0.99";
    },
    (f: ReturnType<typeof enrollmentFixture>) => {
      f.enrollment.candidate.user = "other";
    },
    (f: ReturnType<typeof enrollmentFixture>) => {
      f.enrollment.role_snapshots[0].node_id = 99;
    },
    (f: ReturnType<typeof enrollmentFixture>) => {
      f.enrollment.role_snapshots[0].assignment_id = 0;
    },
    (f: ReturnType<typeof enrollmentFixture>) => {
      f.enrollment.role_snapshots[0].agent_sha256 = "b".repeat(64);
    },
    (f: ReturnType<typeof enrollmentFixture>) => {
      f.enrollment.role_snapshots.push({ ...f.enrollment.role_snapshots[0] });
    },
    (f: ReturnType<typeof enrollmentFixture>) => {
      Reflect.deleteProperty(f.current.input, "role_snapshots");
    },
  ];
  for (const mutate of mutations) {
    const f = enrollmentFixture();
    mutate(f);
    await assertRejects(
      () =>
        f.behavior.methods.runtime.execute(
          { ...f.args, node: node(13) },
          f.ctx,
        ),
      Error,
      "metrics.owner_mismatch",
    );
    assertEquals(
      f.calls.some((call) => call.includes("private-material")),
      false,
    );
    assertEquals(f.resources, []);
  }
});

Deno.test("an enrollment snapshot never relaxes a standalone Metrics root's Node binding", async () => {
  const f = enrollmentFixture();
  f.current.kind = "node.role_add";
  f.current.input.role = "metrics";
  await assertRejects(
    () =>
      f.behavior.methods.runtime.execute({
        ...f.args,
        kind: "node.role_add",
        node: node(13),
      }, f.ctx),
    Error,
    "metrics.owner_mismatch",
  );
  assertEquals(
    f.calls.some((call) => call.includes("private-material")),
    false,
  );
});
Deno.test("own Metrics post-initialization verification failure or late cancellation retains runtime commit", async () => {
  for (const failure of ["refused", "unknown", "cancelled"]) {
    const f = fixture();
    f.current.input.role = "metrics";
    f.current.input.metrics_fleet = {
      metrics_node_id: 13,
      metrics_address: "10.44.0.13",
      fleet: [node(13)],
    };
    const controller = new AbortController();
    let runtimeCalls = 0;
    const behavior = roleMetrics(() => f.http, f.local, {
      runtime: () => {
        runtimeCalls++;
        if (failure === "cancelled") controller.abort();
        return Promise.resolve({ changed: true });
      },
      credential: () =>
        failure === "unknown"
          ? Promise.reject(new Error(f.secret))
          : Promise.resolve({ verified: false }),
    });
    assertEquals(
      await behavior.methods.runtime.execute({ ...args, node: node(13) }, {
        ...f.ctx,
        signal: controller.signal,
      }),
      {
        ok: false,
        committed: true,
        error_code: failure === "cancelled"
          ? "operation.cancelled"
          : "metrics.credentials_unverified",
      },
    );
    assertEquals(runtimeCalls, 1);
    assertEquals(JSON.stringify(f.resources).includes(f.secret), false);
  }
});
Deno.test("role Metrics runtime refuses owner/address drift before private material or effects", async () => {
  const f = fixture();
  await assertRejects(
    () =>
      f.behavior.methods.runtime.execute({ ...args, node: node(13) }, f.ctx),
    Error,
    "metrics.owner_mismatch",
  );
  assertEquals(
    f.calls.some((call) => call.includes("private-material")),
    false,
  );
});

Deno.test("fleet checkpoint refuses a failed aggregate before the next native phase", async () => {
  const f = fixture();
  await assertRejects(
    () =>
      f.behavior.methods.checkpoint.execute({
        ...args,
        phase: "service",
        ready: false,
      }, f.ctx),
    Error,
    "metrics.service_failed",
  );
  assertEquals(f.stages, []);
  assertEquals(
    await f.behavior.methods.checkpoint.execute({
      ...args,
      phase: "verify",
      ready: true,
    }, f.ctx),
    { ok: true },
  );
  f.current.id = 99;
  await assertRejects(
    () =>
      f.behavior.methods.checkpoint.execute({
        ...args,
        phase: "verify",
        ready: true,
      }, f.ctx),
    Error,
    "metrics.root_mismatch",
  );
});

Deno.test("Node removal excludes the offline target while retaining approved light-box proxy transport", async () => {
  const f = fixture();
  f.current.kind = "node.remove";
  Object.assign(f.current.input, {
    metrics_excluded_node_ids: [18],
    proxy_node_ids: [1],
  });
  const result = await f.behavior.methods.capture.execute({
    ...args,
    kind: "node.remove",
  }, f.ctx) as { ids: number[]; available: boolean };
  assertEquals(result.ids, [1, 9, 13]);
  assertEquals(result.available, true);
  assertEquals(f.stages, []);
});
