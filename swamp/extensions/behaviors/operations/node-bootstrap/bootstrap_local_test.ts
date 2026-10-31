import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import {
  BootstrapInput,
  nodeBootstrapActions as nativeBootstrapActions,
} from "./bootstrap_local.ts";
import {
  bootstrapHostKey,
  type BootstrapSsh,
  bootstrapSystem,
} from "./bootstrap_ssh.ts";

const pin = "a".repeat(64);
const nodeBootstrapActions = (
  dependencies: NonNullable<Parameters<typeof nativeBootstrapActions>[0]>,
) =>
  nativeBootstrapActions({
    ...dependencies,
    hostKey: () => Promise.resolve("ssh-ed25519 AAAA"),
  });
const target = {
  node_id: 13,
  host: "203.0.113.8",
  port: 2222,
  user: "root",
  fingerprint: "SHA256:" + "a".repeat(43),
};
const environment = {
  createCelEnvironment: () => ({
    parse: () => () => true,
    registerFunction: () => {},
  }),
};
const linux = () => ({
  schema_version: 1 as const,
  node_id: 13,
  platform: "linux" as const,
  target,
  managed_user: "nckrtl",
  expected_architecture: "x86_64" as const,
  address: "10.44.0.13",
  artifacts: {
    "linux-x86_64": { sha256: pin, url: "https://example.com/agent" },
  },
  command: {
    user: "nckrtl",
    public_key: "ssh-ed25519 AA",
    releases: ["resolute"],
    packages: ["curl"],
    dns_policy: "preserve" as const,
  },
  firewall: null,
});
const observation = {
  schema_version: 1,
  node_id: 13,
  platform: "linux",
  architecture: "x86_64",
  agent_sha256: pin,
  installed: true,
  host_key_type: "ssh-ed25519",
  host_public_key: "AAAA",
} as const;

Deno.test("bootstrap verifies scanned key fingerprint before granting SSH trust", async () => {
  const blob = new Uint8Array([
    0,
    0,
    0,
    11,
    ...new TextEncoder().encode("ssh-ed25519"),
    0,
    0,
    0,
    32,
    ...new Uint8Array(32).fill(7),
  ]);
  const key = "ssh-ed25519 " + btoa(String.fromCharCode(...blob));
  const digest = new Uint8Array(await crypto.subtle.digest("SHA-256", blob));
  const fingerprint = "SHA256:" +
    btoa(String.fromCharCode(...digest)).replace(/=+$/, "");
  const scan = (argv: string[]) => {
    assertEquals(argv, ["-T", "10", "-p", "2222", target.host]);
    return Promise.resolve(target.host + " " + key + "\n");
  };
  assertEquals(await bootstrapHostKey({ ...target, fingerprint }, scan), key);
  await assertRejects(
    () => bootstrapHostKey(target, scan),
    Error,
    "node.ssh_host_key_mismatch",
  );
});

Deno.test("bootstrap refuses foreign target, account changes on macOS and untrusted delivery URLs", () => {
  const input = linux();
  assertThrows(() =>
    BootstrapInput.parse({ ...input, target: { ...target, node_id: 14 } })
  );
  assertThrows(() =>
    BootstrapInput.parse({ ...input, platform: "macos", command: null })
  );
  assertThrows(() =>
    BootstrapInput.parse({
      ...input,
      artifacts: {
        "linux-x86_64": { sha256: pin, url: "http://example.com/agent" },
      },
    })
  );
  assertThrows(() =>
    BootstrapInput.parse({
      ...input,
      artifacts: {
        "linux-x86_64": {
          sha256: pin,
          url: "https://secret@example.com/agent",
        },
      },
    })
  );
});

Deno.test("wrong platform and architecture refuse before download or remote mutation", async () => {
  for (const values of [["Darwin", "arm64"], ["Linux", "aarch64"]]) {
    const commands: string[][] = [];
    const ssh: BootstrapSsh = (_target, _identity, _environment, argv) => {
      commands.push(argv);
      return Promise.resolve(values[commands.length - 1]);
    };
    const action = nodeBootstrapActions({
      ssh,
      download: () => {
        throw new Error("must not download");
      },
    });
    const result = await action.install(linux(), "/private/key", environment);
    assertEquals("bootstrapped" in result && result.bootstrapped, false);
    assertEquals(commands.every((argv) => argv[0] === "/usr/bin/uname"), true);
  }
});

Deno.test("bootstrap installs only verified delivery via fixed candidate installer", async () => {
  const commands: string[][] = [];
  const ssh: BootstrapSsh = (
    actual,
    _identity,
    _environment,
    argv,
    stdin,
    _seconds,
    upload,
  ) => {
    assertEquals(actual, target);
    commands.push(argv);
    if (upload) {
      assertEquals(upload.dst, "/tmp/orbit-bootstrap-agent-AbCd12345678/agent");
      return Promise.resolve("");
    }
    if (argv[1] === "-s") return Promise.resolve("Linux\n");
    if (argv[1] === "-m") return Promise.resolve("x86_64\n");
    if (argv[0] === "/usr/bin/mktemp") {
      return Promise.resolve("/tmp/orbit-bootstrap-agent-AbCd12345678\n");
    }
    if (argv[0] === "/usr/bin/python3") {
      assertEquals(JSON.parse(stdin!), {
        candidate: "/tmp/orbit-bootstrap-agent-AbCd12345678/agent",
        sha256: pin,
      });
      return Promise.resolve(JSON.stringify({ replaced: true, sha256: pin }));
    }
    assertEquals(argv, [
      "/bin/rm",
      "-rf",
      "--",
      "/tmp/orbit-bootstrap-agent-AbCd12345678",
    ]);
    return Promise.resolve("");
  };
  const action = nodeBootstrapActions({
    ssh,
    download: (_url, actual) => {
      assertEquals(actual, pin);
      return Promise.resolve(new Uint8Array([1, 2, 3]));
    },
  });
  assertEquals(
    await action.install(linux(), "/private/key", environment),
    observation,
  );
  assertEquals(commands.some((argv) => argv.includes("sh")), false);
});

Deno.test("host stage reuses the admitted installer observation and verifies managed account", async () => {
  const calls: { user: string; argv: string[] }[] = [];
  const ssh: BootstrapSsh = (actual, _identity, _environment, argv) => {
    calls.push({ user: actual.user, argv });
    return Promise.resolve(
      argv[0] === "/usr/bin/python3"
        ? JSON.stringify({ prepared: true })
        : argv.at(-1) === "-un"
        ? "nckrtl\n"
        : "0\n",
    );
  };
  const action = nodeBootstrapActions({
    ssh,
    download: () => {
      throw new Error("no download");
    },
  });
  assertEquals(
    await action.host(linux(), observation, "/private/key", environment),
    { ...observation, bootstrapped: true },
  );
  assertEquals(calls.map((c) => c.user), ["root", "nckrtl", "nckrtl"]);
  await assertRejects(
    () =>
      action.host(
        linux(),
        { ...observation, node_id: 14 },
        "/private/key",
        environment,
      ),
    Error,
    "node.bootstrap_observation_mismatch",
  );
  await assertRejects(
    () =>
      action.host(
        linux(),
        { ...observation, agent_sha256: "b".repeat(64) },
        "/private/key",
        environment,
      ),
    Error,
    "node.bootstrap_observation_mismatch",
  );
});

Deno.test("Mac inspection keeps account/network immutable and never runs sudo/bootstrap/firewall", async () => {
  const input = {
    ...linux(),
    platform: "macos",
    target: { ...target, user: "nckrtl" },
    command: null,
    firewall: null,
    artifacts: {
      "macos-aarch64": { sha256: pin, url: "https://example.com/agent" },
    },
  };
  const commands: string[][] = [];
  const ssh: BootstrapSsh = (actual, _identity, _environment, argv, _stdin) => {
    assertEquals(actual.user, "nckrtl");
    commands.push(argv);
    return Promise.resolve(
      argv[0] === "/usr/bin/id"
        ? "nckrtl\n"
        : "utun0:\n\tinet " + input.address + " netmask 0xffffffff\n",
    );
  };
  const action = nodeBootstrapActions({
    ssh,
    download: () => {
      throw new Error("no download");
    },
  });
  const observed = {
    ...observation,
    platform: "macos",
    architecture: "arm64",
  } as const;
  assertEquals(
    await action.host(input, observed, "/private/key", environment),
    { ...observed, bootstrapped: true },
  );
  assertEquals(commands, [["/usr/bin/id", "-un"], ["/sbin/ifconfig"]]);
});

Deno.test("same-root SSH system adapter preserves private stdin without calling the agent", async () => {
  const invoke = bootstrapSystem(
    target,
    "/private/key",
    environment,
    (_target, _identity, _environment, argv, stdin) => {
      assertEquals(argv, ["/usr/bin/python3", "-c", "fixed-program"]);
      assertEquals(stdin, "synthetic-private-input");
      return Promise.resolve('{"prepared":true}');
    },
  );
  assertEquals(
    await invoke(
      ["/usr/bin/python3", "-c", "fixed-program"],
      "synthetic-private-input",
    ),
    { success: true, stdout: '{"prepared":true}' },
  );
});

Deno.test("pre-cancelled guest bootstrap install and host perform no probe, private transport or mutation", async () => {
  let effects = 0;
  const unexpected = () => {
    effects++;
    return Promise.reject(new Error("unexpected effect"));
  };
  const action = nodeBootstrapActions({
    ssh: unexpected,
    download: unexpected,
  });
  for (
    const call of [
      () =>
        action.install(
          linux(),
          "/private/key",
          environment,
          AbortSignal.abort(),
        ),
      () =>
        action.host(
          linux(),
          observation,
          "/private/key",
          environment,
          AbortSignal.abort(),
        ),
    ]
  ) await assertRejects(call);
  assertEquals(effects, 0);
});

Deno.test("probe returns the scanned host identity without downloading or mutating", async () => {
  const calls: string[][] = [];
  const action = nodeBootstrapActions({
    ssh: (_target, _identity, _environment, argv) => {
      calls.push(argv);
      return Promise.resolve(argv[1] === "-s" ? "Linux\n" : "x86_64\n");
    },
    download: () => {
      throw new Error("probe must not download");
    },
  });
  assertEquals(
    await action.probe(
      { ...linux(), artifacts: {} },
      "/private/key",
      environment,
    ),
    {
      schema_version: 1,
      node_id: 13,
      platform: "linux",
      architecture: "x86_64",
      host_key_type: "ssh-ed25519",
      host_public_key: "AAAA",
      probed: true,
    },
  );
  assertEquals(calls, [["/usr/bin/uname", "-s"], ["/usr/bin/uname", "-m"]]);
});

Deno.test("verify checks the installed observer and candidate login without running preparation", async () => {
  const calls: { user: string; argv: string[] }[] = [];
  let digest = pin;
  const action = nodeBootstrapActions({
    ssh: (target, _identity, _environment, argv) => {
      calls.push({ user: target.user, argv });
      return Promise.resolve(
        argv[0] === "/usr/bin/sha256sum"
          ? digest + "  /usr/local/bin/orbit-agent\n"
          : argv.at(-1) === "-un"
          ? "nckrtl\n"
          : "0\n",
      );
    },
    download: () => {
      throw new Error("verify must not download");
    },
  });
  assertEquals(
    await action.verify(linux(), observation, "/private/key", environment),
    { ...observation, bootstrapped: true },
  );
  assertEquals(calls.map((c) => c.user), ["nckrtl", "nckrtl", "nckrtl"]);
  assertEquals(calls.some((c) => c.argv[0] === "/usr/bin/python3"), false);
  digest = "b".repeat(64);
  await assertRejects(
    () => action.verify(linux(), observation, "/private/key", environment),
    Error,
    "node.bootstrap_observer_mismatch",
  );
});
