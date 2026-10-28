import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { type Context, Operation } from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { tools } from "./mod.ts";
import { allows, Input, normalizeVersion } from "./contract.ts";

class Fixture {
  operation = Operation.parse({
    id: 11,
    kind: "tool.install",
    node_id: 2,
    status: "running",
    cancel_requested: false,
    input: {
      node_id: 2,
      manager: "vp",
      package: "is-number",
      platform: "linux",
      architecture: "x86_64",
      version_constraint: null,
      constraint_intervals: null,
      tool_id: null,
      intent_only: false,
    },
    step_results: {},
  });
  calls: { path: string; body: Record<string, unknown> }[] = [];
  resources = new Map<string, Record<string, unknown>>();
  receipts = new Map<string, Record<string, unknown>>();
  installed: string | null = null;
  candidate = "7.0.0";
  block: string | null = null;
  plan = ["is-number"];
  mutations = 0;
  loseRun = false;
  loseCompletion = false;
  leaseLost = false;
  cancelAtRun = false;
  refuse: string | null = null;
  http: Http = async (_method, path, raw) => {
    const body = raw as Record<string, unknown>;
    this.calls.push({ path, body });
    await Promise.resolve();
    if (this.leaseLost) {
      return { status: 409, body: { error: { code: "operation.lease_lost" } } };
    }
    let data: unknown = this.operation;
    if (path.endsWith("/run")) {
      const key = String(body.key);
      if (!this.receipts.has(key)) {
        const name = String(body.name);
        let receipt: Record<string, unknown> = {};
        if (this.cancelAtRun) receipt = { error_code: "operation.cancelled" };
        else if (this.refuse) receipt = { error_code: this.refuse };
        else if ((name.endsWith("-inventory") || name.endsWith("-adoption"))) {
          receipt = { installed_version: this.installed, block: this.block };
        } else if (name.endsWith("-version")) receipt = { version: "1.0.0" };
        else if (name.endsWith("-candidate")) {
          receipt = { version: this.candidate };
        } else if (name.endsWith("-removal-plan")) {
          receipt = { packages: this.plan };
        } else if (key === "package-mutate") {
          this.mutations++;
          this.installed = this.operation.kind === "tool.remove"
            ? null
            : this.candidate;
        }
        this.receipts.set(key, receipt);
      }
      data = this.receipts.get(key);
      if (this.loseRun && key === "package-mutate") {
        this.loseRun = false;
        throw new Error("Lost Node reply");
      }
    }
    if (path.endsWith("/step") && body.status === "succeeded") {
      this.operation.step_results[String(body.name)] = body.result;
    }
    if (path.endsWith("/complete") && this.loseCompletion) {
      this.loseCompletion = false;
      throw new Error("Lost completion reply");
    }
    return { status: 200, body: { data } };
  };
  context(): Context {
    const { context } = createModelTestContext({
      globalArgs: {
        gatewayUrl: "https://gateway.example.test",
        caFile: "/unused",
      },
    });
    const write = context.writeResource.bind(context);
    return Object.assign(context, {
      globalArgs: {
        gatewayUrl: "https://gateway.example.test",
        caFile: "/unused",
      },
      readResource: (name: string) =>
        Promise.resolve(this.resources.get(name) ?? null),
      writeResource: async (
        spec: string,
        name: string,
        value: Record<string, unknown>,
      ) => {
        const handle = await write(spec, name, value);
        this.resources.set(name, structuredClone(value));
        return handle;
      },
    });
  }
  execute() {
    return tools(() => this.http).methods.execute.execute({
      operationId: 11,
      worker: "run-11",
    }, this.context());
  }
  complete() {
    return this.calls.filter((c) => c.path.endsWith("/complete")).at(-1)!.body;
  }
}

Deno.test("installs through each exact manager and omits a transport target", async () => {
  for (
    const [manager, prefix, platform] of [
      ["apt", "apt", "linux"],
      ["composer", "composer", "linux"],
      ["vp", "vp", "linux"],
      ["brew", "homebrew-formula", "macos"],
      ["brew-cask", "homebrew-cask", "macos"],
    ]
  ) {
    const f = new Fixture();
    Object.assign(f.operation.input, {
      manager,
      platform,
      architecture: "arm64",
    });
    assertEquals((await f.execute()).status, "succeeded");
    const mutation = f.calls.find((c) =>
      c.body.key === "package-mutate" && c.path.endsWith("/run")
    )!.body;
    assertEquals(mutation.name, `tool.${prefix}-install`);
    assertEquals("node_id" in mutation, false);
    assertEquals(mutation.input, {
      package: "is-number",
      ...(platform === "macos" ? { architecture: "arm64" } : {}),
    });
    assertEquals(f.mutations, 1);
    assertEquals(f.complete().result, {
      installed_version: "7.0.0",
      block: null,
    });
  }
});
Deno.test("install refuses silent adoption, including on a restarted process", async () => {
  const f = new Fixture();
  f.installed = "7.0.0";
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.complete().error_code, "tool.package_already_installed");
  assertEquals(f.mutations, 0);
});
Deno.test("same managed install verifies the live version without changing it", async () => {
  const f = new Fixture();
  f.installed = "7.0.0";
  f.operation.input.tool_id = 8;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.mutations, 0);
});
Deno.test("adoption records only an accepted live package and never materializes", async () => {
  for (
    const block of [
      null,
      "protected",
      "dependency",
      "unsupported_source",
      "authorization_required",
    ]
  ) {
    const f = new Fixture();
    f.operation.kind = "tool.adopt";
    f.installed = "7.0.0";
    f.block = block;
    assertEquals(
      (await f.execute()).status,
      block === null ? "succeeded" : "failed",
    );
    assertEquals(f.mutations, 0);
    assertEquals(
      f.calls.some((c) => String(c.body.name).includes("materialize")),
      false,
    );
  }
});
Deno.test("absent adoption fails without creating a host effect", async () => {
  const f = new Fixture();
  f.operation.kind = "tool.adopt";
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.complete().error_code, "tool.package_absent");
});
Deno.test("update refuses an absent managed package", async () => {
  const f = new Fixture();
  f.operation.kind = "tool.update";
  f.operation.input.tool_id = 8;
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.mutations, 0);
});
Deno.test("candidate gates stop install and leave a blocked update unchanged", async () => {
  for (const kind of ["tool.install", "tool.update"]) {
    const f = new Fixture();
    f.operation.kind = kind;
    f.candidate = "8.0.0";
    f.operation.input.version_constraint = "^7.0";
    f.operation.input.constraint_intervals = [{
      start: "7.0.0.0-dev",
      start_operator: ">=",
      end: "8.0.0.0-dev",
      end_operator: "<",
    }];
    if (kind === "tool.update") {
      f.installed = "7.0.0";
      f.operation.input.tool_id = 8;
    }
    assertEquals(
      (await f.execute()).status,
      kind === "tool.install" ? "failed" : "succeeded",
    );
    assertEquals(f.mutations, 0);
  }
});
Deno.test("removal refuses a plan that includes another root or dependencies", async () => {
  for (const plan of [[], ["is-number", "other-package"]]) {
    const f = new Fixture();
    f.operation.kind = "tool.remove";
    f.installed = "7.0.0";
    f.plan = plan;
    assertEquals((await f.execute()).status, "failed");
    assertEquals(f.complete().error_code, "tool.removal_plan_unsafe");
    assertEquals(f.mutations, 0);
  }
});
Deno.test("exact removal verifies absence and already absent removal is harmless", async () => {
  for (const present of [true, false]) {
    const f = new Fixture();
    f.operation.kind = "tool.remove";
    if (present) f.installed = "7.0.0";
    assertEquals((await f.execute()).status, "succeeded");
    assertEquals(f.mutations, present ? 1 : 0);
  }
});
Deno.test("lost Node reply resumes the same effect key and recorded receipts", async () => {
  const f = new Fixture();
  f.loseRun = true;
  await assertRejects(() => f.execute(), Error, "Lost Node reply");
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.mutations, 1);
  assertEquals(
    f.calls.filter((c) =>
      c.path.endsWith("/run") && c.body.key === "package-mutate"
    ).map((c) => c.body.key),
    ["package-mutate", "package-mutate"],
  );
});
Deno.test("lost completion reply replays saved completion intent without any other call", async () => {
  const f = new Fixture();
  f.loseCompletion = true;
  await assertRejects(() => f.execute(), Error, "Lost completion reply");
  const count = f.calls.length;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.calls.length, count + 1);
  assertEquals(f.mutations, 1);
});
Deno.test("an expired lease stops before any new command", async () => {
  const f = new Fixture();
  f.leaseLost = true;
  await assertRejects(() => f.execute(), Error, "operation.lease_lost");
  assertEquals(f.calls.length, 1);
});
Deno.test("cancellation before execution and Gateway cancellation both stop mutation", async () => {
  for (const atRun of [true, false]) {
    const f = new Fixture();
    f.operation.cancel_requested = !atRun;
    f.cancelAtRun = atRun;
    assertEquals((await f.execute()).status, "cancelled");
    assertEquals(f.mutations, 0);
  }
});
Deno.test("a command refusal retains its stable error code", async () => {
  const f = new Fixture();
  f.refuse = "tool.vp_scope_conflict";
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.complete().error_code, "tool.manager_unavailable");
});
Deno.test("normalization preserves package-manager distinctions", () => {
  assertEquals(normalizeVersion("1:1.25.0-2ubuntu4", "apt"), "1.25.0");
  assertEquals(normalizeVersion("25.8.1_1", "brew"), null);
  assertEquals(normalizeVersion("3.003", "brew-cask"), null);
  assertEquals(normalizeVersion("v1.2", "vp"), "1.2.0");
  const input = Input.parse({
    node_id: 2,
    manager: "vp",
    package: "x",
    platform: "linux",
    architecture: "x86_64",
    constraint_intervals: [{
      start: "1.0.0.0-dev",
      start_operator: ">=",
      end: "2.0.0.0-dev",
      end_operator: "<",
    }],
  });
  assertEquals(allows(input, "1.9.0"), true);
  assertEquals(allows(input, "2.0.0-beta.1"), false);
});

Deno.test("compiled ranges preserve Composer 3.5.1 constraint decisions", async () => {
  const corpus = JSON.parse(
    await Deno.readTextFile(
      new URL("./composer-constraints.json", import.meta.url),
    ),
  );
  for (const entry of corpus) {
    const input = Input.parse({
      node_id: 2,
      manager: "vp",
      package: "x",
      platform: "linux",
      architecture: "x86_64",
      constraint_intervals: entry.intervals,
    });
    for (const { version, allowed } of entry.versions) {
      assertEquals(
        allows(input, version),
        allowed,
        `${entry.constraint}: ${version}`,
      );
    }
  }
});
