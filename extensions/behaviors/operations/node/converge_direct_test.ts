import {
  assertEquals,
  assertInstanceOf,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1";
import { nodeConverge } from "./converge_direct.ts";
import { Args, Snapshot, Stage } from "./converge_direct_contract.ts";
import {
  footprintActions,
  type LocalDependencies,
} from "./converge_footprint.ts";
import { GatewayOperation } from "../_lib/direct.ts";
import type { NodeCall } from "../_lib/node-agent.ts";

const args = {
  operation_id: 7,
  node_id: 209,
  kind: "node.converge" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const snapshot = (name = "agent"): Snapshot => ({
  schema_version: 1,
  node_id: 209,
  agent_sha256: "a".repeat(64),
  force_artifacts: false,
  first_visit: false,
  artifacts: [{ name, digest: "b".repeat(64), required: true, repair: false }],
  network: null,
  refusal: null,
  payloads: {
    agent: {
      configuration: "swamp_worker = true\n",
      certificate: "CA",
      unit: "[Service]\nProtectHome=yes\n",
      user: "orbit",
      apps_root: null,
      checkouts: ["/home/orbit/apps/a", "/srv/foreign"],
      url: null,
    },
  },
});
function fixture() {
  const writes: unknown[] = [];
  const posts: Record<string, unknown>[] = [];
  const current = {
    id: 7,
    node_id: 209,
    kind: "node.converge",
    status: "queued",
    cancel_requested: false,
    input: snapshot(),
  };
  const http = (method: string, _path: string, raw?: unknown) => {
    if (method === "POST") posts.push(raw as Record<string, unknown>);
    return Promise.resolve({ status: 200, body: { data: current } });
  };
  const context = {
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/tmp/test-ca" },
    writeResource: (_s: string, _n: string, v: unknown) => {
      writes.push(v);
      return Promise.resolve({});
    },
  };
  return { writes, posts, current, http, context };
}
function dependencies() {
  const calls: NodeCall[] = [];
  const deps: LocalDependencies = {
    agent: (call) => {
      calls.push(call);
      return Promise.resolve({ changed: false });
    },
    install: () => Promise.resolve({ changed: false }),
    account: () => Promise.resolve({ home: "/home/orbit", group: "orbit" }),
    dns: () => Promise.resolve({ ready: true }),
    residue: () => Promise.resolve({ changed: false }),
    network: () => Promise.reject(new Error("Network must not be dispatched")),
  };
  return { deps, calls };
}
function privateGateway(material: Record<string, unknown>) {
  return new GatewayOperation(
    () => Promise.resolve({ status: 200, body: { data: material } }),
    args,
  );
}
Deno.test("admission refuses a foreign Node before projecting or dispatching", async () => {
  const f = fixture();
  f.current.node_id = 210;
  await assertRejects(() =>
    nodeConverge(undefined, () => f.http).methods.admit.execute(args, f.context)
  );
  assertEquals(f.posts, []);
  assertEquals(f.writes, []);
});
Deno.test("admission projects running and stores only the safe immutable snapshot", async () => {
  const f = fixture();
  await nodeConverge(undefined, () => f.http).methods.admit.execute(
    args,
    f.context,
  );
  assertEquals(f.posts.map((p) => p.status), ["running"]);
  assertEquals(
    (f.writes[0] as { snapshot: unknown }).snapshot,
    f.current.input,
  );
});
Deno.test("uncertain native stage cannot be projected as failure or success", async () => {
  const f = fixture();
  await assertRejects(() =>
    nodeConverge(undefined, () => f.http).methods.project.execute({
      ...args,
      admission: {
        ready: true,
        status: "running",
        started_at: "2026-10-30T12:00:00Z",
        snapshot: snapshot(),
      },
      outcomes: [],
    }, f.context)
  );
  assertEquals(f.posts, []);
});
Deno.test("configure preserves legacy hashes and strips the returned hash inside the private callback", async () => {
  const f = dependencies();
  f.deps.agent = (call) => {
    f.calls.push(call);
    return Promise.resolve({
      secret_hash: "protected-output",
      restarted: false,
      unclosed: [],
    });
  };
  const result = await footprintActions(f.deps)(
    "agent",
    snapshot(),
    privateGateway({
      secret_hash: "c".repeat(64),
      worker_credentials: {
        enrollment_token: "private-enrollment",
        server_token: "private-access",
      },
    }),
    true,
  );
  assertEquals(result, {
    stage: "agent",
    ok: true,
    entry: { name: "agent", status: "applied" },
  });
  assertEquals(f.calls[0].input.allow_secret_rotation, false);
  assertEquals(f.calls[0].input.secret_hash, "c".repeat(64));
  assertEquals(f.calls[0].input.checkouts, ["/home/orbit/apps/a"]);
  assertEquals(
    f.calls[0].input.unit,
    "[Service]\nProtectHome=tmpfs\nBindReadOnlyPaths=-/home/orbit/apps\n",
  );
  assertEquals(JSON.stringify(result).includes("protected-output"), false);
  assertEquals(JSON.stringify(result).includes("private-"), false);
});
Deno.test("private callback errors never expose protected bytes or a fabricated safe outcome", async () => {
  const f = dependencies();
  f.deps.agent = () => Promise.reject(new Error("private-enrollment"));
  const error = await assertRejects(() =>
    footprintActions(f.deps)(
      "agent",
      snapshot(),
      privateGateway({ secret_hash: "c".repeat(64) }),
      false,
    )
  );
  assertInstanceOf(error, Error);
  assertEquals(error.message, "Private material callback failed");
});
Deno.test("nonrotation refusal is bounded and never returns the hash", async () => {
  const f = dependencies();
  f.deps.agent = () =>
    Promise.resolve({
      error_code: "agent.secret_mismatch",
      secret_hash: "protected",
    });
  const result = await footprintActions(f.deps)(
    "agent",
    snapshot(),
    privateGateway({ secret_hash: null }),
    false,
  );
  assertEquals(result, {
    stage: "agent",
    ok: false,
    error_code: "agent.secret_mismatch",
    failed_artifact: "agent",
  });
});
Deno.test("PHP runtime dispatch covers every admitted version and stops on a real refusal", async () => {
  const f = dependencies();
  const input = snapshot("php-runtime");
  input.payloads = {
    "php-runtime": {
      configurations: ["8.4", "8.5"].map((version) => ({
        version,
        runtime_ini: "managed",
        release: { id: "test", codenames: ["resolute"] },
      })),
    },
  };
  await footprintActions(f.deps)(
    "php-runtime",
    input,
    privateGateway({}),
    false,
  );
  assertEquals(f.calls.map((c) => c.input.version), ["8.4", "8.5"]);
  f.calls.length = 0;
  f.deps.agent = (call) => {
    f.calls.push(call);
    return Promise.resolve({ error_code: "php.runtime_failed" });
  };
  assertEquals(
    (await footprintActions(f.deps)(
      "php-runtime",
      input,
      privateGateway({}),
      false,
    )).ok,
    false,
  );
  assertEquals(f.calls.length, 1);
});
Deno.test("only Caddy validation refusal is skippable and first-visit failure stays visible", async () => {
  const f = dependencies();
  const input = snapshot("caddy");
  input.first_visit = true;
  input.payloads = {
    caddy: {
      content: "managed",
      version: "test",
      listen_addresses: [],
      unserved: true,
    },
  };
  f.deps.agent = () =>
    Promise.resolve({
      error_code: "caddy.publication_failed",
      stage: "validate",
    });
  assertEquals(
    await footprintActions(f.deps)("caddy", input, privateGateway({}), false),
    {
      stage: "caddy",
      ok: true,
      entry: {
        name: "caddy",
        status: "skipped",
        reason: "caddy_validate_refused",
      },
      deferred_error: "node.footprint_caddy_failed",
    },
  );
  input.artifacts[0].repair = true;
  assertEquals(
    (await footprintActions(f.deps)("caddy", input, privateGateway({}), false))
      .deferred_error,
    undefined,
  );
  f.deps.agent = () =>
    Promise.resolve({
      error_code: "caddy.publication_failed",
      stage: "reload",
    });
  assertEquals(
    (await footprintActions(f.deps)("caddy", input, privateGateway({}), false))
      .ok,
    false,
  );
});
Deno.test("DNS publication happens only after a proven listener install", async () => {
  const f = dependencies();
  const input = snapshot("private-dns");
  input.payloads = { "private-dns": { listener: {}, publication: {} } };
  const events: string[] = [];
  f.deps.agent = (call) => {
    events.push(call.name);
    return Promise.resolve({ installed: true, changed: false });
  };
  f.deps.dns = () => {
    events.push("publish");
    return Promise.resolve({ ready: true });
  };
  await footprintActions(f.deps)(
    "private-dns",
    input,
    privateGateway({}),
    false,
  );
  assertEquals(events, ["node.install-private-dns-listener", "publish"]);
});
Deno.test("null network is never dispatched when the peer artifact is absent", async () => {
  const f = dependencies();
  assertEquals(
    await footprintActions(f.deps)(
      "peer-dns",
      snapshot(),
      privateGateway({}),
      false,
    ),
    { stage: "peer-dns", ok: true },
  );
});
Deno.test("immutable network input refuses cross-node binding and unsafe domains", () => {
  const input = snapshot();
  const network = {
    schema_version: 1 as const,
    node_id: 210,
    agent_sha256: "a".repeat(64),
    peer_dns: {
      dns_server: "10.44.0.1",
      public_key: btoa("a".repeat(32)),
      domains: ["gateway.orbit"],
    },
  };
  assertThrows(() => Snapshot.parse({ ...input, network }));
  assertThrows(() =>
    Snapshot.parse({
      ...input,
      network: {
        ...network,
        node_id: 209,
        peer_dns: { ...network.peer_dns, domains: ["z.orbit", "a.orbit"] },
      },
    })
  );
  assertEquals(Stage.options.length, 9);
  assertEquals(Args.parse(args), args);
});

Deno.test("agent delivery verifies the admitted digest across safe HTTPS redirects before executable staging", async () => {
  const { downloadAgent } = await import("./converge_local.ts");
  const bytes = new TextEncoder().encode("pinned candidate");
  const pin = Array.from(
    new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
  const calls: string[] = [];
  const request: typeof fetch = (url) => {
    calls.push(String(url));
    return Promise.resolve(
      calls.length === 1
        ? new Response(null, {
          status: 302,
          headers: { location: "https://downloads.example/candidate" },
        })
        : new Response(bytes),
    );
  };
  assertEquals(
    await downloadAgent(
      "https://release.example/candidate",
      pin,
      undefined,
      request,
    ),
    bytes,
  );
  assertEquals(calls, [
    "https://release.example/candidate",
    "https://downloads.example/candidate",
  ]);
  await assertRejects(
    () =>
      downloadAgent(
        "https://release.example/candidate",
        "a".repeat(64),
        undefined,
        () => Promise.resolve(new Response(bytes)),
      ),
    Error,
    "differs from the admitted digest",
  );
  let downloaded = 0;
  await assertRejects(() =>
    downloadAgent("https://release.example/candidate", pin, undefined, () => {
      downloaded++;
      return Promise.resolve(
        new Response(null, {
          status: 302,
          headers: { location: "http://insecure.example/candidate" },
        }),
      );
    })
  );
  assertEquals(downloaded, 1);
});

Deno.test("root composes the canonical network action and stores only its safe artifact outcome", async () => {
  const { nodeNetwork } = await import("./network_direct.ts");
  const f = fixture();
  const local = dependencies();
  const input = snapshot("peer-dns");
  input.network = {
    schema_version: 1,
    node_id: 209,
    agent_sha256: input.agent_sha256,
    peer_dns: {
      dns_server: "10.44.0.1",
      public_key: btoa("a".repeat(32)),
      domains: ["gateway.orbit"],
    },
  };
  let dispatched = 0;
  local.deps.network = nodeNetwork((policy) => {
    dispatched++;
    return Promise.resolve({
      changed: false,
      result: {
        state: `orbit\n${policy.server}\n${policy.domains.join("\n")}`,
        hooks:
          `PostUp = resolvectl dns %i ${policy.server}; resolvectl domain %i \\~gateway.orbit`,
        dns: `Link 2 (orbit): ${policy.server}`,
        domains: "Link 2 (orbit): ~gateway.orbit",
        default_route: "Link 2 (orbit): no",
        uplink: true,
      },
    });
  });
  const operation = nodeConverge(footprintActions(local.deps), () => f.http);
  const admission = {
    ready: true,
    status: "running" as const,
    started_at: "2026-10-30T12:00:00Z",
    snapshot: input,
  };
  await operation.methods.stage.execute({
    ...args,
    admission,
    stage: "peer-dns",
    cancellation: { cancel_requested: false },
    binary_changed: false,
  }, f.context);
  assertEquals(dispatched, 1);
  assertEquals(f.writes, [{
    stage: "peer-dns",
    ok: true,
    entry: { name: "peer-dns", status: "unchanged" },
  }]);
  await assertRejects(() =>
    operation.methods.stage.execute({
      ...args,
      node_id: 210,
      admission,
      stage: "peer-dns",
      cancellation: { cancel_requested: false },
      binary_changed: false,
    }, f.context)
  );
  assertEquals(dispatched, 1);
});

Deno.test("a cancellation recorded after admission stops the next effect and projects cancellation", async () => {
  const f = fixture();
  f.current.cancel_requested = true;
  let effects = 0;
  const operation = nodeConverge(() => {
    effects++;
    return Promise.resolve({
      stage: "agent-install",
      ok: true,
      binary_changed: false,
    });
  }, () => f.http);
  const admission = {
    ready: true,
    status: "running" as const,
    started_at: "2026-10-30T12:00:00Z",
    snapshot: snapshot(),
  };
  const cancellation = await operation.methods.observe.execute({
    ...args,
    stage: "agent-install",
  }, f.context);
  await operation.methods.stage.execute({
    ...args,
    admission,
    stage: "agent-install",
    cancellation,
    binary_changed: false,
  }, f.context);
  assertEquals(effects, 0);
  assertEquals(f.writes.at(-1), {
    stage: "agent-install",
    ok: false,
    error_code: "operation.cancelled",
  });
  await operation.methods.project.execute({
    ...args,
    admission,
    outcomes: [{
      stage: "agent-install",
      ok: false,
      error_code: "operation.cancelled",
    }],
  }, f.context);
  assertEquals(f.posts[0].status, "cancelled");
  assertEquals(f.posts[0].result, {});
  assertEquals(f.posts[0].error_code, null);
});
