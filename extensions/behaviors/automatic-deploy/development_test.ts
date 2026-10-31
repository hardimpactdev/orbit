import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { tickDevelopment } from "./development.ts";
import type { Http } from "./gateway.ts";
import type { Operation, Row } from "./policy.ts";
import { PolicyError } from "./policy.ts";
const A = "a".repeat(40), B = "b".repeat(40), now = "2026-10-31T05:00:00.000Z";
function fixture() {
  const instance = {
    id: 7,
    node_id: 9,
    name: "default",
    status: "active",
    source_layout: "checkout",
    seed_commit: A,
    development_projection_route_id: null as number | null,
    route: null as { id: number } | null,
    auto_deploy: {
      policy_owner: "swamp",
      resumed_after: 0,
      history_version: "f".repeat(64),
    },
  };
  const listed: unknown[] = [instance];
  const memory = new Map<string, Record<string, unknown>>(),
    requests: { method: string; path: string; body: unknown; key?: string }[] =
      [];
  const history: Row[] = [], starts: string[] = [];
  let holder = false,
    lost = false,
    busy = false,
    receiptReads = 0,
    repairStatus = "queued";
  const operation: Operation = {
    id: 100,
    kind: "instance.deploy",
    status: "queued",
    input: { commit: B },
    triggered_by: "swamp:auto-deploy",
    result: null,
  };
  const http: Http = (method, path, body, key) => {
    requests.push({ method, path, body, key });
    let data: unknown;
    if (path === "/api/v1/nodes") data = [{ id: 9, roles: ["app-dev"] }];
    else if (path === "/api/v1/instances") data = listed;
    else if (path === "/api/v1/instances/7") data = instance;
    else if (path.endsWith("/deployments")) data = history;
    else if (path.endsWith("/head")) data = { head: B };
    else if (path.includes("/operations?")) {
      data = holder && path.endsWith("queued")
        ? [{ ...operation, target_type: "instance", target_id: 7 }]
        : [];
    } else if (path === "/api/v1/routes/3" && method === "PATCH") {
      data = { id: 200, status: repairStatus };
    } else if (path.endsWith("/policy") && method === "PUT") {
      if (
        (body as { route_operation_id?: number }).route_operation_id === 200
      ) instance.development_projection_route_id = null;
      data = {};
    } else throw new Error("Unexpected HTTP request");
    return Promise.resolve({
      status: 200,
      body: { data: structuredClone(data) },
    });
  };
  const admissions = {
    event: () =>
      Promise.resolve({ ...operation, id: 201, kind: "instance.policy_event" }),
    deploy: (_id: number, commit: string, key: string) => {
      assertEquals(
        (memory.get("development-7")?.pending as { key: string }).key,
        key,
      );
      assertEquals(commit, B);
      if (busy) throw new PolicyError("operation.target_busy", "Busy", 110);
      starts.push(key);
      if (lost) throw new Error("Lost admission reply");
      return Promise.resolve(structuredClone(operation));
    },
    receipt: () => {
      receiptReads++;
      return Promise.resolve(structuredClone(operation));
    },
    resolve: () => {
      throw new Error("An existing receipt must not be fenced");
    },
    operation: (id: number) =>
      Promise.resolve(
        id === 200
          ? {
            ...operation,
            id: 200,
            kind: "route.update",
            status: repairStatus,
          }
          : structuredClone(operation),
      ),
  };
  const ctx = {
    readResource: (name: string) => Promise.resolve(memory.get(name) ?? null),
    writeResource: (
      _spec: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      memory.set(name, structuredClone(value));
      return Promise.resolve({ name });
    },
  };
  const run = () => tickDevelopment(http, ctx, admissions, now);
  return {
    instance,
    listed,
    memory,
    requests,
    history,
    starts,
    operation,
    run,
    busy: () => {
      busy = true;
    },
    holder: () => {
      holder = true;
    },
    lost: () => {
      lost = true;
    },
    repairDone: () => {
      repairStatus = "succeeded";
    },
    receiptReads: () => receiptReads,
  };
}
function failed(id: number, manual = false): Row {
  return {
    id,
    kind: "deploy",
    status: "failed",
    commit: B,
    triggered_by: manual ? "operator" : "orbit:auto-deploy",
    failed_step: "preparation",
    error_code: "deployment.interrupted",
    started_at: now,
    finished_at: now,
  };
}
Deno.test("development policy admits only after Gateway ownership switches", async () => {
  const f = fixture();
  f.instance.auto_deploy.policy_owner = "gateway";
  await f.run();
  assertEquals(f.starts, []);
  assertEquals(f.memory.size, 0);
});
Deno.test("native development policy saves the exact pinned intent before admission", async () => {
  const f = fixture();
  await f.run();
  assertEquals(f.starts.length, 1);
  assertEquals(
    (f.memory.get("development-7")?.pending as { operation_id: number })
      .operation_id,
    100,
  );
  assertEquals(
    f.requests.some((r) => /child|executor|secret/.test(r.path)),
    false,
  );
});
Deno.test("an existing target hold starts no competing deployment", async () => {
  const f = fixture();
  f.holder();
  await f.run();
  assertEquals(f.starts, []);
  assertEquals(f.memory.get("development-7")?.result, "busy");
});
Deno.test("target_busy at admission clears only the unadmitted intent", async () => {
  const f = fixture();
  f.busy();
  await f.run();
  assertEquals(f.starts, []);
  assertEquals(f.memory.get("development-7")?.pending, null);
  assertEquals(f.memory.get("development-7")?.result, "busy");
});
Deno.test("a lost admission response follows its receipt without a second start", async () => {
  const f = fixture();
  f.lost();
  await assertRejects(f.run);
  await f.run();
  assertEquals(f.starts.length, 1);
  assertEquals(f.receiptReads(), 1);
  assertEquals(f.memory.get("development-7")?.result, "deploying");
});
Deno.test("terminal work without history cannot authorize a new effect", async () => {
  const f = fixture();
  await f.run();
  f.operation.status = "failed";
  await f.run();
  await f.run();
  assertEquals(f.starts.length, 1);
  assertEquals(
    (f.memory.get("development-7")?.pending as { operation_id: number })
      .operation_id,
    100,
  );
});
Deno.test("development retries and backoff are owned by Swamp and reset after manual work", async () => {
  for (const count of [1, 2, 3]) {
    const f = fixture();
    for (let id = 1; id <= count; id++) {
      f.history.push({
        ...failed(id),
        error_code: "agent.version_mismatch",
        failed_step: "source_preparation",
      });
    }
    await f.run();
    assertEquals(f.starts, []);
    assertEquals(
      f.memory.get("development-7")?.result,
      count === 3 ? "commit_failed" : "backing_off",
    );
    f.history.push(failed(count + 1, true));
    await f.run();
    assertEquals(f.starts.length, 1);
  }
});
Deno.test("pending publication uses the normal Route root before dev deployment", async () => {
  const f = fixture();
  f.instance.development_projection_route_id = 3;
  f.instance.route = { id: 3 };
  await f.run();
  assertEquals(f.starts, []);
  const patches = f.requests.filter((r) => r.method === "PATCH");
  assertEquals(patches.length, 1);
  assertEquals(patches[0].body, {});
  f.repairDone();
  await f.run();
  assertEquals(f.starts, []);
  assertEquals(f.instance.development_projection_route_id, null);
  await f.run();
  assertEquals(f.starts.length, 1);
  assertEquals(f.requests.filter((r) => r.method === "PATCH").length, 1);
});

Deno.test("only a proven zero-effect admission fence clears a pending development intent", async () => {
  for (
    const receipt of [
      { attempts: 0, result: null },
      { attempts: 1, result: { admission_fenced: true } },
      { attempts: 0, result: { admission_fenced: true, deployment_id: 7 } },
      { attempts: 0, result: { admission_fenced: true } },
    ]
  ) {
    const f = fixture();
    await f.run();
    Object.assign(f.operation, receipt, {
      status: "cancelled",
      error_code: "operation.admission_fenced",
    });
    await f.run();
    assertEquals(
      f.memory.get("development-7")?.pending === null,
      receipt.attempts === 0 && receipt.result?.admission_fenced === true &&
        !("deployment_id" in receipt.result),
    );
    assertEquals(f.starts.length, 1);
  }
});

Deno.test("unrelated production and non-default checkouts with null history cannot block dev discovery", async () => {
  const f = fixture();
  f.listed.unshift({
    ...f.instance,
    id: 8,
    node_id: 4,
    source_layout: "release",
    auto_deploy: { ...f.instance.auto_deploy, history_version: null },
  });
  f.listed.push({
    ...f.instance,
    id: 9,
    name: "feature",
    auto_deploy: { ...f.instance.auto_deploy, history_version: null },
  });
  await f.run();
  assertEquals(f.starts.length, 1);
  assertEquals(f.requests.filter((r) => /instances\/(8|9)\//.test(r.path)), []);
});

Deno.test("a default with no authoritative Route deploys even when its display Route is unrelated", async () => {
  const f = fixture();
  f.instance.route = { id: 999 };
  await f.run();
  assertEquals(f.starts.length, 1);
  assertEquals(f.requests.filter((r) => r.method === "PATCH"), []);
});
Deno.test("native-first deployment skips the old Gateway dev owner before reading new fields", async () => {
  const f = fixture();
  f.listed.splice(0, 1, {
    id: 7,
    node_id: 9,
    name: "default",
    status: "active",
    source_layout: "checkout",
    auto_deploy: { policy_owner: "gateway" },
  });
  await f.run();
  assertEquals(f.starts, []);
  assertEquals(f.requests.some((r) => r.path === "/api/v1/instances/7"), false);
});
