import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { commandLaunch, type NodeCommand } from "../_lib/node-commands.ts";
import { proxyAddress } from "../_lib/node-proxy-config.ts";
import {
  firewallInspection,
  inspectFirewall,
  lockedFirewallLocal,
  LockedInspectionProgram,
} from "./inspection.ts";

const shape = {
  comment: "orbit:rule-7",
  action: "allow",
  direction: "in",
  source: "192.0.2.1",
  destination: "any",
  port: "443",
  protocol: "tcp",
  in_interface: null,
  out_interface: null,
  family: "v4",
} as const;
const args = {
  operation_id: 17,
  kind: "firewall.inspect" as const,
  node_id: 9,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const ctx = {
  globalArgs: {
    gatewayUrl: "https://gateway.test",
    caFile: "/fixture/root.crt",
  },
};
function fixture(raw: unknown = { backend: "active", live: [shape] }) {
  const operation = {
    id: 17,
    kind: "firewall.inspect",
    node_id: 9,
    status: "queued",
    result: null,
    cancel_requested: false,
    input: { node_id: 9 },
  };
  const posts: Record<string, unknown>[] = [];
  let reads = 0;
  const model = firewallInspection(() => (method, path, body) => {
    assert(path.startsWith("/api/v1/direct-operations/17"));
    if (method === "POST") posts.push((body ?? {}) as Record<string, unknown>);
    return Promise.resolve({
      status: 200,
      body: { data: method === "GET" ? operation : {} },
    });
  }, (argv) => {
    reads++;
    assertEquals(JSON.parse(argv[6]), {
      name: "firewall.inspect",
      input: { expected: [], exclusive_families: [], stored: false },
    });
    return Promise.resolve(JSON.stringify(raw));
  });
  return { model, operation, posts, reads: () => reads };
}
Deno.test("inspection projects the complete IPv4 snapshot without parser metadata", async () => {
  const f = fixture();
  assertEquals(await f.model.methods.inspect.execute(args, ctx), {
    status: "succeeded",
  });
  assertEquals(f.reads(), 1);
  assertEquals(f.posts.map((p) => p.status), ["running", "succeeded"]);
  const result = f.posts[1].result as {
    backend: string;
    live: unknown[];
    observed_at: string;
  };
  assertEquals(result.backend, "active");
  assertEquals(result.live, [shape]);
  assert(Number.isFinite(Date.parse(result.observed_at)));
  assertEquals(Object.keys(result).sort(), ["backend", "live", "observed_at"]);
});
Deno.test("foreign intent or root identity refuses before any local read", async () => {
  for (const field of ["node_id", "kind", "input"]) {
    const f = fixture();
    if (field === "node_id") f.operation.node_id = 3;
    if (field === "kind") f.operation.kind = "firewall.allow";
    if (field === "input") f.operation.input.node_id = 3;
    await assertRejects(() => f.model.methods.inspect.execute(args, ctx));
    assertEquals(f.reads(), 0);
  }
});
Deno.test("recorded cancel performs no local read and projects cancellation", async () => {
  const f = fixture();
  f.operation.cancel_requested = true;
  assertEquals(await f.model.methods.inspect.execute(args, ctx), {
    status: "cancelled",
  });
  assertEquals(f.reads(), 0);
  assertEquals(f.posts[1].status, "cancelled");
});
Deno.test("oversized row count fails explicitly without a partial snapshot", async () => {
  const f = fixture({
    backend: "active",
    live: Array.from({ length: 513 }, () => shape),
  });
  await assertRejects(
    () => f.model.methods.inspect.execute(args, ctx),
    Error,
    "firewall.inspection_too_large",
  );
  assertEquals(f.posts[1].result, {
    error_code: "firewall.inspection_too_large",
  });
  assertEquals(f.posts[1].status, "failed");
});
Deno.test("UTF-8 result bytes have a strict 256KiB bound", async () => {
  const result = await inspectFirewall(() =>
    Promise.resolve(
      JSON.stringify({
        backend: "active",
        live: [{ ...shape, comment: "é".repeat(131072) }],
      }),
    )
  );
  assertEquals(result, { error_code: "firewall.inspection_too_large" });
});
Deno.test("malformed observations fail rather than dropping unrepresentable rows", async () => {
  assertEquals(
    await inspectFirewall(() =>
      Promise.resolve(
        JSON.stringify({
          backend: "active",
          live: [{ ...shape, family: "v6" }],
        }),
      )
    ),
    { error_code: "firewall.inspection_unavailable" },
  );
});
Deno.test("fixed runner refuses commands other than the canonical runtime read", async () => {
  await assertRejects(() => lockedFirewallLocal(["/bin/sh", "-c", "id"]));
});

async function localFixture(text: string, root: string, unsafe = false) {
  const lock = root + "/firewall.lock";
  const marker = root + "/ufw-read";
  const hook = `
def observed_run(argv):
 import fcntl,os
 fd=os.open(${JSON.stringify(lock)},os.O_RDWR)
 try:
  try:
   fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
  except BlockingIOError:pass
  else:raise RuntimeError('snapshot was not locked')
 finally:os.close(fd)
 with open(${JSON.stringify(marker)},'w') as stream:stream.write('read')
 return 0,${JSON.stringify(text)}
runtime_scope['run']=observed_run
`;
  const program = LockedInspectionProgram.replaceAll(
    "/run/lock/orbit-firewall.lock",
    lock,
  ).replace("LOCK_UID = 0", "LOCK_UID = " + Deno.uid()).replace(
    "try:\n payload=json.loads",
    hook + "\ntry:\n payload=json.loads",
  );
  if (unsafe) await Deno.symlink(root + "/outside", lock);
  return {
    marker,
    run: async (argv: string[]) => {
      const child = await new Deno.Command("/usr/bin/python3", {
        args: ["-I", "-c", program, argv[6]],
        stdin: "null",
        stdout: "piped",
        stderr: "piped",
      }).output();
      assertEquals(child.success, true, new TextDecoder().decode(child.stderr));
      return new TextDecoder().decode(child.stdout);
    },
  };
}
Deno.test("canonical parser observes IPv4 rows while the canonical writer lock is held", async () => {
  const root = await Deno.makeTempDir();
  try {
    const local = await localFixture(
      "Status: active\n[1] 443/tcp ALLOW IN 192.0.2.1 # orbit:rule-7\n[2] 443/tcp (v6) ALLOW IN Anywhere (v6) # orbit:rule-7",
      root,
    );
    const result = await inspectFirewall(local.run);
    assert("live" in result);
    assertEquals(result.live, [shape]);
    assertEquals(await Deno.readTextFile(local.marker), "read");
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
Deno.test("unsafe shared lock refuses before reading UFW", async () => {
  const root = await Deno.makeTempDir();
  try {
    const local = await localFixture("Status: active", root, true);
    assertEquals(await inspectFirewall(local.run), {
      error_code: "firewall.inspection_unavailable",
    });
    await assertRejects(() => Deno.stat(local.marker), Deno.errors.NotFound);
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
Deno.test("native locked reader refuses an oversized real parsed inventory", async () => {
  const root = await Deno.makeTempDir();
  try {
    const text = "Status: active\n" +
      Array.from(
        { length: 513 },
        (_, index) =>
          `[${index + 1}] 443/tcp ALLOW IN 192.0.2.1 # orbit:rule-${index}`,
      ).join("\n");
    const local = await localFixture(text, root);
    assertEquals(await inspectFirewall(local.run), {
      error_code: "firewall.inspection_too_large",
    });
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});

Deno.test("inactive and absent firewalls are complete empty observations", async () => {
  for (const backend of ["inactive", "absent"]) {
    const result = await inspectFirewall(() =>
      Promise.resolve(JSON.stringify({ backend, live: [] }))
    );
    assert("backend" in result);
    assertEquals(result.backend, backend);
    assertEquals(result.live, []);
  }
});

Deno.test("inspection delegates the fixed locked program to each proxy Node through NodeCommand", async () => {
  for (const node of [1, 18] as const) {
    const controller = new AbortController();
    let calls = 0;
    const command: NodeCommand = (argv, stdin, asUser, options) => {
      calls++;
      assertEquals(argv.slice(0, 4), [
        "/usr/bin/python3",
        "-I",
        "-c",
        LockedInspectionProgram,
      ]);
      assertEquals(JSON.parse(argv[4]), {
        name: "firewall.inspect",
        input: { expected: [], exclusive_families: [], stored: false },
      });
      assertEquals(stdin, undefined);
      assertEquals(asUser, "root");
      assertEquals(options, {
        signal: controller.signal,
        deadlineMs: 30000,
        maxOutputBytes: 256 * 1024,
      });
      const launch = commandLaunch(argv, asUser, {
        node_id: node,
        host: proxyAddress(node),
        user: "orbit",
      }, options?.deadlineMs);
      assertEquals(launch.command, "/usr/bin/sudo");
      assertEquals(launch.args.includes("orbit@" + proxyAddress(node)), true);
      return Promise.resolve({
        success: true,
        code: 0,
        stdout: new TextEncoder().encode(
          JSON.stringify({ backend: "active", live: [shape] }),
        ),
      });
    };
    const result = await inspectFirewall(
      (argv, signal) => lockedFirewallLocal(argv, signal, command),
      controller.signal,
    );
    assert("live" in result);
    assertEquals(result.live, [shape]);
    assertEquals(calls, 1);
  }
});
Deno.test("inspection refuses a full-size command reply explicitly without decoding or truncating it", async () => {
  const command: NodeCommand = () =>
    Promise.resolve({
      success: true,
      code: 0,
      stdout: new Uint8Array(256 * 1024),
    });
  assertEquals(
    await inspectFirewall((argv, signal) =>
      lockedFirewallLocal(argv, signal, command)
    ),
    { error_code: "firewall.inspection_too_large" },
  );
});
Deno.test("nonzero Node command output cannot become a successful observation", async () => {
  const command: NodeCommand = () =>
    Promise.resolve({
      success: false,
      code: 37,
      stdout: new TextEncoder().encode(
        JSON.stringify({ backend: "active", live: [shape] }),
      ),
    });
  assertEquals(
    await inspectFirewall((argv, signal) =>
      lockedFirewallLocal(argv, signal, command)
    ),
    { error_code: "firewall.inspection_unavailable" },
  );
});
Deno.test("inspection cancels before calling NodeCommand when the signal is already aborted", async () => {
  let calls = 0;
  const command: NodeCommand = () => {
    calls++;
    return Promise.resolve({
      success: true,
      code: 0,
      stdout: new Uint8Array(),
    });
  };
  await assertRejects(
    () =>
      inspectFirewall(
        (argv, signal) => lockedFirewallLocal(argv, signal, command),
        AbortSignal.abort(),
      ),
    Error,
    "cancelled",
  );
  assertEquals(calls, 0);
});
