import { fakeNetworkSystem } from "./network_test_support.ts";
import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { nodeWireGuard } from "./wireguard_direct.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
const args = {
  operation_id: 7,
  kind: "node.wireguard_hub_converge",
  node_id: 1,
  run_id: "ff3b7992-7376-4ac7-a49f-ed45bedc8de5",
};
const snapshot = {
  schema_version: 1 as const,
  executor_node_id: 1,
  hub_id: 1,
  context_node_id: 13,
  excluded_node_id: null,
  agent_sha256: "a".repeat(64),
};
const privateKey = "A".repeat(43) + "=";
const material = {
  snapshot,
  address: "10.44.0.1/24",
  port: 51820,
  private_key: privateKey,
  peers: [],
};
function fixture(cancel_requested = false) {
  const posts: unknown[] = [];
  const resources: unknown[] = [];
  const http: Http = (method, path, body) => {
    if (method === "POST") posts.push(body);
    return Promise.resolve({
      status: 200,
      body: {
        data: path.includes("private-material")
          ? { configuration: material }
          : {
            id: 7,
            node_id: 1,
            kind: args.kind,
            status: "queued",
            cancel_requested,
            input: snapshot,
          },
      },
    });
  };
  const ctx = {
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/tmp/test-ca" },
    writeResource: (_s: string, _n: string, value: unknown) => {
      resources.push(value);
      return Promise.resolve({});
    },
  };
  return { posts, resources, http, ctx };
}
Deno.test("hub root cancellation settles before private read or effect", async () => {
  const f = fixture(true);
  let calls = 0;
  const behavior = nodeWireGuard(
    undefined,
    () => f.http,
    fakeNetworkSystem(() => {
      calls++;
      return Promise.resolve({});
    }),
  );
  const admission = await behavior.methods.admit.execute(args, f.ctx);
  assertEquals(admission.ready, false);
  assertEquals(calls, 0);
  assertEquals((f.posts[0] as { status: string }).status, "cancelled");
});
Deno.test("hub root refuses wrong root placement before projecting", async () => {
  const f = fixture();
  const behavior = nodeWireGuard(
    undefined,
    () => () =>
      Promise.resolve({
        status: 200,
        body: {
          data: {
            id: 7,
            node_id: 14,
            kind: args.kind,
            status: "queued",
            cancel_requested: false,
            input: snapshot,
          },
        },
      }),
  );
  await assertRejects(
    () => behavior.methods.admit.execute({ ...args, node_id: 14 }, f.ctx),
    Error,
    "admitted Node",
  );
  assertEquals(f.posts.length, 0);
});
Deno.test("hub publication keeps existing private material callback local", async () => {
  const f = fixture();
  let effects = 0;
  const behavior = nodeWireGuard(
    undefined,
    () => f.http,
    fakeNetworkSystem((call) => {
      effects++;
      assertEquals(call.name, "wireguard.project-hub");
      assertEquals(
        call.input.config,
        `[Interface]\nAddress = 10.44.0.1/24\nListenPort = 51820\nPrivateKey = ${privateKey}\n`,
      );
      return Promise.resolve({});
    }),
  );
  const admission = await behavior.methods.admit.execute(args, f.ctx);
  const outcome = await behavior.methods.publish.execute(
    { ...args, snapshot },
    f.ctx,
  );
  await behavior.methods.project.execute(
    { ...args, admission, outcome },
    f.ctx,
  );
  assertEquals(effects, 1);
  assertEquals(outcome.status, "succeeded");
  assertEquals(JSON.stringify(f.resources).includes(privateKey), false);
  assertEquals(JSON.stringify(f.posts).includes(privateKey), false);
});
Deno.test("hub private transport failures remain sanitized", async () => {
  const f = fixture();
  const behavior = nodeWireGuard(
    undefined,
    () => f.http,
    fakeNetworkSystem(() => Promise.reject(new Error(privateKey))),
  );
  const error = await assertRejects(
    () => behavior.methods.publish.execute({ ...args, snapshot }, f.ctx),
    Error,
    "Private material callback failed",
  );
  assertEquals(error.message.includes(privateKey), false);
  assertEquals(f.resources.length, 0);
});

Deno.test("TaskVM shared hub method reads fresh private configuration before its Gateway SSH effect", async () => {
  const f = fixture();
  const remote = { ...snapshot, executor_node_id: 14 };
  let reads = 0;
  let effects = 0;
  const http: Http = (_method, path) => {
    if (!path.includes("private-material")) {
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            id: args.operation_id,
            kind: "task_vm.enroll",
            node_id: 14,
            status: "running",
            cancel_requested: false,
          },
        },
      });
    }
    reads++;
    return Promise.resolve({
      status: 200,
      body: {
        data: {
          configuration: {
            ...material,
            snapshot: remote,
            connection: {
              target: {
                node_id: 1,
                host: "vpn.example.test",
                port: 22,
                user: "orbit",
                fingerprint: "SHA256:" + "A".repeat(43),
              },
              identity: "/private/existing-key",
            },
            peers: [{
              node_id: 13,
              name: "fresh",
              public_key: "B".repeat(43) + "=",
              address: "10.44.0.13",
            }],
          },
        },
      },
    });
  };
  const behavior = nodeWireGuard(
    undefined,
    () => http,
    () => Promise.reject(new Error("must not run locally")),
    (_target, _identity, _environment, _argv, stdin) => {
      effects++;
      assertEquals(reads, 1);
      assertEquals(JSON.parse(stdin!).input.config.includes("# fresh"), true);
      return Promise.resolve("{}");
    },
  );
  const ctx = {
    ...f.ctx,
    createCelEnvironment: () => {
      throw new Error("unused fixture");
    },
  };
  const result = await behavior.methods.publish.execute({
    ...args,
    kind: "task_vm.enroll",
    node_id: 14,
    snapshot: remote,
  }, ctx);
  assertEquals(effects, 1);
  assertEquals(result.status, "succeeded");
  assertEquals(JSON.stringify(f.resources).includes(privateKey), false);
  assertEquals(JSON.stringify(f.resources).includes("/private/"), false);
});

Deno.test("cancelled shared hub publication refuses before its private read or effect", async () => {
  const f = fixture(true);
  let privateReads = 0;
  let effects = 0;
  const http: Http = (method, path, body) => {
    if (path.includes("private-material")) privateReads++;
    return f.http(method, path, body);
  };
  const behavior = nodeWireGuard(
    undefined,
    () => http,
    fakeNetworkSystem(() => {
      effects++;
      return Promise.resolve({});
    }),
  );
  await assertRejects(
    () => behavior.methods.publish.execute({ ...args, snapshot }, f.ctx),
    Error,
    "operation.cancelled",
  );
  assertEquals(privateReads, 0);
  assertEquals(effects, 0);
});

Deno.test("cancellation during the hub private read refuses the next effect boundary", async () => {
  const f = fixture();
  let cancelled = false;
  let effects = 0;
  const http: Http = (method, path, body) => {
    if (path.includes("private-material")) cancelled = true;
    if (cancelled && !path.includes("private-material")) {
      return fixture(true).http(method, path, body);
    }
    return f.http(method, path, body);
  };
  const behavior = nodeWireGuard(
    undefined,
    () => http,
    fakeNetworkSystem(() => {
      effects++;
      return Promise.resolve({});
    }),
  );
  await assertRejects(
    () => behavior.methods.publish.execute({ ...args, snapshot }, f.ctx),
    Error,
    "Private material callback failed",
  );
  assertEquals(effects, 0);
});

function restorationFixture(kind = "node.add") {
  const f = fixture();
  const remote = { ...snapshot, executor_node_id: 14 };
  const current = {
    id: args.operation_id,
    node_id: 14,
    kind,
    status: "running",
    cancel_requested: true,
  };
  const privateValue = {
    ...material,
    snapshot: remote,
    connection: {
      target: {
        node_id: 1,
        host: "vpn.example.test",
        port: 22,
        user: "orbit",
        fingerprint: "SHA256:" + "A".repeat(43),
      },
      identity: "/private/existing-key",
    },
  };
  const control = { beforePrivate: () => {} };
  const reads: string[] = [];
  const signals: (AbortSignal | undefined)[] = [];
  const http: Http = (_method, path) => {
    reads.push(path);
    if (path.includes("private-material")) control.beforePrivate();
    return Promise.resolve({
      status: 200,
      body: {
        data: path.includes("private-material")
          ? { configuration: privateValue }
          : current,
      },
    });
  };
  const behavior = nodeWireGuard(
    undefined,
    () => http,
    () => Promise.reject(new Error("must not run locally")),
    (
      _target,
      _identity,
      _environment,
      _argv,
      _stdin,
      _timeout,
      _upload,
      signal,
    ) => {
      signals.push(signal);
      return Promise.resolve("{}");
    },
  );
  return {
    ...f,
    current,
    control,
    privateValue,
    reads,
    signals,
    behavior,
    args: { ...args, kind, node_id: 14, snapshot: remote },
    ctx: {
      ...f.ctx,
      signal: AbortSignal.abort(),
      createCelEnvironment: () => {
        throw new Error("unused fixture");
      },
    },
  };
}

Deno.test("shared hub recovery restores the selected view despite cancellation and an aborted signal", async () => {
  for (const kind of ["node.add", "node.remove", "task_vm.destroy"]) {
    const f = restorationFixture(kind);
    const result = await f.behavior.methods.publish.execute({
      ...f.args,
      recovery: true,
    }, f.ctx);
    assertEquals(result.status, "succeeded");
    assertEquals(f.signals, [undefined]);
    assertEquals(f.reads.length, 3);
    assertEquals(f.reads.every((path) => path.includes(args.run_id)), true);
    assertEquals(f.current.status, "running");
    assertEquals(f.current.cancel_requested, true);
    assertEquals(JSON.stringify(f.resources).includes(privateKey), false);
  }
});

Deno.test("shared hub publication defaults to cancellation refusal even for a restoration-capable parent", async () => {
  const f = restorationFixture();
  await assertRejects(
    () => f.behavior.methods.publish.execute(f.args, f.ctx),
  );
  assertEquals(f.reads, []);
  assertEquals(f.signals, []);
});

Deno.test("hub recovery retains fresh root checks before private reads and again before SSH", async () => {
  const changes = [
    (f: ReturnType<typeof restorationFixture>) => {
      f.current.id++;
    },
    (f: ReturnType<typeof restorationFixture>) => {
      f.current.node_id++;
    },
    (f: ReturnType<typeof restorationFixture>) => {
      f.current.kind = "node.remove";
    },
    (f: ReturnType<typeof restorationFixture>) => {
      f.current.status = "failed";
    },
  ];
  for (const late of [false, true]) {
    for (const change of changes) {
      const f = restorationFixture();
      if (late) f.control.beforePrivate = () => change(f);
      else change(f);
      await assertRejects(
        () =>
          f.behavior.methods.publish.execute(
            { ...f.args, recovery: true },
            f.ctx,
          ),
      );
      assertEquals(f.signals, []);
      assertEquals(
        f.reads.some((path) => path.includes("private-material")),
        late,
      );
      assertEquals(f.resources, []);
    }
  }
});

Deno.test("hub recovery refuses a snapshot that differs from the source-selected restoration", async () => {
  const f = restorationFixture();
  await assertRejects(
    () =>
      f.behavior.methods.publish.execute({
        ...f.args,
        snapshot: { ...f.args.snapshot, excluded_node_id: 13 },
        recovery: true,
      }, f.ctx),
    Error,
    "Private material callback failed",
  );
  assertEquals(f.signals, []);
  assertEquals(f.resources, []);
});
