import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.14";
import { renewProxyWorker, validProxyPair } from "./proxy.ts";
import { convergeWorker, type Native } from "./rotation.ts";
import { publishProgram } from "./files.ts";
import type { NodeCommand } from "./command.ts";
const now = Date.now();
const token = (name: string, age = 0) => ({
  name,
  state: "active",
  createdAt: new Date(now - age).toISOString(),
  expiresAt: new Date(now - age + 86400000).toISOString(),
});
Deno.test("stored proxy pairs refuse expired, revoked, due or superseded native generations", async () => {
  let age = 0, state = "active";
  const call: Native = (argv) =>
    Promise.resolve([{
      ...token(
        argv[0] === "worker" ? "orbit-node-1" : "orbit-node-1-access",
        age,
      ),
      state,
    }]);
  assertEquals(
    await validProxyPair(
      1,
      [{ mtime_ms: now + 1 }, { mtime_ms: now + 1 }],
      call,
      now,
    ),
    true,
  );
  assertEquals(
    await validProxyPair(
      1,
      [{ mtime_ms: now - 1 }, { mtime_ms: now + 1 }],
      call,
      now,
    ),
    false,
  );
  assertEquals(await validProxyPair(1, [{}, {}], call, now), false);
  age = 23 * 3600000;
  assertEquals(
    await validProxyPair(1, [{ mtime_ms: now }, { mtime_ms: now }], call, now),
    false,
  );
  age = 0;
  state = "revoked";
  assertEquals(
    await validProxyPair(1, [{ mtime_ms: now }, { mtime_ms: now }], call, now),
    false,
  );
});
Deno.test("proxy rotation never posts Gateway Node converge even when local worker selection is false", async () => {
  for (const id of [1, 18] as const) {
    const requests: string[] = [];
    const http = (_method: string, path: string) => {
      requests.push(_method + " " + path);
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            id,
            user: "orbit",
            status: "active",
            platform: "linux",
            swamp_worker_selected: false,
          },
        },
      });
    };
    const call: Native = () => {
      throw new Error("Native issuer is owned by the209 renewal adapter");
    };
    const result = await convergeWorker(
      { node_id: id },
      http,
      call,
      undefined,
      {
        selected: () => Promise.resolve([1, 18]),
        renew: (node) =>
          Promise.resolve({
            node_id: node,
            user: "orbit",
            proxy_refreshed: true,
          }),
      },
    );
    assertEquals(result, { node_id: id, user: "orbit", proxy_refreshed: true });
    assertEquals(requests, [`GET /api/v1/nodes/${id}`]);
    await assertRejects(
      () =>
        convergeWorker({ node_id: id }, http, call, undefined, {
          selected: () => Promise.resolve([]),
          renew: () => {
            throw new Error("unexpected renewal");
          },
        }),
      Error,
      "not selected",
    );
  }
});
Deno.test("due proxy credentials publish only on209 and restart only their exact proxy unit", async () => {
  const commands: { argv: readonly string[]; stdin?: Uint8Array }[] = [];
  const nativeCalls: string[][] = [];
  const root = "/etc/orbit/node-proxy", dir = root + "/credentials/1";
  const contents = new Map([
    [
      root + "/1.json",
      JSON.stringify({ node_id: 1, host: "10.44.0.1", user: "orbit" }),
    ],
    [dir + "/enrollment.token", "orbit-node-1." + "old".repeat(12)],
    [dir + "/server.token", "orbit-node-1-access." + "old".repeat(12)],
  ]);
  const mode = (path: string) =>
    path.includes("token") || path.endsWith("id_ed25519") ? 0o600 : 0o644;
  const paths = new Set([
    ...contents.keys(),
    root + "/id_ed25519",
    "/etc/systemd/system/orbit-node-proxy-worker-1.service",
  ]);
  const invoke: NodeCommand = (argv, stdin) => {
    commands.push({ argv, stdin });
    let output = "";
    if (argv[0] === "/usr/bin/python3" && argv[2] === publishProgram) {
      const value = JSON.parse(new TextDecoder().decode(stdin));
      contents.set(value.path, atob(value.bytes));
      output = JSON.stringify({ changed: true });
    } else if (argv[0] === "/usr/bin/python3" && argv.length === 4) {
      const path = argv[3];
      output = JSON.stringify(
        paths.has(path)
          ? {
            uid: 0,
            gid: 0,
            mode: mode(path),
            kind: "file",
            mtime_ms: now - 23 * 3600000,
          }
          : null,
      );
    } else if (argv[0] === "/usr/bin/cat") output = contents.get(argv[1]) ?? "";
    return Promise.resolve({
      success: true,
      code: 0,
      stdout: new TextEncoder().encode(output),
    });
  };
  const call: Native = (argv) => {
    nativeCalls.push(argv);
    if (argv.at(-1) === "list") {
      return Promise.resolve([
        token(
          argv[0] === "worker" ? "orbit-node-1" : "orbit-node-1-access",
          23 * 3600000,
        ),
      ]);
    }
    if (argv.includes("create")) {
      return Promise.resolve({
        token: "orbit-node-1." + "privateenrollment".repeat(2),
      });
    }
    if (argv.includes("reveal")) {
      return Promise.resolve({
        token: "orbit-node-1-access." + "privateserver".repeat(2),
      });
    }
    return Promise.resolve({});
  };
  const outcome = await renewProxyWorker(1, call, undefined, invoke);
  assertEquals(outcome, { node_id: 1, user: "orbit", proxy_refreshed: true });
  assertEquals(
    contents.get(dir + "/enrollment.token"),
    "orbit-node-1." + "privateenrollment".repeat(2),
  );
  assertEquals(
    contents.get(dir + "/server.token"),
    "orbit-node-1-access." + "privateserver".repeat(2),
  );
  assertEquals(
    commands.filter((c) => c.argv[0] === "/usr/bin/systemctl").map((c) =>
      c.argv
    ),
    [[
      "/usr/bin/systemctl",
      "restart",
      "--no-block",
      "orbit-node-proxy-worker-1.service",
    ]],
  );
  assertEquals(
    JSON.stringify(commands.map((c) => c.argv)).includes("privateenrollment"),
    false,
  );
  assertEquals(
    JSON.stringify(commands.map((c) => c.argv)).includes("privateserver"),
    false,
  );
  assertEquals(
    nativeCalls.some((v) => v.includes("create") && v.at(-1) === "24h"),
    true,
  );
  assertEquals(
    nativeCalls.some((v) => v.includes("rotate") && v.at(-1) === "24h"),
    true,
  );
});
