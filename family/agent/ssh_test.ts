import {
  assert,
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import { z } from "npm:zod@4.4.3";
import {
  type AgentContext,
  type AgentDependencies,
  type CommandContract,
  orbitAgentExtension,
} from "./ssh.ts";
import { AgentCallError } from "./process.ts";

const contract: CommandContract = {
  name: "tool.apt-version",
  mutates: false,
  input: z.strictObject({}),
  output: z.strictObject({ version: z.string().max(255) }),
  privilege: { linux: "account", macos: null },
  timeout_seconds: 10,
  errors: ["agent.version_mismatch", "tool.apt_operation_failed"],
};
const args = {
  node_id: 209,
  operation_id: 71,
  step: "manager-version",
  command: contract.name,
  input: {},
};
function fixture(selected: CommandContract = contract) {
  const calls: Parameters<AgentDependencies["process"]>[0][] = [];
  const writes: {
    spec: string;
    name: string;
    data: Record<string, unknown>;
  }[] = [];
  let cleaned = 0;
  let prepared = 0;
  const deps: AgentDependencies = {
    prepare: (hosts, options) => {
      prepared++;
      assertEquals(options.mode, "agent");
      assertEquals(options.sshAgentBinary, "/usr/bin/ssh-agent");
      assertEquals(options.sshAddBinary, "/usr/bin/ssh-add");
      return Promise.resolve({
        hosts: hosts.map((host) => ({
          ...host,
          transport: {
            ...host.transport,
            kind: "ssh" as const,
            auth: { kind: "key" as const },
            port: 22,
            connectTimeoutSec: 10,
            controlMaster: { enabled: false, persistSec: 0 },
            extraOptions: [],
            identityContent: undefined,
            identityFile: "/tmp/test-public.pub",
            identityAgent: "/tmp/test-agent.sock",
          },
        })),
        cleanup: () => {
          cleaned++;
          return Promise.resolve();
        },
      });
    },
    process: (request) => {
      calls.push(request);
      return Promise.resolve({
        code: 0,
        stdout: JSON.stringify({ ok: true, data: { version: "1.2.3" } }) + "\n",
      });
    },
  };
  const context: AgentContext = {
    globalArgs: {
      name: "control-209",
      identityContentMode: "agent",
      transport: {
        kind: "ssh",
        user: "orbit",
        port: 22,
        hostKeyPolicy: "pinned",
        hostKeys: [
          "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIE1hsFb4g5nLZc6G/4IlOuHk9Q8OZG4mG9TV2tcqfrjY",
        ],
        identityContent: "synthetic-test-only",
        identitiesOnly: true,
        controlMaster: { enabled: false },
      },
      hosts: [{
        name: "node-209",
        address: "10.44.0.15",
        attrs: {
          node_id: 209,
          platform: "linux",
          agent_sha256: "a".repeat(64),
        },
      }],
    },
    writeResource: (spec, name, data) => {
      writes.push({ spec, name, data });
      return Promise.resolve({ name });
    },
  };
  const extension = orbitAgentExtension([selected], deps);
  return {
    calls,
    writes,
    context,
    extension,
    deps,
    execute: () => extension.methods[0].orbit_agent.execute(args, context),
    cleaned: () => cleaned,
    prepared: () => prepared,
  };
}

Deno.test("typed SSH uses only pinned identity, fixed argv and stdin before saving validated data", async () => {
  const f = fixture();
  await f.execute();
  const request = f.calls[0];
  assertEquals(request.args.slice(0, 3), ["-F", "/dev/null", "-T"]);
  for (
    const option of [
      "StrictHostKeyChecking=yes",
      "ControlMaster=no",
      "ControlPath=none",
      "IdentityAgent=/tmp/test-agent.sock",
      "IdentitiesOnly=yes",
      "ForwardAgent=no",
      "ClearAllForwardings=yes",
      "BatchMode=yes",
    ]
  ) assert(request.args.includes(option));
  assertEquals(
    request.args.at(-1),
    "'/usr/local/bin/orbit-agent' 'command' 'tool.apt-version'",
  );
  assert(!request.args.join(" ").includes("synthetic-test-only"));
  assert(!request.args.some((arg) => arg.startsWith("SendEnv=")));
  const known =
    request.args.find((arg) => arg.startsWith("UserKnownHostsFile="))!.split(
      "=",
    )[1];
  await assertRejects(() => Deno.stat(known), Deno.errors.NotFound);
  assertEquals(JSON.parse(request.input), {
    agent_sha256: "a".repeat(64),
    deadline_ms: 10000,
    input: {},
  });
  assertEquals(f.writes, [{
    spec: "agent_tool_apt_version",
    name: "op-71.manager-version",
    data: {
      node_id: 209,
      operation_id: 71,
      step: "manager-version",
      command: contract.name,
      effect_key: null,
      data: { version: "1.2.3" },
    },
  }]);
  assertEquals(f.cleaned(), 1);
});

for (
  const [label, alter] of [
    [
      "wrong Node",
      (g) => (g.hosts as Record<string, unknown>[])[0].name = "node-210",
    ],
    [
      "extra host",
      (g) => (g.hosts as unknown[]).push((g.hosts as unknown[])[0]),
    ],
    [
      "host override",
      (g) =>
        (g.hosts as Record<string, unknown>[])[0].transport = { user: "other" },
    ],
    [
      "non-WireGuard endpoint",
      (g) => (g.hosts as Record<string, unknown>[])[0].address = "8.8.8.8",
    ],
    [
      "insecure host key",
      (g) =>
        (g.transport as Record<string, unknown>).hostKeyPolicy = "insecure",
    ],
    [
      "ambient identity",
      (g) => (g.transport as Record<string, unknown>).identityFile = "/tmp/key",
    ],
    ["file fallback", (g) => g.identityContentMode = "auto"],
    [
      "multiplexing",
      (g) =>
        (g.transport as Record<string, unknown>).controlMaster = {
          enabled: true,
        },
    ],
    [
      "proxy",
      (g) => (g.transport as Record<string, unknown>).proxyCommand = "other",
    ],
    [
      "extra SSH option",
      (g) =>
        (g.transport as Record<string, unknown>).extraOptions = [
          "ForwardAgent=yes",
        ],
    ],
    [
      "environment",
      (g) =>
        (g.hosts as Record<string, unknown>[])[0].env = { TOKEN: "synthetic" },
    ],
    ["binary override", (g) => g.sshBinary = "/tmp/ssh"],
  ] satisfies [string, (g: Record<string, unknown>) => unknown][]
) {
  Deno.test(`typed SSH refuses ${label} before credential preparation`, async () => {
    const f = fixture();
    alter(f.context.globalArgs);
    await assertRejects(f.execute, AgentCallError);
    assertEquals(f.prepared(), 0);
    assertEquals(f.calls.length, 0);
    assertEquals(f.writes.length, 0);
  });
}

Deno.test("catalogue and typed registry are closed", async () => {
  assertThrows(
    () => fixture({ ...contract, name: "tool.invented" }),
    AgentCallError,
  );
  assertThrows(() => fixture({ ...contract, mutates: true }), AgentCallError);
  const f = fixture();
  await assertRejects(
    () =>
      f.extension.methods[0].orbit_agent.execute({
        ...args,
        command: "tool.apt-install",
      }, f.context),
    AgentCallError,
    "agent.command_unavailable",
  );
  assertEquals(f.prepared(), 0);
});

Deno.test("mutations use original effect identity and supervisor privilege, never caller effect_key", async () => {
  const f = fixture({
    ...contract,
    name: "tool.apt-install",
    mutates: true,
    privilege: { linux: "root", macos: null },
    input: z.strictObject({ package: z.string() }),
  });
  const effect = {
    nonce: "11111111-1111-4111-8111-111111111111",
    created_at: 1793300000,
    redactions: ["synthetic-mask"],
    booleans: [],
  };
  const call = {
    ...args,
    command: "tool.apt-install",
    input: { package: "hello" },
    effect,
  };
  await f.extension.methods[0].orbit_agent.execute(call, f.context);
  const request = f.calls[0];
  assert(
    request.args.at(-1)!.startsWith(
      "'sudo' '-n' '--' '/usr/local/bin/orbit-agent'",
    ),
  );
  assertEquals(JSON.parse(request.input), {
    agent_sha256: "a".repeat(64),
    deadline_ms: 10000,
    input: { package: "hello", effect_key: "op-71.manager-version" },
    effect_receipt: { ...effect, secret_outputs: [] },
  });
  assertEquals(f.writes[0].data.effect_key, "op-71.manager-version");
  await assertRejects(
    () =>
      f.extension.methods[0].orbit_agent.execute({
        ...call,
        input: { package: "hello", effect_key: "op-72.other" },
      }, f.context),
    AgentCallError,
  );
  await assertRejects(
    () =>
      f.extension.methods[0].orbit_agent.execute(
        { ...call, effect: undefined },
        f.context,
      ),
    AgentCallError,
  );
});

for (
  const stdout of [
    "not JSON, synthetic secret",
    '{"ok":true,"data":{"version":3}}',
    '{"ok":true,"data":{"version":"good","secret":"synthetic"}}',
    '{"ok":true,"data":{"version":"good"}}\n{"ok":true}',
    '{"ok":false,"error":{"code":"unknown.secret","message":"synthetic","details":{"secret":"synthetic"}}}',
  ]
) {
  Deno.test("invalid/unknown reply never reaches data or error text", async () => {
    const f = fixture();
    f.deps.process = () => Promise.resolve({ code: 0, stdout });
    const error = await assertRejects(f.execute, AgentCallError);
    assert(!error.message.includes("synthetic"));
    assertEquals(f.writes.length, 0);
    assertEquals(f.cleaned(), 1);
  });
}

Deno.test("known refusal exposes only its code, including agent pin mismatch", async () => {
  const f = fixture();
  f.deps.process = () =>
    Promise.resolve({
      code: 0,
      stdout: JSON.stringify({
        ok: false,
        error: {
          code: "agent.version_mismatch",
          message: "synthetic-sensitive-text",
          details: { secret: "synthetic-sensitive-text" },
        },
      }),
    });
  await assertRejects(f.execute, AgentCallError, "agent.version_mismatch");
  assertEquals(f.calls.length, 0);
  assertEquals(f.writes.length, 0);
  assertEquals(f.cleaned(), 1);
});

Deno.test("helper failure cannot leak key material or fall back to files", async () => {
  const f = fixture();
  f.deps.prepare = () => Promise.reject(new Error("synthetic-private-key"));
  const error = await assertRejects(
    f.execute,
    AgentCallError,
    "agent.call_invalid",
  );
  assert(!error.message.includes("synthetic"));
  assertEquals(f.writes.length, 0);
});

Deno.test("disconnect is uncertain and saves no completion", async () => {
  const f = fixture();
  f.deps.process = () =>
    Promise.resolve({ code: 255, stdout: "synthetic-secret" });
  await assertRejects(f.execute, AgentCallError, "agent.transport_uncertain");
  assertEquals(f.writes.length, 0);
  assertEquals(f.cleaned(), 1);
});

Deno.test("already cancelled calls do not prepare credentials", async () => {
  const f = fixture();
  f.context.signal = AbortSignal.abort();
  await assertRejects(f.execute, AgentCallError, "agent.transport_uncertain");
  assertEquals(f.prepared(), 0);
  assertEquals(f.writes.length, 0);
});

Deno.test("typed output spec keeps native sensitive metadata and the output-vault pin", () => {
  const f = fixture({
    ...contract,
    output: z.strictObject({ token: z.string().meta({ sensitive: true }) }),
  });
  const spec = f.extension.resources.agent_tool_apt_version;
  assertEquals(spec.vaultName, "orbit-control-outputs");
  const schema = z.toJSONSchema(spec.schema);
  assertEquals(
    (schema.properties!.data as {
      properties: { token: { sensitive: boolean } };
    }).properties.token.sensitive,
    true,
  );
});

Deno.test("real pinned identity preparation loads a synthetic key into an agent and removes it", async () => {
  const { prepareIdentities } = await import(
    "../../.swamp/pulled-extensions/@swamp/ssh/models/_lib/identities.ts"
  );
  const directory = await Deno.makeTempDir({ prefix: "typed-agent-identity-" });
  try {
    const output = await new Deno.Command("/usr/bin/ssh-keygen", {
      args: ["-q", "-t", "ed25519", "-N", "", "-f", `${directory}/test`],
      stdout: "null",
      stderr: "null",
    }).output();
    assertEquals(output.code, 0);
    const f = fixture();
    (f.context.globalArgs.transport as Record<string, unknown>)
      .identityContent = await Deno.readTextFile(`${directory}/test`);
    f.deps.prepare = prepareIdentities;
    await f.execute();
    const request = f.calls[0];
    const publicPath = request.args[request.args.indexOf("-i") + 1];
    assert(publicPath.endsWith(".pub"));
    await assertRejects(() => Deno.stat(publicPath), Deno.errors.NotFound);
    const socket = request.args.find((arg) => arg.startsWith("IdentityAgent="))!
      .slice("IdentityAgent=".length);
    await assertRejects(() => Deno.stat(socket), Deno.errors.NotFound);
    assert(!request.args.join(" ").includes("PRIVATE KEY"));
  } finally {
    await Deno.remove(directory, { recursive: true });
  }
});

Deno.test("one sensitive output schema also selects Node receipt sanitization paths", async () => {
  const f = fixture({
    ...contract,
    name: "tool.apt-install",
    mutates: true,
    output: z.strictObject({ token: z.string().meta({ sensitive: true }) }),
  });
  f.deps.process = (request) => {
    f.calls.push(request);
    return Promise.resolve({
      code: 0,
      stdout: '{"ok":true,"data":{"token":"synthetic-output"}}',
    });
  };
  await f.extension.methods[0].orbit_agent.execute({
    ...args,
    command: "tool.apt-install",
    effect: {
      nonce: "11111111-1111-4111-8111-111111111111",
      created_at: 1793300000,
      redactions: [],
      booleans: [],
    },
  }, f.context);
  assertEquals(JSON.parse(f.calls[0].input).effect_receipt.secret_outputs, [
    "token",
  ]);
  assertEquals(
    f.extension.resources.agent_tool_apt_install.vaultName,
    "orbit-control-outputs",
  );
  assertThrows(
    () =>
      fixture({
        ...contract,
        output: z.strictObject({
          rows: z.array(
            z.strictObject({ secret: z.string().meta({ sensitive: true }) }),
          ),
        }),
      }),
    AgentCallError,
  );
});

Deno.test("cleanup errors expose a fixed code instead of helper output", async () => {
  const f = fixture();
  const prepare = f.deps.prepare;
  f.deps.prepare = async (...input) => ({
    ...await prepare(...input),
    cleanup: () => Promise.reject(new Error("synthetic-sensitive-cleanup")),
  });
  await assertRejects(f.execute, AgentCallError, "agent.cleanup_failed");
});

Deno.test("macOS uses the enrolled account path and no Linux sudo wrapper", async () => {
  const f = fixture({
    ...contract,
    name: "tool.homebrew-version",
    privilege: { linux: "account", macos: "account" },
  });
  ((f.context.globalArgs.hosts as Record<string, unknown>[])[0].attrs as Record<
    string,
    unknown
  >).platform = "macos";
  ((f.context.globalArgs.hosts as Record<string, unknown>[])[0].attrs as Record<
    string,
    unknown
  >).agent_path = "/Users/orbit/.orbit/bin/orbit-agent";
  await f.extension.methods[0].orbit_agent.execute({
    ...args,
    command: "tool.homebrew-version",
  }, f.context);
  assertEquals(
    f.calls[0].args.at(-1),
    "'/Users/orbit/.orbit/bin/orbit-agent' 'command' 'tool.homebrew-version'",
  );
});

Deno.test("oversized input and unsupported platforms stop before credential preparation", async () => {
  const f = fixture({
    ...contract,
    input: z.strictObject({ value: z.string() }),
  });
  await assertRejects(
    () =>
      f.extension.methods[0].orbit_agent.execute({
        ...args,
        input: { value: "x".repeat(4 * 1024 * 1024) },
      }, f.context),
    AgentCallError,
    "agent.input_limit",
  );
  assertEquals(f.prepared(), 0);
  ((f.context.globalArgs.hosts as Record<string, unknown>[])[0].attrs as Record<
    string,
    unknown
  >).platform = "macos";
  ((f.context.globalArgs.hosts as Record<string, unknown>[])[0].attrs as Record<
    string,
    unknown
  >).agent_path = "/Users/orbit/.orbit/bin/orbit-agent";
  await assertRejects(f.execute, AgentCallError, "agent.platform_unsupported");
  assertEquals(f.prepared(), 0);
});

Deno.test("repair and bootstrap commands are forbidden even if present in the catalogue", () => {
  for (
    const name of [
      "agent.install",
      "agent.remove",
      "agent.configure",
      "cli.self-update",
    ]
  ) {
    assertThrows(
      () => fixture({ ...contract, name, mutates: true }),
      AgentCallError,
      "agent.command_forbidden",
    );
  }
});

Deno.test("macOS requires an absolute admitted agent path before credentials are prepared", async () => {
  for (
    const path of [
      undefined,
      ".orbit/bin/orbit-agent",
      "/Users/orbit/../other/.orbit/bin/orbit-agent",
      "/tmp/arbitrary",
    ]
  ) {
    const f = fixture({
      ...contract,
      name: "tool.homebrew-version",
      privilege: { linux: "account", macos: "account" },
    });
    const attrs = (f.context.globalArgs.hosts as Record<string, unknown>[])[0]
      .attrs as Record<string, unknown>;
    attrs.platform = "macos";
    if (path !== undefined) attrs.agent_path = path;
    await assertRejects(
      () =>
        f.extension.methods[0].orbit_agent.execute({
          ...args,
          command: "tool.homebrew-version",
        }, f.context),
      AgentCallError,
    );
    assertEquals(f.prepared(), 0);
  }
});
