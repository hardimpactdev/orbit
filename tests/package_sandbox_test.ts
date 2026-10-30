import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { PackageSandboxes } from "../extensions/behaviors/_lib/package_sandbox.ts";
import { withServiceTools } from "../extensions/behaviors/_lib/maintenance/tool_policy.ts";
import { workspace } from "../extensions/behaviors/js-maintenance/_lib/workspace.ts";
import { command } from "../extensions/behaviors/_lib/maintenance/io.ts";

Deno.test("package execution fails closed without a sandbox and never invokes the supplied program", async () => {
  await assertRejects(
    () =>
      withServiceTools({
        home: "/tmp",
        npmrc: "/dev/null",
        bunfig: "/dev/null",
      }, () => command("/tmp", "npm", ["--version"])),
    Error,
    "package.isolation_unavailable",
  );
});
Deno.test("package sessions carry only admitted file bytes and reuse a private job while exporting locks", async () => {
  const root = await Deno.makeTempDir({ prefix: "dependency-tools-" });
  await Deno.writeTextFile(`${root}/package.json`, '{"name":"fixture"}');
  const calls: Record<string, unknown>[] = [];
  const session = new PackageSandboxes((input) => {
    calls.push(structuredClone(input));
    if (input.action === "open") {
      return Promise.resolve({ status: "opened", job: input.job });
    }
    if (input.action === "run") {
      return Promise.resolve({
        status: "executed",
        code: 0,
        stdout: "{}",
        stderr: "",
        files: {
          "package.json": '{"name":"fixture"}',
          "package-lock.json": '{"lockfileVersion":3}',
        },
      });
    }
    return Promise.resolve({
      status: input.action === "cancel" ? "cancelled" : "closed",
    });
  });
  try {
    await session.command(root, "npm", ["outdated", "--json"]);
    await session.command(root, "npm", ["audit", "--json"]);
    assertEquals(calls.map((c) => c.action), ["open", "run", "run"]);
    assertEquals(calls[0].job, calls[2].job);
    assertEquals(calls[1].tool, "npm");
    assertEquals(calls[1].cwd, ".");
    assertEquals(Object.keys(calls[1]).sort(), [
      "action",
      "args",
      "cwd",
      "files",
      "job",
      "tool",
    ]);
    assert(!JSON.stringify(calls).includes("SWAMP_HOME"));
    assertEquals(
      await Deno.readTextFile(`${root}/package-lock.json`),
      '{"lockfileVersion":3}',
    );
    await session.close();
    assertEquals(calls.slice(-2).map((c) => c.action), ["cancel", "close"]);
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
Deno.test("unadmitted outputs and symlinked inputs refuse instead of escaping the disposable tree", async () => {
  const root = await Deno.makeTempDir({ prefix: "dependency-tools-" });
  await Deno.writeTextFile(`${root}/package.json`, "{}");
  const session = new PackageSandboxes((input) =>
    Promise.resolve(
      input.action === "open" ? { status: "opened", job: input.job } : {
        status: "executed",
        code: 0,
        stdout: "",
        stderr: "",
        files: { "../package.json": "overwrite" },
      },
    )
  );
  try {
    await assertRejects(
      () => session.command(root, "npm", []),
      Error,
      "package.output_invalid",
    );
    await Deno.symlink("/etc/passwd", `${root}/composer.json`);
    const fresh = new PackageSandboxes(() => {
      throw new Error("must_not_invoke_agent");
    });
    await assertRejects(
      () => fresh.command(root, "npm", []),
      Error,
      "package.input_invalid",
    );
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
Deno.test("cleanup retains failed jobs for explicit retry and does not discard their identity", async () => {
  const root = await Deno.makeTempDir({ prefix: "dependency-tools-" });
  await Deno.writeTextFile(`${root}/package.json`, "{}");
  let fail = true;
  const calls: string[] = [];
  const session = new PackageSandboxes((input) => {
    calls.push(input.action as string);
    if (input.action === "open") {
      return Promise.resolve({ status: "opened", job: input.job });
    }
    if (input.action === "run") {
      return Promise.resolve({
        status: "executed",
        code: 0,
        stdout: "",
        stderr: "",
        files: {},
      });
    }
    if (input.action === "cancel" && fail) throw new Error("cancel_failed");
    return Promise.resolve({ status: "closed" });
  });
  try {
    await session.command(root, "npm", []);
    await assertRejects(() => session.close(), Error, "package.cleanup_failed");
    fail = false;
    await session.close();
    assertEquals(calls, ["open", "run", "cancel", "cancel", "close"]);
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});

Deno.test("an agent refusal never falls back to the worker account's package executable", async () => {
  const root = await Deno.makeTempDir({ prefix: "dependency-tools-" });
  const marker = `${root}/ran-as-worker`;
  await Deno.writeTextFile(`${root}/package.json`, "{}");
  await Deno.writeTextFile(
    `${root}/npm`,
    `#!/bin/sh\nprintf unsafe > ${JSON.stringify(marker)}\n`,
    { mode: 0o755 },
  );
  try {
    for (const refusedAction of ["open", "run"]) {
      const calls: string[] = [];
      const sandboxes = new PackageSandboxes((input) => {
        calls.push(input.action as string);
        if (input.action === refusedAction) {
          throw new Error("package.isolation_unavailable");
        }
        return Promise.resolve({ status: "opened", job: input.job });
      });
      await assertRejects(
        () =>
          withServiceTools(
            {
              home: root,
              npmrc: "/dev/null",
              bunfig: "/dev/null",
              sandboxes,
            },
            () =>
              command(root, "npm", ["--version"], undefined, { PATH: root }),
          ),
        Error,
        "package.isolation_unavailable",
      );
      assertEquals(
        calls,
        refusedAction === "open" ? ["open"] : ["open", "run"],
      );
      await assertRejects(() => Deno.stat(marker), Deno.errors.NotFound);
    }
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});

Deno.test("JS and Composer baseline audit trees stay inside isolated package sessions", async () => {
  for (const prefix of ["js-maintenance-base-", "composer-maintenance-base-"]) {
    const root = await Deno.makeTempDir({ prefix });
    await Deno.writeTextFile(`${root}/package.json`, "{}");
    const calls: Record<string, unknown>[] = [];
    const session = new PackageSandboxes((input) => {
      calls.push(input);
      return Promise.resolve(
        input.action === "open" ? { status: "opened", job: input.job } : {
          status: "executed",
          code: 0,
          stdout: "{}",
          stderr: "",
          files: {},
        },
      );
    });
    try {
      await session.command(
        root,
        prefix.startsWith("js-") ? "npm" : "composer",
        ["audit", "--json"],
      );
      assertEquals(calls.map((call) => call.action), ["open", "run"]);
      assertEquals(calls[1].cwd, ".");
    } finally {
      await Deno.remove(root, { recursive: true });
    }
  }
});

Deno.test("authoritative Vite+ scan uses its declared package manager without executing vp", async () => {
  const root = await Deno.makeTempDir({ prefix: "dependency-tools-" });
  try {
    await Deno.writeTextFile(
      `${root}/package.json`,
      JSON.stringify({
        name: "fixture",
        packageManager: "npm@11.19.1",
        devDependencies: { "vite-plus": "0.3.0" },
      }),
    );
    await Deno.writeTextFile(
      `${root}/package-lock.json`,
      JSON.stringify({ lockfileVersion: 3, packages: {} }),
    );
    const ordinary = await workspace({ path: ".", dir: root });
    assertEquals(ordinary.vitePlus, true);
    const isolated = await withServiceTools({
      home: root,
      npmrc: "/dev/null",
      bunfig: "/dev/null",
    }, () => workspace({ path: ".", dir: root }));
    assertEquals(isolated.manager, "npm");
    assertEquals(isolated.vitePlus, false);
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
