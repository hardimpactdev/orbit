import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { applyBootstrap, nodeBootstrapDirect } from "./direct.ts";
import {
  BootstrapInput,
  type nodeBootstrapActions,
} from "./bootstrap_local.ts";

const pin = "a".repeat(64);
const input = () =>
  BootstrapInput.parse({
    schema_version: 1,
    node_id: 209,
    platform: "linux",
    target: {
      node_id: 209,
      host: "192.0.2.209",
      port: 2222,
      user: "bootstrap",
      fingerprint: "SHA256:" + "a".repeat(43),
    },
    managed_user: "candidate",
    expected_architecture: "x86_64",
    address: "10.44.0.209",
    artifacts: {
      "linux-x86_64": { sha256: pin, url: "https://example.com/observer" },
    },
    command: {
      user: "candidate",
      public_key: "ssh-ed25519 AAAA",
      releases: ["resolute"],
      packages: ["curl"],
      dns_policy: "preserve",
    },
    firewall: null,
  });
const observation = {
  schema_version: 1 as const,
  node_id: 209,
  platform: "linux" as const,
  architecture: "x86_64" as const,
  host_key_type: "ssh-ed25519" as const,
  host_public_key: "AAAA",
  agent_sha256: pin,
  installed: false,
};
const environment = {
  createCelEnvironment: () => ({
    parse: () => () => true,
    registerFunction: () => {},
  }),
};
function fixture() {
  const calls: string[] = [];
  const actions: ReturnType<typeof nodeBootstrapActions> = {
    probe: () => {
      calls.push("probe");
      return Promise.resolve({ ...observation, probed: true as const });
    },
    install: () => {
      calls.push("install");
      return Promise.resolve(observation);
    },
    host: () => {
      calls.push("host");
      return Promise.resolve({ ...observation, bootstrapped: true as const });
    },
    verify: () => {
      calls.push("verify");
      return Promise.resolve({ ...observation, bootstrapped: true as const });
    },
  };
  return { calls, actions };
}
Deno.test("standalone bootstrap kinds compose native lower actions without child Operations", async () => {
  for (
    const [kind, wanted] of [
      ["node.bootstrap_install", ["install"]],
      ["node.bootstrap_host", ["install", "host"]],
      ["node.bootstrap_verify", ["probe", "verify"]],
    ] as const
  ) {
    const f = fixture();
    const result = await applyBootstrap(
      kind,
      input(),
      "/private/key",
      environment,
      undefined,
      f.actions,
    );
    assertEquals(f.calls, [...wanted]);
    assertEquals(result.error_code, undefined);
    assertEquals(result.architecture, "x86_64");
    assertEquals(result.public_key, "AAAA");
    if (kind === "node.bootstrap_install") {
      assertEquals(result.installed, true);
      assertEquals(result.changed, false);
    }
  }
});
Deno.test("Mac inspection only probes and verifies its existing account", async () => {
  const f = fixture();
  f.actions.probe = () => {
    f.calls.push("probe");
    return Promise.resolve({
      ...observation,
      platform: "macos",
      architecture: "arm64",
      probed: true,
    });
  };
  f.actions.verify = () => {
    f.calls.push("verify");
    return Promise.resolve({
      ...observation,
      platform: "macos",
      architecture: "arm64",
      bootstrapped: true,
    });
  };
  const raw = {
    ...input(),
    platform: "macos",
    expected_architecture: "arm64",
    managed_user: "candidate",
    target: { ...input().target, user: "candidate" },
    command: null,
    artifacts: {
      "macos-aarch64": { sha256: pin, url: "https://example.com/observer" },
    },
  };
  const result = await applyBootstrap(
    "node.macos_inspect",
    raw,
    "/private/key",
    environment,
    undefined,
    f.actions,
  );
  assertEquals(f.calls, ["probe", "verify"]);
  assertEquals(result.bootstrap_verified, true);
  await assertRejects(() =>
    applyBootstrap(
      "node.bootstrap_host",
      raw,
      "/private/key",
      environment,
      undefined,
      f.actions,
    )
  );
});
Deno.test("bootstrap protocol keeps identity callback-local and projects only bounded observations", async () => {
  const secret = "/private/bootstrap-key-sentinel";
  const args = {
    operation_id: 7,
    kind: "node.bootstrap_verify" as const,
    node_id: 14,
    run_id: "0d363abd-ec96-4979-8866-f1c0233c576c",
  };
  const operation = {
    id: 7,
    kind: args.kind,
    node_id: 14,
    status: "queued",
    result: null,
    cancel_requested: false,
    input: { bootstrap: input() },
  };
  const calls: { method: string; path: string; body: unknown }[] = [];
  const http = (method: string, path: string, body?: unknown) => {
    calls.push({ method, path, body });
    return Promise.resolve({
      status: 200,
      body: {
        data: path.includes("private-material")
          ? { bootstrap_identity: secret }
          : operation,
      },
    });
  };
  let invoked = 0;
  const behavior = nodeBootstrapDirect(
    () => http,
    (kind, raw, identity) => {
      invoked++;
      assertEquals(kind, args.kind);
      assertEquals(raw, input());
      assertEquals(identity, secret);
      return Promise.resolve({
        bootstrap_verified: true,
        architecture: "x86_64",
        host_type: "ssh-ed25519",
        public_key: "AAAA",
        host_key_fingerprint: input().target.fingerprint,
      });
    },
  );
  const ctx = {
    ...environment,
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/ca" },
  };
  assertEquals(await behavior.methods.apply.execute(args, ctx), {
    status: "succeeded",
  });
  assertEquals(invoked, 1);
  assertEquals(
    calls.filter((c) => c.path.includes("private-material")).length,
    1,
  );
  assertEquals(JSON.stringify(calls).includes(secret), false);
  assertEquals(
    calls.some((c) => /operation-executor|children|receipt/.test(c.path)),
    false,
  );
  const posts = calls.filter((c) => c.method === "POST");
  assertEquals((posts.at(-1)?.body as { status: string }).status, "succeeded");
  operation.cancel_requested = true;
  calls.length = 0;
  assertEquals(await behavior.methods.apply.execute(args, ctx), {
    status: "cancelled",
  });
  assertEquals(invoked, 1);
  assertEquals(calls.some((c) => c.path.includes("private-material")), false);
  operation.node_id = 99;
  await assertRejects(() => behavior.methods.apply.execute(args, ctx));
  assertEquals(invoked, 1);
});
