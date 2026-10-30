import { localTool } from "./local.ts";
import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { z } from "npm:zod@4.4.3";
import { directTool } from "./direct.ts";
import { Intent, ToolIntentInput } from "./direct-contract.ts";
import { inventoryVersion, toolNode } from "./node.ts";

const identity = {
  operation_id: 17,
  node_id: 9,
  kind: "tool.install" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
class Fixture {
  value: z.infer<typeof Intent>;
  installed: string | null;
  calls: { name: string; input: Record<string, unknown> }[] = [];
  resources = new Map<string, unknown>();
  lost = false;
  candidate = "2.0.0";
  constructor(kind: string = "tool.install", manager: string = "apt") {
    this.installed = ["tool.install", "tool.manager_converge"].includes(kind)
      ? null
      : "1.0.0";
    this.value = Intent.parse({
      ...identity,
      kind,
      input: {
        node_id: 9,
        manager,
        package: manager === "composer" ? "vendor/package" : "jq",
        platform: manager === "brew-cask" ? "macos" : "linux",
        architecture: "x86_64",
        user: "orbit",
        agent_sha256: "a".repeat(64),
        tool_id: kind === "tool.install" ? null : 1,
      },
      started_at: "2026-10-30T12:00:00.000Z",
      cancelled: false,
      terminal: null,
    });
  }
  ctx = {
    writeResource: (_spec: string, name: string, value: unknown) => {
      this.resources.set(name, structuredClone(value));
      return Promise.resolve(value);
    },
    readResource: (name: string) =>
      Promise.resolve(this.resources.get(name) ?? null),
  };
  inventory() {
    return {
      packages: this.installed === null ? [] : [
        this.value.input.manager.startsWith("brew")
          ? {
            name: this.value.input.package,
            versions: [this.installed],
            unverifiable: false,
          }
          : { name: this.value.input.package, version: this.installed },
      ],
      scan_complete: true,
    };
  }
  invoke = (name: string, input: Record<string, unknown>) => {
    this.calls.push({ name, input });
    if (name.endsWith("-version")) return Promise.resolve({ version: "3.0.0" });
    if (name.endsWith("-inventory")) return Promise.resolve(this.inventory());
    if (name.endsWith("-candidate")) {
      return Promise.resolve({ version: this.candidate });
    }
    if (name.endsWith("-removal-plan")) {
      return Promise.resolve({
        packages: this.installed === null ? [] : [this.value.input.package],
      });
    }
    if (name.endsWith("-adoption")) {
      return Promise.resolve({
        installed_version: this.installed,
        block: null,
      });
    }
    if (name === "tool.inspect-composer-scope") {
      return Promise.resolve({ roots: [this.value.input.package] });
    }
    if (/-(install|update|remove)$/.test(name)) {
      this.installed = name.endsWith("-remove") ? null : this.candidate;
      if (this.lost) {
        this.lost = false;
        throw new Error("uncertain Node reply");
      }
    }
    return Promise.resolve({});
  };
  execute() {
    return toolNode(this.invoke).methods.apply.execute(
      { intent: this.value },
      this.ctx,
    );
  }
  mutations() {
    return this.calls.filter((c) => /-(install|update|remove)$/.test(c.name));
  }
}

Deno.test("direct policy preserves all five kinds across the closed manager registry", async () => {
  for (const manager of ["apt", "composer", "vp", "brew", "brew-cask"]) {
    for (
      const kind of [
        "tool.install",
        "tool.update",
        "tool.remove",
        "tool.adopt",
        "tool.manager_converge",
      ]
    ) {
      const f = new Fixture(kind, manager);
      const value = await f.execute();
      assertEquals(value.outcome.status, "succeeded", `${manager}/${kind}`);
      assertEquals(value.outcome.result.manager_version, "3.0.0");
      assertEquals(
        f.mutations().length,
        ["tool.adopt", "tool.manager_converge"].includes(kind) ? 0 : 1,
      );
    }
  }
});

Deno.test("silent adoption remains refused while native resume reconciles an uncertain install", async () => {
  const existing = new Fixture();
  existing.installed = "1.0.0";
  assertEquals(
    (await existing.execute()).outcome.error_code,
    "tool.package_already_installed",
  );
  assertEquals(existing.mutations().length, 0);
  const interrupted = new Fixture();
  interrupted.lost = true;
  await assertRejects(
    () => interrupted.execute(),
    Error,
    "uncertain Node reply",
  );
  const result = await interrupted.execute();
  assertEquals(result.outcome.status, "succeeded");
  assertEquals(result.outcome.result.installed_version, "2.0.0");
  assertEquals(interrupted.mutations().length, 1);
  assertEquals(interrupted.resources.size, 2);
});

Deno.test("native resume reconciles completed update and removal without repeating effects", async () => {
  for (const kind of ["tool.update", "tool.remove"]) {
    const f = new Fixture(kind);
    f.lost = true;
    await assertRejects(() => f.execute());
    assertEquals((await f.execute()).outcome.status, "succeeded");
    assertEquals(f.mutations().length, 1);
  }
});

Deno.test("candidate constraints and exact removal plans remain policy gates", async () => {
  const f = new Fixture();
  f.value.input.version_constraint = "^1.0";
  assertEquals(
    (await f.execute()).outcome.error_code,
    "tool.version_constraint_blocked",
  );
  assertEquals(f.mutations().length, 0);
  const remove = new Fixture("tool.remove");
  const invoke = remove.invoke;
  const result = await toolNode((name, input) =>
    name.endsWith("-removal-plan")
      ? Promise.resolve({ packages: ["jq", "curl"] })
      : invoke(name, input)
  ).methods.apply.execute({ intent: remove.value }, remove.ctx);
  assertEquals(result.outcome.error_code, "tool.removal_plan_unsafe");
  assertEquals(remove.mutations().length, 0);
});

Deno.test("malformed constraints refuse before manager materialization or package commands", async () => {
  const f = new Fixture();
  f.value.input.version_constraint = "not a range";
  f.value.input.materialize = { user: "orbit" };
  assertEquals(
    (await f.execute()).outcome.error_code,
    "tool.constraint_invalid",
  );
  assertEquals(f.calls, []);
});

Deno.test("ambiguous unreadable and incomplete inventories cannot prove package state", () => {
  const input = ToolIntentInput.parse(
    new Fixture("tool.remove", "vp").value.input,
  );
  for (
    const raw of [{ packages: [], scan_complete: false }, {
      packages: [{ name: "jq", version: null }],
      scan_complete: true,
    }, {
      packages: [{ name: "jq", version: "1" }, { name: "jq", version: "2" }],
      scan_complete: true,
    }]
  ) {
    let failed = false;
    try {
      inventoryVersion(input, raw);
    } catch {
      failed = true;
    }
    assertEquals(failed, true);
  }
});

Deno.test("manager materialization uses a fixed command and projects native refusal separately", async () => {
  const f = new Fixture("tool.manager_converge", "vp");
  f.value.input.materialize = { user: "orbit" };
  await f.execute();
  assertEquals(f.calls[0].name, "tool.materialize-vp");
  const failed = await toolNode(() =>
    Promise.resolve({ error_code: "tool.vp_scope_absent" })
  ).methods.apply.execute({ intent: f.value }, f.ctx);
  assertEquals(failed.outcome.error_code, "tool.manager_unavailable");
  assertEquals(failed.outcome.result.native_error_code, "tool.vp_scope_absent");
});

Deno.test("shared protocol binds intent before local action and uses no execution relay", async () => {
  const f = new Fixture();
  const calls: string[] = [];
  const http = (_method: string, path: string) => {
    calls.push(path);
    return Promise.resolve({
      status: 200,
      body: {
        data: {
          id: 17,
          node_id: 9,
          kind: "tool.install",
          input: f.value.input,
          status: "queued",
          result: null,
          cancel_requested: false,
        },
      },
    });
  };
  const ctx = {
    ...f.ctx,
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/dev/null" },
  };
  const direct = directTool(() => http, f.invoke);
  assertEquals(
    (await direct.methods.apply.execute(identity, ctx)).status,
    "succeeded",
  );
  assertEquals(calls.length, 3);
  assertEquals(
    calls.every((p) => p.startsWith("/api/v1/direct-operations/17")),
    true,
  );
  const count = f.calls.length;
  await assertRejects(() =>
    direct.methods.apply.execute({ ...identity, node_id: 10 }, ctx)
  );
  assertEquals(f.calls.length, count);
  const unbound = directTool(
    () => () =>
      Promise.resolve({
        status: 409,
        body: { error: { code: "operation.run_mismatch" } },
      }),
    f.invoke,
  );
  await assertRejects(() => unbound.methods.apply.execute(identity, ctx));
  assertEquals(f.calls.length, count);
});

Deno.test("production adapter checks cancellation before a local sudo launch", async () => {
  await assertRejects(
    () =>
      localTool(
        "tool.apt-install",
        {},
        new Fixture().value.input,
        AbortSignal.abort(new Error("cancelled before launch")),
      ),
    Error,
    "cancelled before launch",
  );
});

Deno.test("cancelled and terminal intents perform no account or package action", async () => {
  for (const status of ["queued", "succeeded"]) {
    const f = new Fixture();
    const http = () =>
      Promise.resolve({
        status: 200,
        body: {
          data: {
            id: 17,
            node_id: 9,
            kind: "tool.install",
            input: f.value.input,
            status,
            result: null,
            cancel_requested: true,
          },
        },
      });
    const result = await directTool(() => http, f.invoke).methods.apply.execute(
      identity,
      {
        ...f.ctx,
        globalArgs: {
          gatewayUrl: "https://gateway.orbit",
          caFile: "/dev/null",
        },
      },
    );
    assertEquals(
      result.status,
      status === "queued" ? "cancelled" : "succeeded",
    );
    assertEquals(f.calls.length, 0);
  }
});
