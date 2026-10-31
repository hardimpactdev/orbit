import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.14";
import type { NodeCommand } from "./command.ts";
import {
  availableMemory,
  configureNodeWorker,
  Swamp,
  workerUnit,
} from "./install.ts";
import { type Metadata, publishProgram, text, utf8 } from "./files.ts";

function fixture(
  options: {
    memory?: string;
    wrongDownload?: boolean;
    nonemptyCache?: boolean;
    masked?: boolean;
  } = {},
) {
  const calls: { command: readonly string[]; stdin?: Uint8Array }[] = [];
  const files = new Map<string, Metadata>();
  const contents = new Map<string, string>();
  const file = (path: string, mode: number, kind = "file", uid = 0, gid = 0) =>
    files.set(path, { uid, gid, mode, kind });
  for (
    const path of ["/usr", "/usr/local", "/usr/local/bin", "/etc/orbit/agent"]
  ) file(path, 0o755, "directory");
  file("/usr/local/bin/swamp", 0o755);
  file("/etc/orbit/agent/ca.pem", 0o644);
  file("/etc/orbit/swamp-worker", 0o700, "directory");
  file("/etc/orbit/swamp-worker/enrollment.token", 0o600);
  file("/etc/orbit/swamp-worker/server.token", 0o600);
  file(
    "/var/lib/orbit-node-worker",
    options.nonemptyCache ? 0o755 : 0o700,
    "directory",
    options.nonemptyCache ? 0 : 1000,
    options.nonemptyCache ? 0 : 1000,
  );
  const invoke: NodeCommand = (command, stdin) => {
    calls.push({ command, stdin });
    let output = "";
    let code = 0;
    if (command[0] === "/usr/bin/cat") {
      output = command[1] === "/proc/meminfo"
        ? (options.memory ?? "MemAvailable: 2000000 kB\n")
        : "PUBLIC CA";
    } else if (command[0] === "/usr/bin/getent") {
      output = "orbit:x:1000:1000::/home/orbit:/bin/bash\n";
    } else if (command[0] === "/usr/bin/uname") output = "x86_64\n";
    else if (command[0] === "/usr/bin/sha256sum") {
      output = `${options.wrongDownload ? "0".repeat(64) : Swamp.sha256}  ${
        command[1]
      }\n`;
    } else if (command[0] === "/usr/bin/mktemp") {
      output = "/usr/local/bin/.orbit-swamp-ABCDEFGH\n";
    } else if (
      command[0] === "/usr/bin/rmdir" && options.nonemptyCache &&
      command.at(-1) === "/var/lib/orbit-node-worker"
    ) code = 1;
    else if (command[0] === "/usr/bin/systemctl" && command[1] === "show") {
      output = options.masked ? "LoadState=masked\n" : "LoadState=loaded\n";
    } else if (command[0] === "/usr/bin/python3") {
      if (command[2] === publishProgram) {
        const p = JSON.parse(text(stdin!));
        const content = new TextDecoder().decode(
          Uint8Array.from(atob(p.bytes), (c) => c.charCodeAt(0)),
        );
        output = JSON.stringify({ changed: contents.get(p.path) !== content });
        contents.set(p.path, content);
        file(p.path, p.mode);
      } else output = JSON.stringify(files.get(command.at(-1)!) ?? null);
    }
    return Promise.resolve({ success: code === 0, code, stdout: utf8(output) });
  };
  return { invoke, calls, files, contents };
}

Deno.test("memory floor is exact and malformed, duplicate and overflowing fields fail closed", () => {
  const minimum = Math.ceil((Swamp.bytes + Swamp.headroom) / 1024);
  assertEquals(
    availableMemory(`MemAvailable: ${minimum} kB\n`),
    minimum * 1024,
  );
  for (
    const value of [
      "",
      `MemAvailable: ${minimum - 1} kB`,
      "MemAvailable: 9000000 MB",
      "MemAvailable: 9000000 kB\nMemAvailable: 9000000 kB",
      "MemAvailable: 999999999999999999999 kB",
      "MemAvailable: -1 kB",
      "MemAvailable: 3.5 kB",
    ]
  ) assertThrows(() => availableMemory(value));
});

Deno.test("low memory refuses before filesystem, credentials, service or download changes", async () => {
  const f = fixture({ memory: "MemAvailable: 100000 kB\n" });
  await assertRejects(
    () => configureNodeWorker({ node_id: 209, user: "orbit" }, null, f.invoke),
    Error,
    "swamp.node_worker_memory_low",
  );
  assertEquals(f.calls.map((c) => c.command), [[
    "/usr/bin/cat",
    "/proc/meminfo",
  ]]);
});

Deno.test("proxy Nodes never install a local Swamp binary and an operator mask remains intact", async () => {
  for (const node_id of [1, 18]) {
    const f = fixture();
    await assertRejects(() =>
      configureNodeWorker({ node_id, user: "orbit" }, null, f.invoke)
    );
    assertEquals(f.calls.length, 0);
  }
  const f = fixture({ masked: true });
  assertEquals(await configureNodeWorker(null, null, f.invoke), {
    configured: false,
    changed: false,
  });
  assertEquals(f.calls.length, 1);
});

Deno.test("null credentials preserve both existing files while the native installer owns service configuration", async () => {
  const f = fixture();
  assertEquals(
    await configureNodeWorker({ node_id: 209, user: "orbit" }, null, f.invoke),
    { configured: true, changed: true },
  );
  assertEquals(
    f.contents.has("/etc/orbit/swamp-worker/enrollment.token"),
    false,
  );
  assertEquals(f.contents.has("/etc/orbit/swamp-worker/server.token"), false);
  assertEquals(f.calls.some((c) => c.command.includes("orbit-agent")), false);
  assertEquals(f.calls.at(-1)?.command, [
    "/usr/bin/systemctl",
    "restart",
    "--no-block",
    "orbit-node-worker.service",
  ]);
});

Deno.test("credential ownership refuses before effects and private bytes occur only in stdin", async () => {
  const f = fixture();
  await assertRejects(() =>
    configureNodeWorker({ node_id: 209, user: "orbit" }, {
      enrollment_token: "orbit-node-9.privateWrongCredential",
      server_token: "orbit-node-209-access.privateServerCredential",
    }, f.invoke)
  );
  assertEquals(f.calls.length, 0);
  const credentials = {
    enrollment_token: "orbit-node-209.privateEnrollmentNeedle",
    server_token: "orbit-node-209-access.privateServerNeedle",
  };
  await configureNodeWorker(
    { node_id: 209, user: "orbit" },
    credentials,
    f.invoke,
  );
  assertEquals(
    f.contents.get("/etc/orbit/swamp-worker/enrollment.token"),
    credentials.enrollment_token,
  );
  assertEquals(
    f.contents.get("/etc/orbit/swamp-worker/server.token"),
    credentials.server_token,
  );
  assertEquals(
    JSON.stringify(f.calls.map((c) => c.command)).includes(
      "privateEnrollmentNeedle",
    ),
    false,
  );
  assertEquals(
    f.files.get("/etc/orbit/swamp-worker/enrollment.token")?.mode,
    0o600,
  );
});

Deno.test("wrong staged checksum removes staging and never publishes the downloaded binary", async () => {
  const f = fixture({ wrongDownload: true });
  await assertRejects(
    () => configureNodeWorker({ node_id: 209, user: "orbit" }, null, f.invoke),
    Error,
    "swamp.node_worker_invalid",
  );
  assertEquals(f.calls.some((c) => c.command[0] === "/usr/bin/mv"), false);
  assertEquals(f.calls.slice(-2).map((c) => c.command), [[
    "/usr/bin/rm",
    "-f",
    "--",
    "/usr/local/bin/.orbit-swamp-ABCDEFGH/swamp",
  ], ["/usr/bin/rmdir", "--", "/usr/local/bin/.orbit-swamp-ABCDEFGH"]]);
  assertEquals(f.contents.size, 0);
});

Deno.test("the retained empty-cache migration refuses a nonempty directory without recursive removal", async () => {
  const f = fixture({ nonemptyCache: true });
  await assertRejects(() =>
    configureNodeWorker({ node_id: 209, user: "orbit" }, null, f.invoke)
  );
  assertEquals(f.calls.at(-1)?.command, [
    "/usr/bin/rmdir",
    "--",
    "/var/lib/orbit-node-worker",
  ]);
  assertEquals(f.calls.some((c) => c.command.includes("-rf")), false);
});

Deno.test("rendered worker policy retains retry, credential custody, sudo account and exact placement", () => {
  const unit = workerUnit({ node_id: 209, user: "orbit" });
  for (
    const value of [
      "StartLimitIntervalSec=0",
      "Restart=on-failure",
      "RestartSec=15",
      "User=orbit",
      "--label node=209",
      "LoadCredential=enrollment:",
      "ExecStartPre=+/usr/local/libexec/orbit-node-worker-check",
    ]
  ) assertEquals(unit.includes(value), true);
  assertEquals(unit.includes("orbit-agent"), false);
});
