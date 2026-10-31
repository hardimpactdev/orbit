import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { type Context, Operation } from "../../operation-runner/mod.ts";
import { node } from "./mod.ts";

class Fixture {
  operation = Operation.parse({
    id: 7,
    node_id: 2,
    kind: "node.prepare_php_packages",
    status: "running",
    cancel_requested: false,
    step_results: {},
    input: {
      versions: ["8.5"],
      profile: "app-prod",
      _php_packages_revision: "a".repeat(64),
      _php_packages_deadline: 1793232120,
      _php_packages: {
        versions: {
          "8.5": {
            packages: [{
              name: "php8.5-cli",
              version: "admitted",
              sha256: "a".repeat(64),
            }],
          },
        },
        mirror: "https://mirror.example.test",
        pcov: false,
        opentelemetry: false,
        extras: "keep",
        fpm: "none",
        repack: { package: "dpkg-repack", version: "admitted" },
        release: { id: "ubuntu", codenames: ["resolute"] },
      },
    },
  });
  calls: { method: string; path: string; body: Record<string, unknown> }[] = [];
  resources = new Map<string, Record<string, unknown>>();
  result: Record<string, unknown> = { outcome: "applied" };
  target: Record<string, unknown> | null = null;
  terminal: Record<string, unknown> | null = null;
  lostRun = false;
  lostCompletion = false;
  busyReceipt = false;
  staleAdmission = false;
  noWork = false;
  claims = 0;
  effects = 0;
  http: Http = async (method, path, raw) => {
    await Promise.resolve();
    const body = (raw ?? {}) as Record<string, unknown>;
    this.calls.push({ method, path, body });
    let data: unknown = this.operation;
    if (path.endsWith("/claim")) {
      this.claims++;
      data = this.noWork ? null : this.operation;
    } else if (path.startsWith("/api/v1/operations/")) {
      data = this.terminal ??
        { ...this.operation, result: null, error_code: null };
    } else if (path.includes("/receipts/")) {
      data = {
        effect_key: "op-7.php-packages",
        state: this.busyReceipt
          ? "running"
          : this.target
          ? this.target.error_code ? "failed" : "succeeded"
          : "unknown",
        result: this.busyReceipt ? null : this.target,
        exit_code: null,
        finished_at: null,
      };
    } else if (path.endsWith("/run")) {
      if (this.staleAdmission) {
        return {
          status: 409,
          body: { error: { code: "app-prod.php_package_source_unavailable" } },
        };
      }
      this.effects++;
      this.target = this.result;
      if (this.lostRun) {
        this.lostRun = false;
        throw new Error("Lost run reply");
      }
      data = this.result;
    } else if (path.endsWith("/step") && body.status === "succeeded") {
      this.operation.step_results[String(body.name)] = body.result;
    } else if (path.endsWith("/complete")) {
      this.terminal = {
        id: 7,
        kind: this.operation.kind,
        status: body.status,
        result: body.result,
        error_code: body.error_code,
      };
      if (this.lostCompletion) {
        this.lostCompletion = false;
        throw new Error("Lost completion reply");
      }
      data = this.terminal;
    }
    return { status: 200, body: { data } };
  };
  context(): Context {
    const globalArgs = {
      gatewayUrl: "https://gateway.example.test",
      caFile: "/unused",
    };
    const { context } = createModelTestContext({ globalArgs });
    const write = context.writeResource.bind(context);
    return Object.assign(context, {
      globalArgs,
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
    return node(() => this.http).methods.execute.execute({
      kind: "node.prepare_php_packages",
      worker: "worker-1",
    }, this.context());
  }
  completion() {
    return this.calls.findLast((c) => c.path.endsWith("/complete"))?.body;
  }
}

Deno.test("claims within the placed invocation and installs only admitted packages", async () => {
  const f = new Fixture();
  await f.execute();
  assertEquals(f.calls[0].body, {
    kind: "node.prepare_php_packages",
    worker: "worker-1",
  });
  const run = f.calls.find((c) => c.path.endsWith("/run"))!;
  assertEquals(run.body, {
    worker: "worker-1",
    key: "php-packages",
    transport: "agent",
    name: "php.install-build",
    input: f.operation.input._php_packages,
  });
  assertEquals(f.completion()?.status, "succeeded");
  assertEquals(f.effects, 1);
});

Deno.test("a lost reply reads target proof and never claims another Operation", async () => {
  const f = new Fixture();
  f.lostRun = true;
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  await f.execute();
  assertEquals(f.claims, 1);
  assertEquals(f.effects, 1);
  assertEquals(f.calls.filter((c) => c.path.includes("/receipts/")).length, 1);
  assertEquals(f.completion()?.status, "succeeded");
});

Deno.test("lost completion is verified through the terminal Operation", async () => {
  const f = new Fixture();
  f.lostCompletion = true;
  await assertRejects(() => f.execute(), Error, "Lost completion reply");
  await f.execute();
  assertEquals(f.effects, 1);
  assertEquals(f.calls.filter((c) => c.path.endsWith("/complete")).length, 1);
  f.terminal!.result = { outcome: "current" };
  await assertRejects(
    () => f.execute(),
    Error,
    "operation.completion_unproven",
  );
});

Deno.test("running target proof leaves the original effect unsettled", async () => {
  const f = new Fixture();
  f.lostRun = true;
  await assertRejects(() => f.execute());
  f.busyReceipt = true;
  assertEquals((await f.execute()).status, "waiting");
  assertEquals(f.effects, 1);
  assertEquals(f.completion(), undefined);
});

Deno.test("refuses unsafe policy before an effect", async () => {
  for (const [field, value] of [["fpm", "shared"], ["extras", "purge"]]) {
    const f = new Fixture();
    (f.operation.input._php_packages as Record<string, unknown>)[
      String(field)
    ] = value;
    await assertRejects(() => f.execute());
    assertEquals(f.effects, 0);
  }
});

Deno.test("source and installer refusals keep their existing public error codes", async () => {
  for (
    const [code, expected] of [
      [
        "php.build_platform_unsupported",
        "app-prod.php_package_source_unavailable",
      ],
      [
        "php.build_conflicting_source",
        "app-prod.php_package_source_unavailable",
      ],
      ["php.build_download_failed", "app-prod.php_package_source_unavailable"],
      ["php.build_install_failed", "app-prod.php_install_failed"],
    ]
  ) {
    const f = new Fixture();
    f.result = { error_code: code };
    await f.execute();
    assertEquals(f.completion()?.error_code, expected);
    assertEquals(f.completion()?.status, "failed");
  }
});

Deno.test("idle discovery and pre-effect cancellation perform no remote effects", async () => {
  const empty = new Fixture();
  empty.noWork = true;
  assertEquals(await empty.execute(), { claimed: false });
  assertEquals(empty.effects, 0);
  const cancelled = new Fixture();
  cancelled.operation.cancel_requested = true;
  await cancelled.execute();
  assertEquals(cancelled.effects, 0);
  assertEquals(cancelled.completion()?.status, "cancelled");
});

Deno.test("unexpected outcomes terminate failed and replay completion without another claim", async () => {
  for (const result of [{ outcome: "planned" }, {}]) {
    const f = new Fixture();
    f.result = result;
    await f.execute();
    assertEquals(f.completion()?.status, "failed");
    assertEquals(f.completion()?.error_code, "app-prod.php_install_failed");
    await f.execute();
    assertEquals(f.claims, 1);
    assertEquals(f.effects, 1);
  }
});

Deno.test("a stale admission refuses before dispatch and completes failed", async () => {
  const f = new Fixture();
  f.staleAdmission = true;
  await f.execute();
  assertEquals(f.effects, 0);
  assertEquals(f.completion()?.status, "failed");
  assertEquals(
    f.completion()?.error_code,
    "app-prod.php_package_source_unavailable",
  );
  await f.execute();
  assertEquals(f.claims, 1);
});
