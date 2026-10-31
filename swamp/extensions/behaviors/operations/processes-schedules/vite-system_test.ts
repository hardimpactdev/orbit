import { assert, assertEquals } from "jsr:@std/assert@1";
import { selectVitePort, viteSystem } from "./vite-system.ts";
import type { SystemCommand } from "./system.ts";

Deno.test("native Vite selection skips actual held and excluded ports", async () => {
  const listener = Deno.listen({ hostname: "127.0.0.1", port: 0 });
  try {
    const held = listener.addr.port;
    if (held > 65533) return;
    const selected = await selectVitePort({
      preferred: held,
      excluded: [held + 1],
    });
    assert(typeof selected.port === "number" && selected.port > held + 1);
  } finally {
    listener.close();
  }
});

Deno.test("foreign Vite marker refuses before any write", async () => {
  const mutations: string[] = [];
  const run: SystemCommand = (_program, args) => {
    const program = args[2];
    if (program === "/usr/bin/stat") {
      return Promise.resolve({
        code: 0,
        stdout: args.at(-1)?.endsWith(".awake")
          ? "symbolic link:0:777:1\n"
          : "directory:0:755:2\n",
      });
    }
    mutations.push(program);
    return Promise.resolve({ code: 0, stdout: "" });
  };
  const result = await viteSystem(run)("vite.set-traffic", {
    operation: "awake",
    marker: "/dev/shm/orbit/hibernation/app-instance-7.awake",
  });
  assertEquals(result, { error_code: "vite.node_failed" });
  assert(!mutations.includes("/usr/bin/rm"));
  assertEquals(mutations.filter((p) => p === "/usr/bin/install").length, 0);
});

Deno.test("a Vite listener with any foreign cgroup remains unowned", async () => {
  const run: SystemCommand = (_program, args) => {
    const path = args.at(-1);
    const stdout = args[2] === "/usr/bin/systemctl"
      ? "/system.slice/orbit-process-7-vite.service\n"
      : args[2] === "/usr/bin/ss"
      ? 'LISTEN users:(("node",pid=11,fd=1),("node",pid=12,fd=2))\n'
      : path === "/proc/11/cgroup"
      ? "0::/system.slice/orbit-process-7-vite.service\n"
      : path === "/proc/12/cgroup"
      ? "0::/system.slice/foreign.service\n"
      : "X-Orbit-Process-ID=7\n";
    return Promise.resolve({ code: 0, stdout });
  };
  assertEquals(
    await viteSystem(run)("vite.check-listener", {
      unit: "orbit-process-7-vite.service",
      process_id: 7,
      port: 5173,
      probe: true,
    }),
    { owned: false, ready: false },
  );
});

Deno.test("public Vite plan cannot select a foreign environment path", async () => {
  const writes: string[] = [];
  const run: SystemCommand = (_program, args) => {
    if (args[2] === "/usr/bin/stat") {
      return Promise.resolve({
        code: 0,
        stdout: args.at(-1)?.endsWith(".awake")
          ? "regular empty file:0:644:1\n"
          : "directory:0:755:2\n",
      });
    }
    writes.push(args.join(" "));
    return Promise.resolve({ code: 0, stdout: "" });
  };
  assertEquals(
    await viteSystem(run)("vite.set-traffic", {
      operation: "environment",
      application: "/home/orbit/app",
      path: "/etc/passwd",
      instance_id: 7,
      marker: "/dev/shm/orbit/hibernation/app-instance-7.awake",
      content: "# Orbit Instance 7\nORBIT_DEV_SERVER_PORT=5173\n",
    }),
    { error_code: "vite.node_failed" },
  );
  assert(
    !writes.some((s) => s.includes("/usr/bin/mv") || s.includes("/usr/bin/rm")),
  );
});

Deno.test("shared Orbit directory preserves Schedule traverse-only mode during Vite preparation", async () => {
  const writes: string[] = [];
  const run: SystemCommand = (_program, args) => {
    if (args[2] === "/usr/bin/stat") {
      return Promise.resolve({
        code: 0,
        stdout: args.at(-1)?.endsWith(".awake")
          ? "regular empty file:0:644:1\n"
          : "directory:0:711:2\n",
      });
    }
    writes.push(args.join(" "));
    return Promise.resolve({ code: 0, stdout: "" });
  };
  assertEquals(
    await viteSystem(run)("vite.set-traffic", {
      operation: "suspend",
      marker: "/dev/shm/orbit/hibernation/app-instance-7.awake",
    }),
    {},
  );
  assert(!writes.some((s) => s.includes("/usr/bin/install")));
});
