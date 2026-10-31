import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.14";
import {
  installProxyWorkers,
  knownHost,
  prepareProxyKey,
  ProxyTarget,
  proxyUnit,
} from "./proxy-install.ts";
import type { NodeCommand } from "../operations/_lib/node-commands.ts";

const vpn = {
  node_id: 1 as const,
  host: "10.44.0.1",
  user: "orbit" as const,
  host_key:
    "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG",
  host_fingerprint: "SHA256:TP06RDL2oMgX8CDMhbQMVPkTgBz5oH7PDMb2QcpY8CE",
};
const exit = {
  node_id: 18 as const,
  host: "10.44.0.13",
  user: "orbit" as const,
  host_key:
    "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKTqizm/h9dgo7Zab5NUC1RlLprBkRoUPrKQkF/b+DjF",
  host_fingerprint: "SHA256:teBody6WhmcYgLEXTTI2m3TqyfNnRxgEx7tCbsg7mwA",
};
Deno.test("only the two admitted hosts and recorded Ed25519 fingerprints are trusted", async () => {
  assertEquals(await knownHost(vpn), vpn.host + " " + vpn.host_key + "\n");
  assertEquals(await knownHost(exit), exit.host + " " + exit.host_key + "\n");
  await assertRejects(() =>
    knownHost({ ...vpn, host_fingerprint: exit.host_fingerprint })
  );
  assertThrows(() => ProxyTarget.parse({ ...vpn, node_id: 18 }));
  assertThrows(() => ProxyTarget.parse({ ...vpn, host: "10.44.0.209" }));
  await assertRejects(() =>
    knownHost({ ...vpn, host_key: "ssh-ed25519 YWJjZA==" })
  );
});
Deno.test("two proxy units have isolated credentials and state with exact placement labels", () => {
  for (const id of [1, 18] as const) {
    const unit = proxyUnit(id);
    assertEquals(
      unit.includes(`--label node=${id} --label pool=light-node-proxy`),
      true,
    );
    assertEquals(unit.includes(`/var/lib/orbit-node-proxy-${id}`), true);
    assertEquals(
      unit.includes(`/etc/orbit/node-proxy/credentials/${id}/enrollment.token`),
      true,
    );
    assertEquals(unit.includes("StartLimitIntervalSec=0"), true);
    assertEquals(unit.includes("RestartSec=15"), true);
    assertEquals(unit.includes("orbit-agent"), false);
    assertEquals(unit.includes("NoNewPrivileges"), false);
    assertEquals(unit.includes("User=orbit"), true);
  }
});
Deno.test("insufficient209 memory refuses before key, file, credential or unit effects", async () => {
  const calls: readonly string[][] = [];
  const invoke: NodeCommand = (argv) => {
    (calls as string[][]).push([...argv]);
    const output = argv[0] === "/usr/bin/cat"
      ? "MemAvailable: 500000 kB\n"
      : JSON.stringify(runtimeFixture);
    return Promise.resolve({
      success: true,
      code: 0,
      stdout: new TextEncoder().encode(output),
    });
  };
  await assertRejects(() => prepareProxyKey(invoke));
  assertEquals(calls.length, 2);
  assertEquals(calls[0][0], "/usr/bin/python3");
  assertEquals(calls[1], ["/usr/bin/cat", "/proc/meminfo"]);
});
Deno.test("wrong credential target or incomplete proxy set refuses before effects", async () => {
  const invoke: NodeCommand = () => {
    throw new Error("unexpected effect");
  };
  const pair = {
    enrollment_token: "orbit-node-1." + "a".repeat(32),
    server_token: "orbit-node-1-access." + "b".repeat(32),
  };
  await assertRejects(() =>
    installProxyWorkers([vpn], { 1: pair, 18: pair }, invoke, runtimeFixture)
  );
  const error = await assertRejects(
    () =>
      installProxyWorkers(
        [vpn, exit],
        { 1: pair, 18: pair },
        invoke,
        runtimeFixture,
      ),
    Error,
  );
  assertEquals(error.message, "Proxy credential identity mismatch");
});

import { activate } from "./proxy.ts";
import { runtimeProgram } from "./proxy-runtime.ts";
import { runtimeFixture } from "./proxy-runtime.fixture.ts";
import { publishProgram } from "./files.ts";

Deno.test("derived public keys accept OpenSSH comments but refuse malformed wire data or multiple lines", async () => {
  let publicOutput = vpn.host_key + " orbit-light-node-proxy\n";
  const invoke: NodeCommand = (argv) => {
    let output = "";
    if (argv[0] === "/usr/bin/python3") {
      output = argv[2] === runtimeProgram
        ? JSON.stringify(runtimeFixture)
        : JSON.stringify({
          uid: 0,
          gid: 0,
          kind: argv[3].endsWith("id_ed25519") ? "file" : "directory",
          mode: argv[3].endsWith("id_ed25519") ? 0o600 : 0o755,
        });
    } else if (argv[0] === "/usr/bin/cat") {
      output = "MemAvailable: 4000000 kB\n";
    } else if (argv[0] === "/usr/bin/ssh-keygen" && argv.includes("-y")) {
      output = publicOutput;
    } else throw new Error("Unexpected key or unit mutation");
    return Promise.resolve({
      success: true,
      code: 0,
      stdout: new TextEncoder().encode(output),
    });
  };
  assertEquals(await prepareProxyKey(invoke, runtimeFixture), vpn.host_key);
  publicOutput = vpn.host_key + "\n";
  assertEquals(await prepareProxyKey(invoke, runtimeFixture), vpn.host_key);
  for (
    const malformed of [
      vpn.host_key + "\n" + exit.host_key,
      vpn.host_key + "\n\n",
      "ssh-ed25519 YWJjZA==",
      "ssh-rsa " + vpn.host_key.split(" ")[1],
      "diagnostic\n" + vpn.host_key,
    ]
  ) {
    publicOutput = malformed;
    await assertRejects(() => prepareProxyKey(invoke, runtimeFixture));
  }
});

Deno.test("proxy activation composes canonical issuer and keeps both credential pairs on stdin", async () => {
  const calls: { argv: readonly string[]; stdin?: Uint8Array }[] = [];
  const files = new Map<
    string,
    { uid: number; gid: number; mode: number; kind: string }
  >();
  const root = "/etc/orbit/node-proxy";
  for (const dir of ["/etc", "/etc/orbit", root]) {
    files.set(dir, { uid: 0, gid: 0, mode: 0o755, kind: "directory" });
  }
  files.set(root + "/id_ed25519", {
    uid: 0,
    gid: 0,
    mode: 0o600,
    kind: "file",
  });
  files.set("/usr/local/bin/swamp", {
    uid: 0,
    gid: 0,
    mode: 0o755,
    kind: "file",
  });
  const invoke: NodeCommand = (argv, stdin) => {
    calls.push({ argv, stdin });
    let value = "";
    if (argv[0] === "/usr/bin/python3") {
      if (argv[2] === runtimeProgram) {
        value = JSON.stringify(runtimeFixture);
      } else if (argv[2] === publishProgram) {
        const p = JSON.parse(new TextDecoder().decode(stdin));
        files.set(p.path, { uid: 0, gid: 0, mode: p.mode, kind: "file" });
        value = JSON.stringify({ changed: true });
      } else if (argv.length === 4) {
        value = JSON.stringify(files.get(argv[3]) ?? null);
      }
    } else if (argv[0] === "/usr/bin/cat") value = "MemAvailable: 4000000 kB\n";
    else if (argv[0] === "/usr/bin/ssh-keygen") value = vpn.host_key;
    else if (argv[0] === "/usr/bin/getent") {
      value = "orbit:x:1000:1000::/home/orbit:/bin/bash\n";
    } else if (argv[0] === "/usr/bin/install") {
      const path = argv.at(-1)!;
      files.set(path, {
        uid: Number(argv[argv.indexOf("-o") + 1]),
        gid: Number(argv[argv.indexOf("-g") + 1]),
        mode: parseInt(argv[argv.indexOf("-m") + 1], 8),
        kind: "directory",
      });
    }
    return Promise.resolve({
      success: true,
      code: 0,
      stdout: new TextEncoder().encode(value),
    });
  };
  const issued: number[] = [];
  const issue: Parameters<typeof activate>[2] = async (
    raw,
    existing,
    store,
  ) => {
    const n = (raw as { node_id: number }).node_id;
    issued.push(n);
    assertEquals(existing, null);
    await store({
      worker_credentials: {
        enrollment_token: `orbit-node-${n}.` + "privateenrollment".repeat(2),
        server_token: `orbit-node-${n}-access.` + "privateserver".repeat(2),
      },
    });
    return { issued: true, node_id: n };
  };
  assertEquals(await activate(runtimeFixture, invoke, issue), {
    configured: true,
    node_ids: [1, 18],
  });
  assertEquals(issued, [1, 18]);
  assertEquals(
    JSON.stringify(calls.map((c) => c.argv)).includes("privateenrollment"),
    false,
  );
  assertEquals(
    JSON.stringify(calls.map((c) => c.argv)).includes("privateserver"),
    false,
  );
  assertEquals(
    calls.some((c) =>
      c.argv[0] === "/usr/bin/ssh-keygen" && c.argv.includes("-y")
    ),
    true,
  );
  const publications = calls.filter((c) =>
    c.argv[0] === "/usr/bin/python3" && c.argv[2] === publishProgram
  )
    .map((c) => JSON.parse(new TextDecoder().decode(c.stdin)).path as string);
  for (const node of [1, 18]) {
    assertEquals(
      publications.indexOf(`${root}/${node}.json`) >
        publications.indexOf(
          `/etc/systemd/system/orbit-node-proxy-worker-${node}.service`,
        ),
      true,
    );
  }
  const units = calls.filter((c) => c.argv[0] === "/usr/bin/systemctl");
  assertEquals(
    units.filter((c) => c.argv[1] === "start").map((c) => c.argv.at(-1)),
    ["orbit-node-proxy-worker-1.service", "orbit-node-proxy-worker-18.service"],
  );
  assertEquals(
    units.every((c) => !c.argv.includes("orbit-node-worker.service")),
    true,
  );
});

Deno.test("runtime drift immediately before unit publication refuses without starting a proxy", async () => {
  let attestations = 0;
  const publications: string[] = [];
  const units: readonly string[][] = [];
  const invoke: NodeCommand = (argv, stdin) => {
    let output = "";
    if (argv[0] === "/usr/bin/python3") {
      if (argv[2] === runtimeProgram) {
        attestations++;
        output = JSON.stringify({
          ...runtimeFixture,
          pid: attestations === 1 ? runtimeFixture.pid : 999,
        });
      } else if (argv[2] === publishProgram) {
        publications.push(JSON.parse(new TextDecoder().decode(stdin)).path);
        output = JSON.stringify({ changed: true });
      } else if (argv.length === 4) {
        const path = argv[3];
        output = JSON.stringify(
          path.endsWith(".token") || path.includes("credentials") ||
            path.startsWith("/var/lib/")
            ? null
            : {
              uid: 0,
              gid: 0,
              mode: path.endsWith("id_ed25519") ? 0o600 : 0o755,
              kind: path.endsWith("id_ed25519") ? "file" : "directory",
            },
        );
      }
    } else if (argv[0] === "/usr/bin/cat") {
      output = "MemAvailable: 4000000 kB\n";
    } else if (argv[0] === "/usr/bin/ssh-keygen") output = vpn.host_key;
    else if (argv[0] === "/usr/bin/getent") {
      output = "orbit:x:1000:1000::/home/orbit:/bin/bash\n";
    } else if (argv[0] === "/usr/bin/systemctl") {
      (units as string[][]).push([...argv]);
    }
    return Promise.resolve({
      success: true,
      code: 0,
      stdout: new TextEncoder().encode(output),
    });
  };
  const pair = (node: number) => ({
    enrollment_token: `orbit-node-${node}.` + "a".repeat(32),
    server_token: `orbit-node-${node}-access.` + "b".repeat(32),
  });
  await assertRejects(
    () =>
      installProxyWorkers(
        [vpn, exit],
        { 1: pair(1), 18: pair(18) },
        invoke,
        runtimeFixture,
      ),
    Error,
    "runtime changed",
  );
  assertEquals(attestations, 2);
  assertEquals(publications.some((path) => path.endsWith(".service")), false);
  assertEquals(units, []);
});
