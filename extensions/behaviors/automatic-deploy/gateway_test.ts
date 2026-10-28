import {
  assertEquals,
  assertRejects,
  assertStringIncludes,
} from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { automaticDeploy } from "./mod.ts";
import { GatewayClient, type Http, type Response } from "./gateway.ts";
import { PolicyError, StateSchema, trigger } from "./policy.ts";
import {
  A,
  B,
  C,
  FakeGateway,
  start,
} from "../../../tests/_support/automatic_deploy.ts";

function fixture() {
  const fake = new FakeGateway();
  const instance = {
    id: 7,
    node_id: 2,
    project_id: 3,
    status: "active",
    auto_deploy: {
      enabled: true,
      policy_owner: "swamp" as "swamp" | "gateway" | undefined,
      gate: "Required checks",
      warning: null,
      resumed_after: 1,
      blocked: [] as string[],
      history_version: "f".repeat(64),
      result: "gate_pending",
      commit: B,
      head: B,
      since: start,
      error_code: null,
      message: null,
      pause: null,
    },
  };
  const walk = {
    head: B,
    reached_base: true,
    truncated: false,
    commits: [{ sha: B, parents: [A], descends_from_deployed: true }],
  };
  const operation = {
    id: 100,
    target_type: "instance",
    target_id: 7,
    kind: "instance.deploy",
    input: { commit: B, caller_node_id: 2 },
    status: "queued",
    attempts: 0,
    triggered_by: trigger,
    result: null as Record<string, unknown> | null,
    error_code: null as string | null,
  };
  const operations = new Map<number, Record<string, unknown>>([[
    100,
    operation,
  ]]);
  const requests: {
    method: string;
    path: string;
    body: unknown;
    key?: string;
  }[] = [];
  const data = new Map<string, Record<string, unknown>>();
  const nodes = [
    { id: 2, roles: ["app-prod"] },
    { id: 3, roles: ["app-dev"] },
    { id: 4, roles: ["services"] },
  ];
  const instances = [instance];
  let rows = [fake.observation.history[0]];
  let holder: typeof operation | null = null;
  let found = false;
  let runs = [{
    name: "Required checks",
    head_sha: B as string | null,
    app: "github-actions" as string | null,
    status: "completed" as string | null,
    conclusion: "success" as string | null,
  }];
  let intercept:
    | ((
      method: string,
      path: string,
      body: unknown,
      key?: string,
    ) => Response | undefined)
    | undefined;
  const http: Http = (method, path, body, key) =>
    Promise.resolve().then(() => {
      requests.push({ method, path, body, key });
      const override = intercept?.(method, path, body, key);
      if (override) return structuredClone(override);
      let response: unknown;
      if (method === "POST" && path.endsWith("/deploy/automatic")) {
        // Verify the real behavior persisted its intent before this effect boundary.
        const state = StateSchema.parse(data.get("state-7"));
        assertEquals(state.intent?.key, key);
        assertEquals(state.intent?.commit, (body as { commit: string }).commit);
        holder = operation;
        response = operation;
      } else if (method === "POST" && path.endsWith("/events")) {
        const pending = StateSchema.parse(data.get("state-7")).events.find((
          e,
        ) => e.key === key);
        assertEquals(pending?.body, body);
        const event = {
          ...operation,
          id: 200 + operations.size,
          kind: "instance.policy_event",
          target_type: "instance_policy",
          input: { ...(body as Record<string, unknown>), caller_node_id: 2 },
          status: "queued",
          error_code: null,
          result: null,
        };
        operations.set(event.id, event);
        response = event;
      } else if (method === "PUT" && path.endsWith("/policy")) {
        const publication = body as Record<string, unknown>;
        if (
          publication.history_version !==
            instance.auto_deploy.history_version ||
          publication.resumed_after !== instance.auto_deploy.resumed_after ||
          publication.last_deployment_id !==
            Math.max(0, ...rows.map((r) => r.id))
        ) return refusal("instance.auto_deploy_observation_changed");
        Object.assign(
          instance.auto_deploy,
          Object.fromEntries(
            [
              "result",
              "commit",
              "head",
              "since",
              "error_code",
              "message",
              "pause",
            ]
              .map((key) => [key, publication[key]]),
          ),
        );
        response = instance;
      } else if (method === "POST" && path.endsWith("/automatic/resolve")) {
        response = {
          request_key: `auto.7.${B}.1`,
          found: true,
          operation,
          deployment: null,
        };
      } else if (method !== "GET") {
        throw new Error(`Unexpected effect: ${method} ${path}`);
      } else if (path === "/api/v1/nodes") response = nodes;
      else if (path === "/api/v1/instances?auto_deploy=true") {
        response = instances;
      } else if (path === "/api/v1/instances/7") response = instance;
      else if (path === "/api/v1/nodes/2") response = nodes[0];
      else if (path === "/api/v1/projects/3") {
        response = {
          repository_url: "https://github.com/acme/shop.git",
          source_access: "github_app",
        };
      } else if (path.endsWith("/deployments")) response = rows;
      else if (path.startsWith("/api/v1/operations?")) {
        response = holder && path.endsWith(`status=${holder.status}`)
          ? [holder]
          : [];
      } else if (path.endsWith("/head")) response = { head: B };
      else if (path.includes("/commits?")) response = walk;
      else if (path.includes("/checks?")) {
        response = { gate: instance.auto_deploy.gate, runs };
      } else if (path.includes("/receipt?")) {
        response = {
          request_key: `auto.7.${B}.1`,
          found,
          operation: found ? operation : null,
          deployment: null,
        };
      } else if (path.startsWith("/api/v1/operations/")) {
        response = operations.get(Number(path.split("/").at(-1)));
      } else throw new Error("Unexpected request: " + path);
      return structuredClone({
        status: method === "POST" ? 202 : 200,
        body: { data: response, meta: { request_id: "fixture" } },
      });
    });
  const client = new GatewayClient(http);
  const behavior = automaticDeploy(client, () => fake.now);
  const { context } = createModelTestContext({ globalArgs: {} });
  const write = context.writeResource.bind(context);
  const ctx = Object.assign(context, {
    readResource: (name: string) =>
      Promise.resolve(structuredClone(data.get(name) ?? null)),
    writeResource: async (
      spec: "state",
      name: string,
      value: Record<string, unknown>,
    ) => {
      behavior.resources[spec].schema.parse(value);
      data.set(name, structuredClone(value));
      return await write(spec, name, value);
    },
  });
  return {
    fake,
    instance,
    instances,
    nodes,
    walk,
    operation,
    operations,
    requests,
    data,
    ctx,
    http,
    client,
    behavior,
    setRows: (value: typeof rows) => rows = value,
    setHolder: (value: typeof operation | null) => holder = value,
    setRuns: (value: typeof runs) => runs = value,
    setFound: (value: boolean) => found = value,
    intercept: (fn: NonNullable<typeof intercept>) => intercept = fn,
    state: () => StateSchema.parse(data.get("state-7") ?? {}),
    run: (filter: number[] = []) =>
      behavior.methods.tick.execute({ instances: filter }, ctx as never),
  };
}
const refusal = (code: string): Response => ({
  status: 409,
  body: { error: { code, message: "Refused", details: {} } },
});
const deployPosts = (f: ReturnType<typeof fixture>) =>
  f.requests.filter((r) =>
    r.method === "POST" && r.path.endsWith("/deploy/automatic")
  );
function lostIntent(f: ReturnType<typeof fixture>) {
  f.data.set(
    "state-7",
    StateSchema.parse({
      attempts: { [B]: 1 },
      intent: {
        commit: B,
        attempt: 1,
        key: `auto.7.${B}.1`,
        manual_after: 1,
        operation_id: null,
      },
    }),
  );
}

Deno.test("live default discovers production, guards ownership, saves intent, admits and publishes exact Gateway shapes", async () => {
  const f = fixture();
  await f.run();
  assertEquals(f.requests.slice(0, 2).map((r) => r.path), [
    "/api/v1/nodes",
    "/api/v1/instances?auto_deploy=true",
  ]);
  const writes = f.requests.filter((r) => r.method !== "GET");
  assertEquals(writes.map((r) => [r.method, r.path]), [
    ["POST", "/api/v1/instances/7/deploy/automatic"],
    ["POST", "/api/v1/instances/7/auto-deploy/events"],
    ["PUT", "/api/v1/instances/7/auto-deploy/policy"],
  ]);
  assertEquals(deployPosts(f)[0], {
    method: "POST",
    path: "/api/v1/instances/7/deploy/automatic",
    body: { commit: B },
    key: `auto.7.${B}.1`,
  });
  assertEquals(writes.at(-1)?.body, {
    resumed_after: 1,
    last_deployment_id: 1,
    history_version: "f".repeat(64),
    result: "started",
    commit: B,
    head: B,
    since: start,
    error_code: null,
    message: null,
    pause: null,
  });
  assertEquals(f.state().intent?.operation_id, 100);
  const event = f.operations.get(f.state().events[0].operation_id!)!;
  Object.assign(event, {
    status: "succeeded",
    result: { webhook: { outcome: "sent" }, activity_id: 13 },
  });
  await f.run();
  assertEquals(deployPosts(f).length, 1);
  assertEquals(f.state().result, "deploying");
  assertEquals(f.state().events[0].receipt?.result, {
    webhook: { outcome: "sent" },
    activity_id: 13,
  });
});

Deno.test("first live tick imports the public projection and preserves a stable since", async () => {
  const f = fixture();
  const since = "2026-10-27T19:00:00.000Z";
  Object.assign(f.instance.auto_deploy, {
    result: "up_to_date",
    commit: B,
    head: B,
    since,
  });
  f.setRows([{ ...f.fake.observation.history[0], commit: B }]);
  await f.run();
  assertEquals(f.state().result, "up_to_date");
  assertEquals(f.state().since, since);
  assertEquals(f.state().events, []);
  assertEquals(deployPosts(f), []);
  await f.run();
  assertEquals(f.requests.filter((r) => r.method === "PUT"), []);
});

Deno.test("history written after deploy admission rejects final PUT without failing the run or replaying admission", async () => {
  const f = fixture();
  f.intercept((method, path) => {
    if (method === "POST" && path.endsWith("/deploy/automatic")) {
      f.instance.auto_deploy.history_version = "a".repeat(64);
      f.setRows([
        f.fake.observation.history[0],
        {
          ...f.fake.observation.history[0],
          id: 2,
          status: "running",
          commit: B,
          triggered_by: trigger,
          finished_at: null,
        },
      ]);
    }
    return undefined;
  });
  await f.run();
  assertEquals(f.state().intent?.operation_id, 100);
  assertEquals(f.state().result, "started");
  const stale = f.requests.find((r) => r.method === "PUT")!.body as Record<
    string,
    unknown
  >;
  assertEquals(stale.history_version, "f".repeat(64));
  assertEquals(f.instance.auto_deploy.result, "gate_pending");
  await f.run();
  assertEquals(f.state().result, "deploying");
  assertEquals(deployPosts(f).length, 1);
  const fresh = f.requests.filter((r) => r.method === "PUT").at(-1)!
    .body as Record<string, unknown>;
  assertEquals(fresh.history_version, "a".repeat(64));
  assertEquals(fresh.last_deployment_id, 2);
});

Deno.test("final publication conflict does not hide a failed policy decision", async () => {
  const f = fixture();
  f.intercept((method, path) => {
    if (method === "PUT") {
      return refusal("instance.auto_deploy_observation_changed");
    }
    if (path.endsWith("/head")) return { status: 500, body: {} };
    return undefined;
  });
  await assertRejects(() => f.run(), AggregateError);
  assertEquals(f.state().result, "failed");
  assertEquals(deployPosts(f), []);
});

Deno.test("other final publication errors remain failed runs with the admission receipt kept", async () => {
  const f = fixture();
  f.intercept((method) =>
    method === "PUT" ? { status: 500, body: {} } : undefined
  );
  await assertRejects(() => f.run(), AggregateError);
  assertEquals(f.state().intent?.operation_id, 100);
  assertEquals(deployPosts(f).length, 1);
});

for (const status of ["queued", "running"]) {
  Deno.test(`manual ${status} operation with input [] remains a readable busy holder`, async () => {
    const f = fixture();
    f.intercept((_method, path) =>
      path.startsWith("/api/v1/operations?") &&
        path.endsWith(`status=${status}`)
        ? {
          status: 200,
          body: {
            data: [{
              ...f.operation,
              input: [],
              status,
              triggered_by: "manual",
            }],
          },
        }
        : undefined
    );
    await f.run();
    assertEquals(f.state().result, "busy");
    assertEquals(deployPosts(f), []);
  });
}

Deno.test("target_busy follows a manual operation with input []", async () => {
  const f = fixture();
  f.operations.set(900, {
    ...f.operation,
    id: 900,
    input: [],
    triggered_by: "manual",
  });
  f.intercept((_method, path) =>
    path.endsWith("/deploy/automatic")
      ? {
        status: 409,
        body: {
          error: {
            code: "operation.target_busy",
            message: "Busy",
            details: { operation_id: 900 },
          },
        },
      }
      : undefined
  );
  await f.run();
  assertEquals(f.state().result, "busy");
  assertEquals(f.state().operation_id, 900);
  assertEquals(f.state().intent, null);
});

Deno.test("non-empty operation input arrays remain unreadable", async () => {
  const f = fixture();
  f.operations.set(900, { ...f.operation, id: 900, input: [B] });
  const error = await assertRejects(() => f.client.operation(900), PolicyError);
  assertEquals(error.code, "gateway.read_unavailable");
});
Deno.test("discovery covers all enabled app-prod Instances; a filter only narrows it", async () => {
  const f = fixture();
  f.instances.push(
    { ...structuredClone(f.instance), id: 8 },
    { ...structuredClone(f.instance), id: 9, node_id: 3 },
    { ...structuredClone(f.instance), id: 10, node_id: 4 },
    {
      ...structuredClone(f.instance),
      id: 11,
      auto_deploy: { ...f.instance.auto_deploy, enabled: false },
    },
  );
  assertEquals(await f.client.discover(), [7, 8]);
  assertEquals(await f.client.discover([8, 9, 10, 11, 999]), [8]);
  f.nodes[0].roles.push("app-dev");
  assertEquals(await f.client.discover(), []);
});

Deno.test("discovery uses the Gateway eligibility filter and skips excluded Instances", async () => {
  const f = fixture();
  f.intercept((_method, path) =>
    path === "/api/v1/instances?auto_deploy=true"
      ? { status: 200, body: { data: [] } }
      : undefined
  );
  await f.run();
  assertEquals(f.requests.map((r) => r.path), [
    "/api/v1/nodes",
    "/api/v1/instances?auto_deploy=true",
  ]);
  assertEquals(f.data.size, 0);
});

Deno.test("role refusal after the guard clears an unadmitted intent without a hold", async () => {
  const f = fixture();
  f.intercept((_method, path) =>
    path.endsWith("/deploy/automatic")
      ? refusal("instance.auto_deploy_role_inactive")
      : undefined
  );
  await f.run();
  assertEquals(deployPosts(f).length, 1);
  assertEquals(f.state().intent, null);
  assertEquals(f.state().effect_hold, null);
  assertEquals(f.requests.some((r) => r.path.endsWith("/events")), false);
  f.intercept((_method, path) =>
    path === "/api/v1/instances?auto_deploy=true"
      ? { status: 200, body: { data: [] } }
      : undefined
  );
  await f.run();
  assertEquals(deployPosts(f).length, 1);
  f.intercept(() => undefined);
  await f.run();
  assertEquals(deployPosts(f).at(-1)?.key, `auto.7.${B}.2`);
});

Deno.test("PHP owner from Instance is a successful no-op without a write or state advance", async () => {
  const f = fixture();
  f.instance.auto_deploy.policy_owner = "gateway";
  await f.run();
  assertEquals(f.requests.every((r) => r.method === "GET"), true);
  assertEquals(f.data.size, 0);
});
Deno.test("PHP ownership also prevents automatic resolution of an expired missing request", async () => {
  const f = fixture();
  lostIntent(f);
  f.data.set(
    "state-7",
    StateSchema.parse({
      ...f.state(),
      intent: { ...f.state().intent!, resolve_after: start },
    }),
  );
  f.instance.auto_deploy.policy_owner = "gateway";
  const previous = structuredClone(f.data.get("state-7"));
  await f.run();
  assertEquals(f.requests.every((r) => r.method === "GET"), true);
  assertEquals(f.data.get("state-7"), previous);
});
Deno.test("pre-cutover publication owner refusal is a no-op before any POST or local state", async () => {
  const f = fixture();
  f.instance.auto_deploy.policy_owner = undefined;
  f.intercept((method) =>
    method === "PUT" ? refusal("instance.auto_deploy_policy_owner") : undefined
  );
  await f.run();
  assertEquals(f.requests.some((r) => r.method === "POST"), false);
  assertEquals(f.data.size, 0);
});
Deno.test("pending event owner refusal stops before a deploy POST and preserves the event key", async () => {
  const f = fixture();
  f.data.set(
    "state-7",
    StateSchema.parse({
      events: [{
        key: "policy.7.activity.1",
        body: { event: "activity", result: "busy", commit: B },
        operation_id: null,
        receipt: null,
      }],
    }),
  );
  const before = structuredClone(f.data.get("state-7"));
  f.intercept((_method, path) =>
    path.endsWith("/events")
      ? refusal("instance.auto_deploy_policy_owner")
      : undefined
  );
  await f.run();
  assertEquals(deployPosts(f), []);
  assertEquals(f.data.get("state-7"), before);
});
Deno.test("Gateway deploy admission owner refusal cannot leave an unresolved intent", async () => {
  const f = fixture();
  f.intercept((_method, path) =>
    path.endsWith("/deploy/automatic")
      ? refusal("instance.auto_deploy_policy_owner")
      : undefined
  );
  await f.run();
  assertEquals(f.state().intent, null);
  assertEquals(f.requests.some((r) => r.path.endsWith("/events")), false);
});
Deno.test("changed publication version and unreadable ownership probe stop before admission", async () => {
  for (
    const response of [refusal("instance.auto_deploy_observation_changed"), {
      status: 200,
      body: {},
    }]
  ) {
    const f = fixture();
    Object.assign(f.instance.auto_deploy, { result: null, since: null });
    f.intercept((method) => method === "PUT" ? response : undefined);
    await assertRejects(() => f.run(), AggregateError);
    assertEquals(deployPosts(f), []);
    assertEquals(f.data.size, 0);
  }
});
Deno.test("fresh Instances with no public decision use a valid ownership probe", async () => {
  const f = fixture();
  Object.assign(f.instance.auto_deploy, { result: null, since: null });
  await f.run();
  const probe = f.requests.find((r) => r.method === "PUT")!.body as Record<
    string,
    unknown
  >;
  assertEquals(probe.result, "not_deployed");
  assertEquals(typeof probe.since, "string");
  assertEquals(deployPosts(f).length, 1);
});

Deno.test("truncated walks fail closed even when the first candidate is green", async () => {
  const f = fixture();
  f.walk.truncated = true;
  f.walk.reached_base = false;
  await assertRejects(() => f.run(), AggregateError);
  assertEquals(f.state().error_code, "instance.auto_deploy_gate_unavailable");
  assertEquals(deployPosts(f), []);
  assertEquals(f.requests.some((r) => r.path.includes("/checks?")), false);
});
Deno.test("moved head and nullable check evidence cannot admit a deploy", async () => {
  for (const variant of ["head", "checks"]) {
    const f = fixture();
    if (variant === "head") f.walk.head = C;
    else {f.setRuns([{
        name: "Required checks",
        head_sha: null,
        app: null,
        status: null,
        conclusion: null,
      }]);}
    await f.run();
    assertEquals(deployPosts(f), []);
    assertEquals(
      f.state().result,
      variant === "head" ? "gate_pending" : "gate_failed",
    );
  }
});
Deno.test("mixed history, resume and ownership observations fail closed", async () => {
  for (
    const field of ["history_version", "resumed_after", "policy_owner"] as const
  ) {
    const f = fixture();
    f.intercept((_method, path) => {
      if (path.endsWith("/deployments")) {
        if (field === "history_version") {
          f.instance.auto_deploy.history_version = "e".repeat(64);
        }
        if (field === "resumed_after") f.instance.auto_deploy.resumed_after++;
        if (field === "policy_owner") {
          f.instance.auto_deploy.policy_owner = "gateway";
        }
      }
      return undefined;
    });
    await assertRejects(() => f.run(), AggregateError);
    assertEquals(f.requests.every((r) => r.method === "GET"), true);
  }
});
Deno.test("disabled and development targets do not read source or write", async () => {
  for (const development of [false, true]) {
    const f = fixture();
    f.instance.auto_deploy.enabled = development;
    if (development) f.nodes[0].roles = ["app-dev"];
    await f.run();
    assertEquals(f.requests.length, 2);
    assertEquals(f.data.size, 0);
  }
});
Deno.test("a failed Instance does not prevent the next discovered Instance from running", async () => {
  const f = fixture();
  f.instances.unshift({ ...structuredClone(f.instance), id: 6 });
  f.intercept((_method, path) =>
    path === "/api/v1/instances/6" ? { status: 503, body: {} } : undefined
  );
  const error = await assertRejects(() => f.run(), AggregateError);
  assertStringIncludes(error.message, "Instance 6");
  assertEquals(deployPosts(f).length, 1);
  assertEquals(f.state().result, "started");
});
Deno.test("unreadable discovery fails closed instead of treating the fleet as empty", async () => {
  const f = fixture();
  f.intercept((_method, path) =>
    path === "/api/v1/instances?auto_deploy=true"
      ? { status: 200, body: { data: { items: [] } } }
      : undefined
  );
  await assertRejects(() => f.run(), PolicyError);
  assertEquals(f.data.size, 0);
  assertEquals(deployPosts(f), []);
});

Deno.test("G06 validates keyed receipt caller and target; missing holds through manual deploy and resume", async () => {
  const f = fixture();
  const key = `auto.7.${B}.1`;
  assertEquals(await f.client.receipt(7, B, key), null);
  f.setFound(true);
  assertEquals((await f.client.receipt(7, B, key))?.status, "queued");
  f.operation.target_id = 8;
  await assertRejects(() => f.client.receipt(7, B, key), PolicyError);
  f.operation.target_id = 7;
  f.operation.input.caller_node_id = 0;
  await assertRejects(() => f.client.receipt(7, B, key), PolicyError);
  f.operation.input.caller_node_id = 2;
  f.setFound(false);
  lostIntent(f);
  f.setRows([f.fake.observation.history[0], {
    ...f.fake.observation.history[0],
    id: 2,
  }]);
  f.instance.auto_deploy.resumed_after = 2;
  await assertRejects(() => f.run(), AggregateError);
  assertEquals(f.state().intent?.key, key);
  assertEquals(f.state().error_code, "automatic.outcome_unknown");
  assertEquals(deployPosts(f), []);
});
Deno.test("G06 queued receipt is followed before fresh history without replaying POST", async () => {
  const f = fixture();
  lostIntent(f);
  f.setFound(true);
  await f.run();
  assertEquals(f.state().result, "deploying");
  assertEquals(deployPosts(f), []);
  assertEquals(
    f.requests.findIndex((r) => r.path.includes("/receipt?")) <
      f.requests.findIndex((r) => r.path.endsWith("/deployments")),
    true,
  );
});
function fence(f: ReturnType<typeof fixture>) {
  Object.assign(f.operation, {
    status: "cancelled",
    attempts: 0,
    error_code: "operation.admission_fenced",
    result: { admission_fenced: true },
  });
}
Deno.test("missing receipt stays held until ten minutes, then resolve fences it without a deploy or effect hold", async () => {
  const f = fixture();
  lostIntent(f);
  await assertRejects(() => f.run(), AggregateError);
  assertEquals(f.state().intent?.resolve_after, "2026-10-27T20:10:00.000Z");
  f.fake.now = "2026-10-27T20:09:59.000Z";
  await assertRejects(() => f.run(), AggregateError);
  assertEquals(f.requests.some((r) => r.path.endsWith("/resolve")), false);
  f.fake.now = "2026-10-27T20:10:00.000Z";
  fence(f);
  await f.run();
  assertEquals(f.state().intent, null);
  assertEquals(f.state().effect_hold, null);
  assertEquals(deployPosts(f), []);
  const resolve = f.requests.find((r) => r.path.endsWith("/resolve"));
  assertEquals(resolve, {
    method: "POST",
    path: "/api/v1/instances/7/deploy/automatic/resolve",
    body: { request_key: `auto.7.${B}.1`, commit: B },
    key: undefined,
  });
  Object.assign(f.operation, {
    status: "queued",
    error_code: null,
    result: null,
  });
  await f.run();
  assertEquals(deployPosts(f)[0].key, `auto.7.${B}.2`);
});
Deno.test("an operator-created fenced receipt from G06 clears intent without another resolve", async () => {
  const f = fixture();
  lostIntent(f);
  fence(f);
  f.setFound(true);
  await f.run();
  assertEquals(f.state().intent, null);
  assertEquals(f.state().effect_hold, null);
  assertEquals(f.requests.some((r) => r.path.endsWith("/resolve")), false);
  assertEquals(deployPosts(f), []);
});
Deno.test("resolve returning a real queued operation follows it without replacement", async () => {
  const f = fixture();
  lostIntent(f);
  f.data.set(
    "state-7",
    StateSchema.parse({
      ...f.state(),
      intent: { ...f.state().intent!, resolve_after: start },
    }),
  );
  await f.run();
  assertEquals(f.state().result, "deploying");
  assertEquals(f.state().intent?.operation_id, 100);
  assertEquals(deployPosts(f), []);
  assertEquals(f.requests.filter((r) => r.path.endsWith("/resolve")).length, 1);
});
Deno.test("resolve returning a real terminal receipt uses history read after resolution", async () => {
  const f = fixture();
  lostIntent(f);
  f.data.set(
    "state-7",
    StateSchema.parse({
      ...f.state(),
      intent: { ...f.state().intent!, resolve_after: start },
    }),
  );
  f.intercept((_method, path) => {
    if (path.endsWith("/resolve")) {
      const row = {
        ...f.fake.observation.history[0],
        id: 2,
        commit: B,
        triggered_by: trigger,
      };
      f.setRows([f.fake.observation.history[0], row]);
      Object.assign(f.operation, {
        status: "succeeded",
        result: { deployment_id: 2 },
      });
    }
    return undefined;
  });
  await f.run();
  assertEquals(f.state().result, "up_to_date");
  assertEquals(f.state().intent, null);
  assertEquals(f.state().effect_hold, null);
  assertEquals(deployPosts(f), []);
  const index = f.requests.findIndex((r) => r.path.endsWith("/resolve"));
  assertEquals(
    f.requests.slice(index + 1).some((r) => r.path.endsWith("/deployments")),
    true,
  );
});
Deno.test("resolve terminal receipt without effect history retains the reviewed effect hold", async () => {
  const f = fixture();
  lostIntent(f);
  f.data.set(
    "state-7",
    StateSchema.parse({
      ...f.state(),
      intent: { ...f.state().intent!, resolve_after: start },
    }),
  );
  Object.assign(f.operation, {
    status: "failed",
    error_code: "operation.worker_lost",
    attempts: 1,
  });
  await assertRejects(() => f.run(), AggregateError);
  assertEquals(f.state().intent, null);
  assertEquals(f.state().effect_hold?.operation_id, 100);
  assertEquals(deployPosts(f), []);
});
Deno.test("lost or malformed resolve response preserves the original admission intent", async () => {
  for (const variant of ["lost", "missing", "identity"]) {
    const f = fixture();
    lostIntent(f);
    f.data.set(
      "state-7",
      StateSchema.parse({
        ...f.state(),
        intent: { ...f.state().intent!, resolve_after: start },
      }),
    );
    f.intercept((_method, path) => {
      if (!path.endsWith("/resolve")) return undefined;
      if (variant === "lost") throw new Error("response lost");
      return {
        status: 200,
        body: {
          data: {
            request_key: `auto.7.${B}.1`,
            found: variant === "identity",
            operation: variant === "identity"
              ? { ...f.operation, target_id: 8 }
              : null,
            deployment: null,
          },
        },
      };
    });
    await assertRejects(() => f.run(), AggregateError);
    assertEquals(f.state().intent?.key, `auto.7.${B}.1`);
    assertEquals(deployPosts(f), []);
  }
});
Deno.test("a fence code without zero-attempt terminal proof cannot clear the effect hold", async () => {
  for (const variant of ["attempts", "result"]) {
    const f = fixture();
    lostIntent(f);
    fence(f);
    if (variant === "attempts") f.operation.attempts = 1;
    else f.operation.result = null;
    f.setFound(true);
    await assertRejects(() => f.run(), AggregateError);
    assertEquals(f.state().effect_hold?.operation_id, 100);
    assertEquals(deployPosts(f), []);
  }
});

Deno.test("failure, pause and stall alerts reach instance.policy_event with durable exact payloads", async () => {
  for (const variant of ["failed", "paused", "stalled"] as const) {
    const f = fixture();
    if (variant === "failed") {
      f.setRows([f.fake.observation.history[0], f.fake.failure(B)]);
    }
    if (variant === "paused") f.instance.auto_deploy.resumed_after = 0;
    if (variant === "stalled") {
      f.setRuns([]);
      f.data.set(
        "state-7",
        StateSchema.parse({ stall: { sha: B, since: start, alerted: false } }),
      );
      f.fake.now = "2026-10-27T20:31:00.000Z";
    }
    await f.run();
    const event = f.requests.find((r) =>
      r.path.endsWith("/events") &&
      (r.body as { event: string }).event === variant
    )!;
    assertEquals(typeof event.key, "string");
    const expected = variant === "stalled"
      ? { event: variant, commit: B, since: start, result: "gate_pending" }
      : {
        event: variant,
        commit: variant === "paused" ? A : B,
        deployment_id: variant === "paused" ? 1 : 2,
      };
    assertEquals(event.body, expected);
    const key = event.key;
    await f.run();
    assertEquals(
      f.requests.filter((r) => r.path.endsWith("/events") && r.key === key)
        .length,
      1,
    );
  }
});
Deno.test("lost event admission response retains its exact key and payload for recovery", async () => {
  const f = fixture();
  let lost = true;
  f.intercept((_method, path) => {
    if (path.endsWith("/events") && lost) throw new Error("lost response");
    return undefined;
  });
  await assertRejects(() => f.run(), AggregateError);
  const pending = structuredClone(f.state().events[0]);
  assertEquals(pending.operation_id, null);
  lost = false;
  await f.run();
  const posts = f.requests.filter((r) => r.path.endsWith("/events"));
  assertEquals(posts[0].key, posts[1].key);
  assertEquals(posts[0].body, posts[1].body);
  assertEquals(deployPosts(f).length, 1);
});
Deno.test("terminal failed event receipt preserves partial delivery and never admits a replacement", async () => {
  const f = fixture();
  await f.run();
  const id = f.state().events[0].operation_id!;
  const event = f.operations.get(id)!;
  Object.assign(event, {
    status: "failed",
    error_code: "operation.worker_lost",
    result: { webhook: { outcome: "unknown" } },
  });
  await f.run();
  await f.run();
  assertEquals(f.state().events[0].receipt?.result, event.result);
  assertEquals(
    f.state().events[0].receipt?.error_code,
    "operation.worker_lost",
  );
  assertEquals(f.requests.filter((r) => r.path.endsWith("/events")).length, 1);
});

// The DTOs on either side of orbit#1561: older operations already bind the
// caller in input; only the new DTOs expose it on operations and history rows.
const gatewayOrigins = [
  {
    name: "before orbit#1561",
    fields: { triggered_by: "orbit:auto-deploy" },
  },
  {
    name: "after orbit#1561",
    fields: { triggered_by: "swamp:auto-deploy", caller_node_id: 2 },
  },
  {
    name: "structured caller with a changed display label",
    fields: { triggered_by: "services", caller_node_id: 2 },
  },
];

for (const { name, fields } of gatewayOrigins) {
  Deno.test(`${name}: admission and completed history stay automatic without a pause or effect hold`, async () => {
    const f = fixture();
    Object.assign(f.operation, fields);
    await f.run();
    assertEquals(f.state().result, "started");
    assertEquals(f.state().intent?.operation_id, f.operation.id);
    assertEquals(deployPosts(f).map((r) => r.body), [{ commit: B }]);
    const operation = await f.client.operation(f.operation.id);
    assertEquals(operation.triggered_by, fields.triggered_by);
    assertEquals(
      operation.caller_node_id,
      "caller_node_id" in fields ? 2 : undefined,
    );
    assertEquals(operation.input.caller_node_id, 2);

    Object.assign(f.operation, {
      status: "succeeded",
      attempts: 1,
      result: { deployment_id: 2 },
    });
    f.setHolder(null);
    f.setRows([f.fake.observation.history[0], {
      ...f.fake.observation.history[0],
      ...fields,
      id: 2,
      commit: B,
    }]);
    await f.run();
    assertEquals(f.state().result, "up_to_date");
    assertEquals(f.state().pause, null);
    assertEquals(f.state().intent, null);
    assertEquals(f.state().effect_hold, null);
    assertEquals(
      f.state().events.some((e) => e.body.event === "paused"),
      false,
    );
    assertEquals(deployPosts(f).length, 1);
  });

  Deno.test(`${name}: receipt lookup and resolution follow the automatic operation`, async () => {
    const f = fixture();
    Object.assign(f.operation, fields);
    lostIntent(f);
    f.setFound(true);
    const key = `auto.7.${B}.1`;
    assertEquals((await f.client.receipt(7, B, key))?.id, f.operation.id);
    assertEquals((await f.client.resolve(7, B, key)).id, f.operation.id);
    await f.run();
    assertEquals(f.state().result, "deploying");
    assertEquals(f.state().operation_id, f.operation.id);
    assertEquals(deployPosts(f), []);
  });

  Deno.test(`${name}: own running deploy is deploying and a different commit is busy`, async () => {
    const f = fixture();
    Object.assign(f.operation, fields, { status: "running" });
    f.setHolder(f.operation);
    await f.run();
    assertEquals(f.state().result, "deploying");
    assertEquals(f.state().operation_id, f.operation.id);
    assertEquals(deployPosts(f), []);
    f.operation.input.commit = C;
    await f.run();
    assertEquals(f.state().result, "busy");
    assertEquals(deployPosts(f), []);
  });

  Deno.test(`${name}: automatic failures alert, back off and retain the retry ordinal`, async () => {
    const f = fixture();
    Object.assign(f.operation, fields);
    f.setRows([f.fake.observation.history[0], {
      ...f.fake.observation.history[0],
      ...fields,
      id: 2,
      status: "failed",
      commit: B,
      failed_step: "preparation",
      error_code: "source.unavailable",
    }]);
    await f.run();
    assertEquals(f.state().result, "backing_off");
    assertEquals(f.state().pause, null);
    assertEquals(
      f.state().events.filter((e) => e.body.event === "failed").length,
      1,
    );
    assertEquals(
      f.state().events.some((e) => e.body.event === "paused"),
      false,
    );
    assertEquals(deployPosts(f), []);
    f.fake.now = "2026-10-27T20:10:00.000Z";
    await f.run();
    assertEquals(f.state().result, "started");
    assertEquals(deployPosts(f).map((r) => r.key), [`auto.7.${B}.2`]);
  });

  Deno.test(`${name}: rollbacks and manual deploys still pause and alert`, async () => {
    for (const manual of [false, true]) {
      const f = fixture();
      f.setRows([f.fake.observation.history[0], {
        ...f.fake.observation.history[0],
        ...fields,
        ...(manual ? { triggered_by: "laptop", caller_node_id: null } : {}),
        kind: manual ? "deploy" : "rollback",
        id: 2,
      }]);
      await f.run();
      assertEquals(f.state().result, "paused");
      assertEquals(
        f.state().pause?.reason,
        manual ? "manual_deploy" : "rollback",
      );
      assertEquals(
        f.state().events.filter((e) => e.body.event === "paused").length,
        1,
      );
      assertEquals(deployPosts(f), []);
    }
  });
}
