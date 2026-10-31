import {
  assertEquals,
  assertRejects,
  assertStringIncludes,
} from "jsr:@std/assert@1.0.19";
import type { SystemCommand, SystemTransport } from "../node/system_command.ts";
import { type PiCommand, piNative } from "./pi_native.ts";

const fixtureProgram = String.raw`
import json, os, pathlib, sys, types
value = json.load(sys.stdin)
ns = {'__name__': 'pi_fixture'}
exec(value['source'], ns)
ns['CACHE'] = pathlib.Path(value['root']) / 'cache/pi'
ns['pwd'].getpwnam = lambda user: types.SimpleNamespace(pw_dir=value['root'] + '/home', pw_uid=os.getuid() + value.get('uid_offset', 0), pw_gid=os.getgid())
try:
    print(json.dumps(ns['run'](value['request'])))
except Exception as error:
    code = str(error) if isinstance(error, ns['Refusal']) else 'pi.artifact_failed'
    if value['request']['name'] == 'pi.configure-runtime' and code.startswith('pi.artifact_'):
        code = 'pi.runtime_failed'
    print(json.dumps({'error_code': code}))
    sys.exit(1)
`;
const bytes = new TextEncoder().encode("pinned Pi fixture binary");
async function sha(value: Uint8Array) {
  return Array.from(
    new Uint8Array(
      await crypto.subtle.digest("SHA-256", new Uint8Array(value)),
    ),
  )
    .map((value) => value.toString(16).padStart(2, "0")).join("");
}
const pin = await sha(bytes);
function chunk(offset = 0, end = bytes.length) {
  return {
    sha256: pin,
    offset,
    total: bytes.length,
    bytes: btoa(String.fromCharCode(...bytes.slice(offset, end))),
  };
}
const settings = {
  user: "orbit",
  origin: "http://10.44.0.3:8317",
  models: ["provider/model"],
  model_key: "b".repeat(64),
  token: "c".repeat(64),
};
async function fixture() {
  const root = await Deno.makeTempDir({
    dir: Deno.env.get("HOME"),
    prefix: ".orbit-pi-native-test-",
  });
  await Deno.mkdir(root + "/home", { mode: 0o755 });
  const commands: SystemCommand[] = [];
  let uidOffset = 0;
  const transport: SystemTransport = async (input, signal) => {
    signal?.throwIfAborted();
    commands.push(input);
    assertEquals(input.command, "/usr/bin/sudo");
    assertEquals(input.args.slice(0, 4), [
      "-n",
      "--",
      "/usr/bin/python3",
      "-c",
    ]);
    assertEquals(input.timeoutMs, 120000);
    const child = new Deno.Command("/usr/bin/python3", {
      args: ["-c", fixtureProgram],
      stdin: "piped",
      stdout: "piped",
      stderr: "piped",
      signal: AbortSignal.timeout(input.timeoutMs),
    }).spawn();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify({
      source: input.args[4],
      root,
      request: JSON.parse(input.stdin!),
      uid_offset: uidOffset,
    })));
    await writer.close();
    const output = await child.output();
    assertEquals(new TextDecoder().decode(output.stderr), "");
    return {
      success: output.success,
      stdout: new TextDecoder().decode(output.stdout),
    };
  };
  return {
    root,
    commands,
    wrongOwner: () => uidOffset++,
    call: (name: PiCommand, input: Record<string, unknown>) =>
      piNative(name, input, transport),
    clean: () => Deno.remove(root, { recursive: true }),
  };
}

Deno.test("Pi native chunk replay, digest publication and install are idempotent", async () => {
  const f = await fixture();
  try {
    assertEquals(await f.call("pi.receive-artifact", chunk(0, 8)), {
      received_bytes: 8,
      verified: false,
    });
    const partial = f.root + "/cache/pi/" + pin + ".partial";
    await Deno.truncate(partial, 5);
    assertEquals(await f.call("pi.receive-artifact", chunk(0, 8)), {
      received_bytes: 8,
      verified: false,
    });
    assertEquals(await f.call("pi.receive-artifact", chunk(8)), {
      received_bytes: bytes.length,
      verified: true,
    });
    assertEquals(await f.call("pi.receive-artifact", chunk(0, 8)), {
      received_bytes: bytes.length,
      verified: true,
    });
    assertEquals(
      await f.call("pi.install-artifact", { user: "orbit", sha256: pin }),
      {
        changed: true,
      },
    );
    assertEquals(
      await f.call("pi.install-artifact", { user: "orbit", sha256: pin }),
      {
        changed: false,
      },
    );
    const binary = f.root + "/home/.local/bin/pi-server";
    assertEquals(await Deno.readFile(binary), bytes);
    assertEquals((await Deno.stat(binary)).mode! & 0o7777, 0o755);
    await Deno.remove(f.root + "/cache", { recursive: true });
    assertEquals(
      await f.call("pi.inspect-artifact", { user: "orbit", sha256: pin }),
      {
        current: true,
      },
    );
  } finally {
    await f.clean();
  }
});

Deno.test("Pi native rejects corrupt, conflicting and out-of-order chunks without changing installed bytes", async () => {
  const f = await fixture();
  try {
    await assertRejects(
      () => f.call("pi.receive-artifact", chunk(1)),
      Error,
      "pi.artifact_invalid",
    );
    await assertRejects(
      () => Deno.stat(f.root + "/cache"),
      Deno.errors.NotFound,
    );
    await f.call("pi.receive-artifact", chunk(0, 8));
    await assertRejects(
      () =>
        f.call("pi.receive-artifact", {
          ...chunk(0, 8),
          bytes: btoa("WRONG!!!"),
        }),
      Error,
      "pi.artifact_invalid",
    );
    assertEquals(
      await Deno.readFile(f.root + "/cache/pi/" + pin + ".partial"),
      bytes.slice(0, 8),
    );
    await f.call("pi.receive-artifact", chunk(8));
    await f.call("pi.install-artifact", { user: "orbit", sha256: pin });
    const other = "e".repeat(64);
    await assertRejects(
      () => f.call("pi.receive-artifact", { ...chunk(), sha256: other }),
      Error,
      "pi.artifact_invalid",
    );
    await assertRejects(
      () => Deno.stat(f.root + "/cache/pi/" + other + ".partial"),
      Deno.errors.NotFound,
    );
    assertEquals(
      await Deno.readFile(f.root + "/home/.local/bin/pi-server"),
      bytes,
    );
  } finally {
    await f.clean();
  }
});

Deno.test("Pi native bounds artifacts and refuses malformed values before creating cache", async () => {
  const f = await fixture();
  try {
    for (
      const input of [
        { ...chunk(), total: 268435457 },
        { ...chunk(), bytes: "!!!" },
        { ...chunk(), sha256: "../file" },
        { ...chunk(), offset: -1 },
        { ...chunk(), total: 0 },
        { ...chunk(), offset: 1.5 },
      ]
    ) {
      await assertRejects(
        () => f.call("pi.receive-artifact", input),
        Error,
        "pi.artifact_invalid",
      );
    }
    await assertRejects(
      () => Deno.stat(f.root + "/cache"),
      Deno.errors.NotFound,
    );
  } finally {
    await f.clean();
  }
});

Deno.test("Pi native refuses linked cache and runtime ancestors", async () => {
  const f = await fixture();
  try {
    await Deno.mkdir(f.root + "/outside");
    await Deno.symlink(f.root + "/outside", f.root + "/cache");
    await assertRejects(
      () => f.call("pi.receive-artifact", chunk()),
      Error,
      "pi.artifact_failed",
    );
    await Deno.remove(f.root + "/cache");
    await f.call("pi.receive-artifact", chunk());
    await Deno.symlink(f.root + "/outside", f.root + "/home/.local");
    await assertRejects(
      () => f.call("pi.install-artifact", { user: "orbit", sha256: pin }),
      Error,
      "pi.artifact_failed",
    );
    assertEquals(Array.from(Deno.readDirSync(f.root + "/outside")), []);
  } finally {
    await f.clean();
  }
});

Deno.test("Pi native installs private provider files atomically and preserves unchanged inodes", async () => {
  const f = await fixture();
  try {
    const configured = await f.call("pi.configure-runtime", settings);
    assertEquals(configured, {
      changed: true,
      binary: f.root + "/home/.local/bin/pi-server",
      token_file: f.root + "/home/.pi/agent/orbit-token",
    });
    const modelPath = f.root + "/home/.pi/agent/models.json";
    const before = await Deno.stat(modelPath);
    assertEquals(JSON.parse(await Deno.readTextFile(modelPath)), {
      providers: {
        "orbit-task-vm": {
          baseUrl: settings.origin + "/v1",
          api: "openai-responses",
          apiKey: settings.model_key,
          models: [{ id: "provider/model", reasoning: true }],
        },
      },
    });
    assertEquals(await f.call("pi.configure-runtime", settings), {
      ...configured,
      changed: false,
    });
    assertEquals((await Deno.stat(modelPath)).ino, before.ino);
    await f.call("pi.configure-runtime", {
      ...settings,
      token: "d".repeat(64),
    });
    assertEquals((await Deno.stat(modelPath)).ino, before.ino);
    assertEquals(
      await Deno.readTextFile(f.root + "/home/.pi/agent/orbit-token"),
      "d".repeat(64),
    );
    for (
      const [path, mode] of [[".pi", 0o700], [".pi/agent", 0o700], [
        ".pi/agent/models.json",
        0o600,
      ], [".pi/agent/orbit-token", 0o600]] as const
    ) {
      assertEquals(
        (await Deno.stat(f.root + "/home/" + path)).mode! & 0o7777,
        mode,
      );
    }
    assertEquals(
      JSON.stringify(configured).includes(settings.model_key),
      false,
    );
    assertEquals(JSON.stringify(configured).includes(settings.token), false);
    assertEquals(
      f.commands.every((c) =>
        !c.args.join(" ").includes(settings.model_key) &&
        !c.args.join(" ").includes(settings.token)
      ),
      true,
    );
  } finally {
    await f.clean();
  }
});

Deno.test("Pi native refuses secret-file symlinks before changing either private file", async () => {
  const f = await fixture();
  try {
    await f.call("pi.configure-runtime", settings);
    const modelPath = f.root + "/home/.pi/agent/models.json";
    const original = await Deno.readTextFile(modelPath);
    const token = f.root + "/home/.pi/agent/orbit-token";
    await Deno.remove(token);
    await Deno.writeTextFile(f.root + "/outside", "untouched");
    await Deno.symlink(f.root + "/outside", token);
    await assertRejects(
      () =>
        f.call("pi.configure-runtime", {
          ...settings,
          model_key: "d".repeat(64),
        }),
      Error,
      "pi.runtime_failed",
    );
    assertEquals(await Deno.readTextFile(modelPath), original);
    assertEquals(await Deno.readTextFile(f.root + "/outside"), "untouched");
  } finally {
    await f.clean();
  }
});

Deno.test("Pi native repairs private modes and refuses a different home owner", async () => {
  const f = await fixture();
  try {
    await f.call("pi.configure-runtime", settings);
    await Deno.chmod(f.root + "/home/.pi", 0o755);
    await Deno.chmod(f.root + "/home/.pi/agent/orbit-token", 0o644);
    assertEquals(
      (await f.call("pi.configure-runtime", settings)).changed,
      true,
    );
    assertEquals((await Deno.stat(f.root + "/home/.pi")).mode! & 0o7777, 0o700);
    f.wrongOwner();
    await assertRejects(
      () => f.call("pi.configure-runtime", settings),
      Error,
      "pi.runtime_failed",
    );
  } finally {
    await f.clean();
  }
});

Deno.test("Pi native rejects invalid private input without publishing or exposing it", async () => {
  const f = await fixture();
  try {
    for (
      const input of [
        { ...settings, origin: "https://private@example.test" },
        { ...settings, origin: "file:///tmp/secret" },
        { ...settings, origin: "http://example.test/v1" },
        { ...settings, token: "private-secret" },
        { ...settings, model_key: "secret\n".repeat(8) },
        { ...settings, models: [] },
        { ...settings, models: ["injected\nmodel"] },
      ]
    ) {
      await assertRejects(
        () => f.call("pi.configure-runtime", input),
        Error,
        "pi.runtime_invalid",
      );
    }
    await assertRejects(
      () => Deno.stat(f.root + "/home/.pi"),
      Deno.errors.NotFound,
    );
  } finally {
    await f.clean();
  }
});

Deno.test("Pi native redacts transport and malformed-output errors", async () => {
  const secret = "private-canary-credential";
  const failed: SystemTransport = () => {
    throw new Error(secret);
  };
  const malformed: SystemTransport = () =>
    Promise.resolve({ success: false, stdout: secret });
  await assertRejects(
    () => piNative("pi.configure-runtime", settings, failed),
    Error,
    "pi.runtime_failed",
  );
  await assertRejects(
    () => piNative("pi.configure-runtime", settings, malformed),
    Error,
    "pi.runtime_failed",
  );
  const calls: SystemCommand[] = [];
  const transport: SystemTransport = (call) => {
    calls.push(call);
    return Promise.resolve({ success: true, stdout: "{}" });
  };
  await piNative("pi.configure-runtime", settings, transport);
  assertStringIncludes(calls[0].stdin!, settings.model_key);
  assertEquals(calls[0].args.join(" ").includes(settings.model_key), false);
});
