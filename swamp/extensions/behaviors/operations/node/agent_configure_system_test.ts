import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import {
  agentConfigurationProgram,
  configureAgent,
} from "./agent_configure_system.ts";
import { localSystemCommand, type SystemTransport } from "./system_command.ts";

const hash = async (value: string) =>
  Array.from(
    new Uint8Array(
      await crypto.subtle.digest("SHA-256", new TextEncoder().encode(value)),
    ),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
async function fixture() {
  const root = await Deno.makeTempDir({ prefix: "node-configure-system-" });
  for (
    const directory of [
      "etc/orbit/agent",
      "etc/systemd/system",
      "run/lock",
      "usr/local/bin",
      "proc",
    ]
  ) {
    await Deno.mkdir(`${root}/${directory}`, { recursive: true });
    let path = root;
    for (const part of directory.split("/")) {
      path += "/" + part;
      await Deno.chmod(path, 0o755);
    }
  }
  await Deno.chmod(`${root}/etc/orbit/agent`, 0o700);
  const secret = "owned-existing-secret";
  await Deno.writeTextFile(`${root}/etc/orbit/agent/secret`, secret, {
    mode: 0o600,
  });
  await Deno.writeTextFile(
    `${root}/usr/local/bin/orbit-agent`,
    "observer-binary",
    { mode: 0o755 },
  );
  await Deno.writeTextFile(
    `${root}/proc/meminfo`,
    "MemAvailable: 1048576 kB\n",
  );
  const input = {
    agent_sha256: await hash("observer-binary"),
    secret_hash: await hash(secret),
    allow_secret_rotation: false,
    configuration: "node_id = 13\n",
    certificate: "public-ca",
    unit: "[Service]\nExecStart=/usr/local/bin/orbit-agent observe\n",
    checkouts: [],
    binary_changed: false,
    worker_credentials: null,
  };
  const execute: SystemTransport = (command, signal) =>
    localSystemCommand({
      ...command,
      command: "/usr/bin/python3",
      args: ["-c", agentConfigurationProgram(root, Deno.uid()!)],
    }, signal);
  return { root, input, execute };
}

Deno.test("direct configuration preserves credential bytes and settles unchanged on retry", async () => {
  const f = await fixture();
  try {
    const before = await Deno.stat(`${f.root}/etc/orbit/agent/secret`);
    const result = await configureAgent(f.input, f.execute);
    assertEquals(result, {
      configured: true,
      secret_hash: f.input.secret_hash,
      restarted: true,
      unclosed: [],
    });
    assertEquals(
      await Deno.readTextFile(`${f.root}/etc/orbit/agent/secret`),
      "owned-existing-secret",
    );
    assertEquals(
      (await Deno.stat(`${f.root}/etc/orbit/agent/secret`)).ino,
      before.ino,
    );
    assertEquals(await configureAgent(f.input, f.execute), {
      configured: true,
      secret_hash: f.input.secret_hash,
      restarted: false,
      unclosed: [],
    });
  } finally {
    await Deno.remove(f.root, { recursive: true });
  }
});

Deno.test("nonrotating mismatch refuses before changing any protected file", async () => {
  const f = await fixture();
  try {
    const path = `${f.root}/etc/orbit/agent/secret`;
    const before = await Deno.stat(path);
    assertEquals(
      await configureAgent(
        { ...f.input, secret_hash: "0".repeat(64) },
        f.execute,
      ),
      { configured: false, error_code: "agent.secret_mismatch" },
    );
    assertEquals(await Deno.readTextFile(path), "owned-existing-secret");
    assertEquals((await Deno.stat(path)).ino, before.ino);
    assertRejects(
      () => Deno.stat(`${f.root}/etc/orbit/agent/config.toml`),
      Deno.errors.NotFound,
    );
  } finally {
    await Deno.remove(f.root, { recursive: true });
  }
});

Deno.test("memory floor precedes secret rotation configuration and unit effects", async () => {
  const f = await fixture();
  try {
    const path = `${f.root}/etc/orbit/agent/secret`;
    const before = await Deno.stat(path);
    await Deno.writeTextFile(
      `${f.root}/proc/meminfo`,
      "MemAvailable: 104000 kB\n",
    );
    const result = await configureAgent({
      ...f.input,
      secret_hash: null,
      allow_secret_rotation: true,
      configuration: "[swamp_worker]\nnode_id=13\nuser='orbit'\n",
    }, f.execute);
    assertEquals(result, {
      configured: false,
      error_code: "swamp.node_worker_memory_low",
    });
    assertEquals(await Deno.readTextFile(path), "owned-existing-secret");
    assertEquals((await Deno.stat(path)).ino, before.ino);
  } finally {
    await Deno.remove(f.root, { recursive: true });
  }
});

Deno.test("worker auth-only repair refuses unsafe existing credential custody without rewriting it", async () => {
  const f = await fixture();
  try {
    await Deno.mkdir(`${f.root}/etc/orbit/swamp-worker`, { mode: 0o700 });
    await Deno.writeTextFile(
      `${f.root}/etc/orbit/swamp-worker/enrollment.token`,
      "preserved-enrollment",
      { mode: 0o644 },
    );
    const result = await configureAgent({
      ...f.input,
      configuration: "[swamp_worker]\nnode_id=13\nuser='orbit'\n",
    }, f.execute);
    assertEquals(result, {
      configured: false,
      error_code: "swamp.node_worker_not_ready",
    });
    assertEquals(
      await Deno.readTextFile(
        `${f.root}/etc/orbit/swamp-worker/enrollment.token`,
      ),
      "preserved-enrollment",
    );
  } finally {
    await Deno.remove(f.root, { recursive: true });
  }
});

Deno.test("explicit recovery rotates only the observer secret and emits only its hash", async () => {
  const f = await fixture();
  try {
    const result = await configureAgent({
      ...f.input,
      secret_hash: null,
      allow_secret_rotation: true,
    }, f.execute);
    assertEquals(result.configured, true);
    const secret = await Deno.readTextFile(`${f.root}/etc/orbit/agent/secret`);
    assertEquals(secret.length, 64);
    if (!result.configured) throw new Error("Expected configured result");
    assertEquals(result.secret_hash, await hash(secret));
    assertEquals(JSON.stringify(result).includes(secret), false);
    assertEquals(
      (await Deno.stat(`${f.root}/etc/orbit/agent/secret`)).mode! & 0o777,
      0o600,
    );
  } finally {
    await Deno.remove(f.root, { recursive: true });
  }
});

Deno.test("agent pin and symlink refusals prevent configuration writes", async () => {
  const f = await fixture();
  try {
    assertEquals(
      await configureAgent(
        { ...f.input, agent_sha256: "0".repeat(64) },
        f.execute,
      ),
      { configured: false, error_code: "agent.version_mismatch" },
    );
    await Deno.remove(`${f.root}/etc/orbit/agent/secret`);
    await Deno.symlink(
      `${f.root}/proc/meminfo`,
      `${f.root}/etc/orbit/agent/secret`,
    );
    assertEquals(
      await configureAgent({
        ...f.input,
        secret_hash: null,
        allow_secret_rotation: true,
      }, f.execute),
      { configured: false, error_code: "agent.configure_failed" },
    );
  } finally {
    await Deno.remove(f.root, { recursive: true });
  }
});

async function recoveryFixture() {
  const f = await fixture();
  const configuration =
    "gateway_url='https://gateway.orbit'\n[swamp_worker]\nnode_id=13\nuser='orbit'\n";
  await Deno.mkdir(`${f.root}/etc/orbit/swamp-worker`, { mode: 0o700 });
  const files = {
    "etc/orbit/agent/config.toml": configuration,
    "etc/orbit/agent/ca.pem": "owned-ca",
    "etc/systemd/system/orbit-agent.service": "owned-resolved-unit",
    "etc/orbit/swamp-worker/enrollment.token": "owned-enrollment-token",
    "etc/orbit/swamp-worker/server.token": "owned-server-token",
  };
  for (const [name, bytes] of Object.entries(files)) {
    await Deno.writeTextFile(`${f.root}/${name}`, bytes, {
      mode: name.endsWith(".token") ? 0o600 : 0o644,
    });
  }
  return { ...f, files, input: { ...f.input, configuration } };
}

Deno.test("auth-only recovery preserves all configuration and worker bytes and inodes", async () => {
  const f = await recoveryFixture();
  try {
    const before = await Promise.all(
      Object.keys(f.files).map((name) => Deno.stat(`${f.root}/${name}`)),
    );
    const result = await configureAgent({
      ...f.input,
      node_id: 13,
      auth_only: true,
      allow_secret_rotation: true,
      secret_hash: null,
      certificate: "never-publish",
      unit: "never-publish",
      checkouts: ["/nonexistent/checkout"],
    }, f.execute);
    assertEquals(result.configured, true);
    if (!result.configured) throw new Error("Expected recovered secret");
    const secret = await Deno.readTextFile(`${f.root}/etc/orbit/agent/secret`);
    assertEquals(result.secret_hash, await hash(secret));
    let i = 0;
    for (const [name, bytes] of Object.entries(f.files)) {
      assertEquals(await Deno.readTextFile(`${f.root}/${name}`), bytes);
      assertEquals((await Deno.stat(`${f.root}/${name}`)).ino, before[i++].ino);
    }
  } finally {
    await Deno.remove(f.root, { recursive: true });
  }
});

Deno.test("auth-only recovery refuses wrong config identity and unsafe existing unit before rotating", async () => {
  const f = await recoveryFixture();
  try {
    const before = await Deno.stat(`${f.root}/etc/orbit/agent/secret`);
    for (
      const bad of [
        { ...f.input, node_id: 209 },
        { ...f.input, node_id: 13, configuration: "[malformed" },
        {
          ...f.input,
          node_id: 13,
          configuration: "[swamp_worker]\nnode_id=13\nuser='foreign'\n",
        },
      ]
    ) {
      assertEquals(
        await configureAgent({
          ...bad,
          auth_only: true,
          allow_secret_rotation: true,
          secret_hash: null,
        }, f.execute),
        { configured: false, error_code: "node.agent_rekey_unavailable" },
      );
    }
    await Deno.chmod(`${f.root}/etc/systemd/system/orbit-agent.service`, 0o666);
    assertEquals(
      await configureAgent({
        ...f.input,
        node_id: 13,
        auth_only: true,
        allow_secret_rotation: true,
        secret_hash: null,
      }, f.execute),
      { configured: false, error_code: "node.agent_rekey_unavailable" },
    );
    assertEquals(
      await Deno.readTextFile(`${f.root}/etc/orbit/agent/secret`),
      "owned-existing-secret",
    );
    assertEquals(
      (await Deno.stat(`${f.root}/etc/orbit/agent/secret`)).ino,
      before.ino,
    );
  } finally {
    await Deno.remove(f.root, { recursive: true });
  }
});

Deno.test("native converge and auth repair serialize on the same OS update lock without a stale hash overwrite", async () => {
  const f = await recoveryFixture();
  const lockPath = `${f.root}/run/lock/orbit-self-update.lock`;
  const holder = new Deno.Command("/usr/bin/python3", {
    args: [
      "-c",
      "import fcntl,sys;f=open(sys.argv[1],'w');fcntl.flock(f,fcntl.LOCK_EX);print('held',flush=True);sys.stdin.read(1)",
      lockPath,
    ],
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
  }).spawn();
  const held = holder.stdout.getReader();
  await held.read();
  let entered = 0;
  let bothEntered!: () => void;
  const ready = new Promise<void>((resolve) => {
    bothEntered = resolve;
  });
  const execute: SystemTransport = async (command) => {
    const program = agentConfigurationProgram(f.root, Deno.uid()!).replace(
      "  until=time.monotonic()+300",
      "  print('enter',flush=True)\n  until=time.monotonic()+300",
    );
    const child = new Deno.Command("/usr/bin/python3", {
      args: ["-c", program],
      stdin: "piped",
      stdout: "piped",
      stderr: "null",
    }).spawn();
    const input = child.stdin.getWriter();
    await input.write(new TextEncoder().encode(command.stdin));
    await input.close();
    const output = child.stdout.getReader();
    const first = await output.read();
    assertEquals(new TextDecoder().decode(first.value), "enter\n");
    if (++entered === 2) bothEntered();
    let stdout = "";
    for (;;) {
      const next = await output.read();
      if (next.done) break;
      stdout += new TextDecoder().decode(next.value);
    }
    return { success: (await child.status).success, stdout };
  };
  try {
    const converge = configureAgent(f.input, execute);
    const repair = configureAgent({
      ...f.input,
      node_id: 13,
      auth_only: true,
      allow_secret_rotation: true,
      secret_hash: null,
    }, execute);
    await ready;
    assertEquals(
      await Deno.readTextFile(`${f.root}/etc/orbit/agent/secret`),
      "owned-existing-secret",
    );
    const control = holder.stdin.getWriter();
    await control.write(new Uint8Array([1]));
    await control.close();
    const [old, recovered] = await Promise.all([converge, repair]);
    assertEquals(recovered.configured, true);
    if (!recovered.configured) throw new Error("Expected recovery");
    assertEquals(
      old.configured || old.error_code === "agent.secret_mismatch",
      true,
    );
    assertEquals(
      recovered.secret_hash,
      await hash(await Deno.readTextFile(`${f.root}/etc/orbit/agent/secret`)),
    );
  } finally {
    await holder.status;
    held.releaseLock();
    await Deno.remove(f.root, { recursive: true });
  }
});

Deno.test("direct configuration accepts the fixed root-owned sticky update-lock directory", async () => {
  const f = await fixture();
  try {
    await Deno.chmod(`${f.root}/run/lock`, 0o1777);
    const result = await configureAgent(f.input, f.execute);
    assertEquals(result.configured, true);
    assertEquals(
      await Deno.readTextFile(`${f.root}/etc/orbit/agent/config.toml`),
      f.input.configuration,
    );
    assertEquals(
      (await Deno.stat(`${f.root}/run/lock/orbit-self-update.lock`)).mode! &
        0o777,
      0o600,
    );
    await Deno.chmod(`${f.root}/run/lock`, 0o777);
    assertEquals(
      await configureAgent(f.input, f.execute),
      { configured: false, error_code: "agent.configure_failed" },
    );
  } finally {
    await Deno.remove(f.root, { recursive: true });
  }
});

Deno.test("auth-only accepts only the fixed root-owned sticky update-lock directory", async () => {
  const f = await recoveryFixture();
  try {
    await Deno.chmod(`${f.root}/run/lock`, 0o1777);
    const recovered = await configureAgent({
      ...f.input,
      node_id: 13,
      auth_only: true,
      allow_secret_rotation: true,
      secret_hash: null,
    }, f.execute);
    assertEquals(recovered.configured, true);
    const protectedPath = `${f.root}/etc/orbit/agent/secret`;
    const secret = await Deno.readTextFile(protectedPath);
    const before = await Deno.stat(protectedPath);
    await Deno.chmod(`${f.root}/run/lock`, 0o777);
    assertEquals(
      await configureAgent({
        ...f.input,
        node_id: 13,
        auth_only: true,
        allow_secret_rotation: true,
        secret_hash: null,
      }, f.execute),
      { configured: false, error_code: "node.agent_rekey_unavailable" },
    );
    assertEquals(await Deno.readTextFile(protectedPath), secret);
    assertEquals((await Deno.stat(protectedPath)).ino, before.ino);
    await Deno.chmod(`${f.root}/run/lock`, 0o1777);
    await Deno.chmod(`${f.root}/run/lock/orbit-self-update.lock`, 0o666);
    assertEquals(
      await configureAgent({
        ...f.input,
        node_id: 13,
        auth_only: true,
        allow_secret_rotation: true,
        secret_hash: null,
      }, f.execute),
      { configured: false, error_code: "node.agent_rekey_unavailable" },
    );
    assertEquals(await Deno.readTextFile(protectedPath), secret);
    assertEquals((await Deno.stat(protectedPath)).ino, before.ino);
  } finally {
    await Deno.remove(f.root, { recursive: true });
  }
});
