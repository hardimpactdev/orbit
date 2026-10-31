import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { fleet } from "./mod.ts";
import type { Context } from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

class Fixture {
  operation = {
    id: 9,
    node_id: 2,
    kind: "fleet.recipe",
    status: "running",
    result: null as Record<string, unknown> | null,
    error_code: null as string | null,
    cancel_requested: false,
    input: {
      node_id: 2,
      automatic: true,
      daytime: true,
      has_php: true,
      vpn: false,
      user: "orbit",
      snapshot: "20261028T000000Z",
      git_packages: [],
      artifact_mirror: "https://gateway.example.test/artifacts",
      architecture: "amd64",
      tools: { curl: { source: "ubuntu", version: "1" } },
      vp_lock: null,
      versions: {},
      mirror: "https://gateway.example.test/artifacts",
      pcov: true,
      opentelemetry: true,
      extras: "refuse",
      fpm: "all",
      repack: { package: "dpkg-repack", version: "1" },
      release: null,
      identity: "sha256:recipe",
      agent_sha256: "a".repeat(64),
      cli_version: "0.1.0",
    } as Record<string, unknown>,
    steps: [] as { name: string; status: string; error_code: string | null }[],
    step_results: {} as Record<string, unknown>,
    execution_context: { descriptors: [], result: { wait_resume: false } },
  };
  resources = new Map<string, Record<string, unknown>>();
  receipts = new Map<string, Record<string, unknown>>();
  calls: { path: string; body: Record<string, unknown> }[] = [];
  mutations = 0;
  loseRun: string | null = null;
  loseCompletion = false;
  refusal: string | null = null;
  refusalAt = "recipe.apply";
  childStatus = "succeeded";
  waitingOnChild = false;
  runningReceipts = new Set<string>();
  closeWindowAt: string | null = null;
  windowRefusalCode = "fleet.window_closed";
  update = {
    outcome: "unchanged",
    cli_version: "0.1.0",
    exit_code: 0,
  } as Record<string, unknown>;
  updates: Record<string, unknown>[] = [];
  last: Record<string, unknown> = {};
  at = "2026-10-28T02:00:00Z";
  currentDaytime: boolean | null = null;
  probeReachable = true;
  loseBeforeReceipt: string | null = null;
  loseReceiptRead = false;
  failInstallOnce = false;
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
  http: Http = async (method, path, raw) => {
    const body = (raw ?? {}) as Record<string, unknown>;
    this.calls.push({ path, body });
    await Promise.resolve();
    let data: unknown = this.operation;
    if (path.endsWith("/wait")) this.waitingOnChild = true;
    if (
      path.endsWith("/heartbeat") && this.waitingOnChild &&
      ["succeeded", "failed", "cancelled"].includes(this.childStatus)
    ) {
      this.operation.execution_context.result.wait_resume = true;
      this.waitingOnChild = false;
    }
    if (path.endsWith("/visit-policy")) {
      const daytime = this.currentDaytime ??
        this.operation.input.daytime !== false;
      const hour = Number(
        new Intl.DateTimeFormat("en-GB", {
          timeZone: "Europe/Amsterdam",
          hour: "2-digit",
          hourCycle: "h23",
        }).format(new Date(this.at)),
      );
      data = { daytime, window_open: daytime || hour >= 3 && hour < 6 };
    }
    if (path.endsWith("/children")) {
      data = method === "GET"
        ? [{
          id: 20,
          key: "footprint",
          kind: "node.converge",
          node_id: 2,
          status: this.childStatus,
        }]
        : { id: 20 };
    }
    if (path.includes("/receipts/")) {
      if (this.loseReceiptRead) {
        this.loseReceiptRead = false;
        throw new Error("Receipt read lost");
      }
      const key = decodeURIComponent(path.split("/receipts/")[1]);
      data = {
        effect_key: `op-9.${key}`,
        state: this.runningReceipts.has(key)
          ? "running"
          : this.receipts.has(key)
          ? "succeeded"
          : "unknown",
        exit_code: this.receipts.has(key) ? 0 : null,
        result: this.receipts.get(key) ?? null,
        finished_at: null,
      };
    }
    if (path.endsWith("/run")) {
      const name = String(body.name), key = String(body.key);
      if (this.closeWindowAt === name) {
        return {
          status: 409,
          body: { error: { code: this.windowRefusalCode } },
        };
      }
      if (this.loseBeforeReceipt === name) {
        this.loseBeforeReceipt = null;
        throw new Error("Lost SSH before dispatch");
      }
      if (!this.receipts.has(key)) {
        const failInstall = name === "cli.install" && this.failInstallOnce;
        if (failInstall) this.failInstallOnce = false;
        const result = failInstall
          ? { error_code: "cli.download_failed" }
          : name === "fleet.probe"
          ? { reachable: this.probeReachable }
          : name === this.refusalAt && this.refusal
          ? { error_code: this.refusal }
          : name === "recipe.apply"
          ? { healthy: true, changed_packages: ["curl"] }
          : name === "recipe.incus-preflight"
          ? { ready: true }
          : name === "fleet.recipe-begin"
          ? { started: true }
          : name === "php.install-build"
          ? { outcome: "applied" }
          : name === "recipe.verify-delivery"
          ? {
            identity: this.operation.input.identity,
            agent_sha256: this.operation.input.agent_sha256,
            cli_version: this.operation.input.cli_version,
          }
          : name === "cli.install"
          ? { configured: false, installed: null }
          : name === "cli.self-update"
          ? this.updates.shift() ?? this.update
          : name === "github.recipe-pr"
          ? { completed: true, outcome: "checked", url: null }
          : name === "fleet.cli-converge-result"
          ? this.operation.input.plan_error
            ? { error_code: this.operation.input.plan_error }
            : {
              completed: true,
              outcome: this.operation.input.skip_reason
                ? "skipped"
                : "cli_present",
              reason: this.operation.input.skip_reason,
              version: null,
              configured: false,
            }
          : {
            passed: true,
            agent_version: "0.5.0",
            cli_version: "0.1.0",
            changed: false,
          };
        this.receipts.set(key, result);
        if (["recipe.apply", "github.recipe-pr"].includes(name)) {
          this.mutations++;
        }
      }
      data = this.receipts.get(key);
      if (this.loseRun === name) {
        this.loseRun = null;
        throw new Error("Lost run reply");
      }
    }
    if (path.endsWith("/step")) {
      this.operation.steps = this.operation.steps.filter((s) =>
        s.name !== body.name
      );
      this.operation.steps.push({
        name: String(body.name),
        status: String(body.status),
        error_code: body.error_code as string | null,
      });
      if (body.status === "succeeded") {
        this.operation.step_results[String(body.name)] = body.result;
      }
    }
    if (path.endsWith("/complete")) {
      this.last = body;
      this.operation.status = String(body.status);
      this.operation.result = body.result as Record<string, unknown>;
      this.operation.error_code = body.error_code as string | null;
      if (this.loseCompletion) {
        this.loseCompletion = false;
        throw new Error("Lost completion reply");
      }
    }
    return { status: 200, body: { data } };
  };
  execute(at = "2026-10-28T02:00:00Z") {
    this.at = at;
    return fleet(() => this.http).methods.execute.execute({
      kind: this.operation.kind,
      worker: "worker",
    }, this.context());
  }
  commands() {
    return this.calls.filter((c) => c.path.endsWith("/run")).map((c) =>
      c.body.name
    );
  }
}
Deno.test("recipe application preserves preflight, inventory/Doctor and locked delivery order", async () => {
  const f = new Fixture();
  f.operation.input.automatic = false;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.commands(), [
    "recipe.incus-preflight",
    "fleet.recipe-begin",
    "recipe.apply",
    "php.install-build",
    "fleet.verify",
    "recipe.verify-delivery",
  ]);
  assertEquals(f.last.result, {
    identity: "sha256:recipe",
    agent_sha256: "a".repeat(64),
    cli_version: "0.1.0",
  });
});
Deno.test("automatic recipe never sends Incus or changes its admitted apply payload", async () => {
  const f = new Fixture();
  await f.execute();
  assertEquals(f.commands().includes("recipe.incus-preflight"), false);
  const run = f.calls.find((c) => c.body.name === "recipe.apply")!;
  assertEquals(
    (run.body.input as Record<string, unknown>).tools,
    f.operation.input.tools,
  );
  assertEquals("node_id" in run.body, false);
});
Deno.test("PHP and Doctor refusals stop before the locked recipe delivery marker", async () => {
  for (
    const [command, code] of [[
      "php.install-build",
      "php.build_rollback_failed",
    ], ["fleet.verify", "node.recipe_inventory_failed"]]
  ) {
    const f = new Fixture();
    f.refusalAt = command;
    f.refusal = code;
    assertEquals((await f.execute()).status, "failed");
    assertEquals(f.commands().includes("recipe.verify-delivery"), false);
    assertEquals(f.last.error_code, code);
  }
});
Deno.test("recipe restart reconciles a lost apply reply with the same key", async () => {
  const f = new Fixture();
  f.loseRun = "recipe.apply";
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.mutations, 1);
  assertEquals(
    f.calls.filter((c) => c.body.name === "recipe.apply").map((c) =>
      c.body.key
    ),
    ["recipe-apply"],
  );
});
Deno.test("a VPN PHP application awaits its footprint child without repeating successful steps", async () => {
  const f = new Fixture();
  f.operation.input.vpn = true;
  f.childStatus = "running";
  assertEquals((await f.execute()).status, "waiting");
  assertEquals(f.commands().includes("recipe.verify-delivery"), false);
  f.childStatus = "succeeded";
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(
    f.calls.filter((c) => c.path.endsWith("/children") && c.body.key).length,
    1,
  );
  assertEquals(f.mutations, 1);
});
Deno.test("completed recipe intent replays after a lost completion reply", async () => {
  const f = new Fixture();
  f.loseCompletion = true;
  await assertRejects(() => f.execute(), Error, "Lost completion reply");
  const commands = f.commands().length;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.commands().length, commands);
});
function rollout(): Fixture {
  const f = new Fixture();
  f.operation.kind = "fleet.rollout";
  f.operation.input = {
    node_id: 2,
    daytime: true,
    user: "orbit",
    release: {
      version: "0.1.0",
      url: "https://example.test/cli",
      sha256: "a".repeat(64),
    },
    profile: "profile",
    downgrade_to: null,
    repairs: ["agent"],
    first_visit: true,
  };
  return f;
}
Deno.test("rollout installs and updates the CLI then awaits the owner footprint before Doctor", async () => {
  const f = rollout();
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.commands(), [
    "cli.install",
    "cli.self-update",
    "fleet.verify",
  ]);
  assertEquals(f.calls.find((c) => c.path.endsWith("/children"))!.body, {
    worker: "worker",
    key: "footprint",
  });
});
Deno.test("foreign CLI, busy update, pending release and incomplete update stop before footprint", async () => {
  for (
    const code of [
      "cli.foreign_binary",
      "node.update_busy",
      "fleet.release_pending",
      "fleet.self_update_incomplete",
    ]
  ) {
    const f = rollout();
    if (code.startsWith("fleet.")) {
      f.update.outcome = code === "fleet.release_pending"
        ? "pending"
        : "incomplete";
    } else {
      f.refusalAt = "cli.install";
      f.refusal = code;
    }
    assertEquals((await f.execute()).status, "failed");
    assertEquals(f.last.error_code, code);
    assertEquals(
      f.calls.some((c) =>
        c.path.endsWith("/children") && Object.keys(c.body).length > 0
      ),
      false,
    );
  }
});
Deno.test("a failed first footprint child stops the rollout before verification", async () => {
  const f = rollout();
  f.childStatus = "failed";
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.commands().includes("fleet.verify"), false);
});
Deno.test("recipe PR write keeps only the declared App secret reference in Swamp", async () => {
  const f = new Fixture();
  f.operation.kind = "fleet.recipe_pr";
  f.operation.input = {
    action: "merge",
    variant: "snapshot",
    date: "20261028",
    pull_number: 99,
    head_sha: "a".repeat(40),
    token: { $secret: "github.app", id: 2 },
  };
  f.loseRun = "github.recipe-pr";
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.mutations, 1);
  assertEquals(
    f.calls.find((c) => c.path.endsWith("/run"))!.body.transport,
    "gateway",
  );
});

Deno.test("queued shared visits stop outside the window and interrupted visits halt without further effects", async () => {
  const queued = rollout();
  queued.operation.input.daytime = false;
  assertEquals((await queued.execute("2026-10-28T05:00:00Z")).status, "failed");
  assertEquals(queued.commands(), []);
  assertEquals(queued.last.error_code, "fleet.window_closed");
  const manual = new Fixture();
  manual.operation.input.daytime = false;
  manual.operation.input.automatic = false;
  assertEquals(
    (await manual.execute("2026-10-28T12:00:00Z")).status,
    "succeeded",
  );
  const started = rollout();
  started.operation.input.daytime = false;
  started.operation.steps = [{
    name: "cli-install",
    status: "succeeded",
    error_code: null,
  }];
  started.operation.step_results["cli-install"] = {
    configured: false,
    installed: null,
  };
  assertEquals(
    (await started.execute("2026-10-28T05:00:00Z")).status,
    "failed",
  );
});

Deno.test("restricted recipe and rollout re-entry fails before effects, even inside the window", async () => {
  for (const kind of ["fleet.recipe", "fleet.rollout"]) {
    for (const at of ["2026-10-28T02:58:00Z", "2026-10-28T08:30:00Z"]) {
      const f = kind === "fleet.recipe" ? new Fixture() : rollout();
      // The Gateway policy, not the stale admitted flag, decides whether this is a restricted Node.
      f.currentDaytime = false;
      f.operation.steps = [{
        name: kind === "fleet.recipe" ? "recipe-begin" : "cli-install",
        status: "running",
        error_code: null,
      }];
      assertEquals((await f.execute(at)).status, "failed");
      assertEquals(f.last.error_code, "fleet.visit_interrupted");
      assertEquals(f.commands(), []);
      assertEquals(
        f.calls.some((c) =>
          c.path.endsWith("/children") && Object.keys(c.body).length > 0
        ),
        false,
      );
      assertEquals(f.operation.steps[0].status, "failed");
    }
  }
});
Deno.test("an interrupted recipe closes its step before replayable completion", async () => {
  const f = new Fixture();
  f.currentDaytime = false;
  f.operation.steps = [{
    name: "recipe-apply",
    status: "running",
    error_code: null,
  }];
  f.loseCompletion = true;
  await assertRejects(() => f.execute(), Error, "Lost completion reply");
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.commands(), []);
  assertEquals(f.last.error_code, "fleet.visit_interrupted");
});
Deno.test("CLI transport loss marks an unreachable Node and starts no footprint child", async () => {
  for (const command of ["cli.install", "cli.self-update"]) {
    const f = rollout();
    f.loseBeforeReceipt = command;
    f.probeReachable = false;
    assertEquals((await f.execute()).status, "failed");
    assertEquals(f.last.error_code, "node.ssh_unreachable");
    assertEquals(f.calls.some((c) => c.body.kind === "node.converge"), false);
    assertEquals(f.operation.steps.at(-1)!.status, "failed");
  }
});
Deno.test("reachable CLI loss reads the receipt and retries the original unknown key once", async () => {
  for (const command of ["cli.install", "cli.self-update"]) {
    const f = rollout();
    f.loseBeforeReceipt = command;
    assertEquals((await f.execute()).status, "succeeded");
    const runs = f.calls.filter((c) =>
      c.path.endsWith("/run") && c.body.name === command
    );
    assertEquals(runs.length, 2);
    assertEquals(runs[0].body.key, runs[1].body.key);
    assertEquals(f.calls.some((c) => c.path.includes("/receipts/")), true);
  }
});
Deno.test("a lost CLI success reply is resolved without another invocation", async () => {
  const f = rollout();
  f.loseRun = "cli.self-update";
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(
    f.calls.filter((c) =>
      c.path.endsWith("/run") && c.body.name === "cli.self-update"
    ).length,
    1,
  );
});
Deno.test("a failed CLI download gets one bounded new attempt", async () => {
  const f = rollout();
  f.failInstallOnce = true;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(
    f.calls.filter((c) => c.body.name === "cli.install").map((c) => c.body.key),
    ["cli-install", "cli-install-retry"],
  );
});
Deno.test("a definite self-update failure does not retry", async () => {
  const f = rollout();
  f.update = {
    outcome: "failed",
    error_code: "agent.unhealthy",
    exit_code: 1,
    report_present: true,
  };
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.last.error_code, "agent.unhealthy");
  assertEquals(
    f.calls.filter((c) => c.body.name === "cli.self-update").length,
    1,
  );
});

Deno.test("a pending child releases the parent lease through the shared wait primitive", async () => {
  const f = rollout();
  f.childStatus = "running";
  assertEquals((await f.execute()).status, "waiting");
  assertEquals(
    f.calls.filter((c) => c.path.endsWith("/wait")).map((c) => c.body),
    [
      { worker: "worker", keys: ["footprint"] },
    ],
  );
  assertEquals(
    f.calls.filter((c) => c.path.endsWith("/children") && !c.body.key).length,
    1,
  );
  assertEquals(f.commands().includes("fleet.verify"), false);
});
Deno.test("a Gateway window refusal closes active steps and halts a started visit", async () => {
  const f = rollout();
  f.currentDaytime = false;
  f.closeWindowAt = "cli.self-update";
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.last.error_code, "fleet.visit_interrupted");
  assertEquals(f.operation.steps.some((s) => s.status === "running"), false);
  assertEquals(f.calls.some((c) => c.body.kind === "node.converge"), false);
});

Deno.test("self-update without a JSON verdict retries once under a new receipt key", async () => {
  const f = rollout();
  f.updates = [{ outcome: "failed", exit_code: 255, report_present: false }, {
    outcome: "unchanged",
    cli_version: "0.1.0",
    exit_code: 0,
    report_present: true,
  }];
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(
    f.calls.filter((c) => c.body.name === "cli.self-update").map((c) =>
      c.body.key
    ),
    ["self-update", "self-update-retry"],
  );
});

Deno.test("an inherited window constraint refusal halts the parent without another effect", async () => {
  const f = rollout();
  f.currentDaytime = false;
  f.closeWindowAt = "cli.self-update";
  f.windowRefusalCode = "operation.run_constraint_failed";
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.last.error_code, "fleet.visit_interrupted");
  assertEquals(
    f.calls.some((c) =>
      c.path.endsWith("/children") && Object.keys(c.body).length > 0
    ),
    false,
  );
});

function cliConverge(): Fixture {
  const f = new Fixture();
  f.operation.kind = "fleet.cli_converge";
  f.operation.input = {
    user: "orbit",
    release: {
      version: "0.1.0",
      url: "https://example.test/cli",
      sha256: "a".repeat(64),
    },
    profile: "admitted-profile",
    skip_reason: null,
    plan_error: null,
  };
  return f;
}
Deno.test("CLI child installs only the admitted release and proves its final result", async () => {
  const f = cliConverge();
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.commands(), ["cli.install", "fleet.cli-converge-result"]);
  assertEquals(f.calls.find((c) => c.body.name === "cli.install")!.body, {
    worker: "worker",
    key: "cli-install",
    transport: "agent",
    name: "cli.install",
    input: {
      user: "orbit",
      release: f.operation.input.release,
      profile: "admitted-profile",
    },
  });
  assertEquals(f.last.result, {
    completed: true,
    outcome: "cli_present",
    reason: null,
    version: null,
    configured: false,
  });
});
Deno.test("CLI child proves release and eligibility skips without invoking the Node", async () => {
  for (const reason of ["release_missing", "roleless", "sandbox"]) {
    const f = cliConverge();
    f.operation.input.skip_reason = reason;
    f.operation.input.release = null;
    assertEquals((await f.execute()).status, "succeeded");
    assertEquals(f.commands(), ["fleet.cli-converge-result"]);
    assertEquals(f.last.result, {
      completed: true,
      outcome: "skipped",
      reason,
      version: null,
      configured: false,
    });
  }
});
Deno.test("CLI child returns optional failure or cancellation with no later effect", async () => {
  for (
    const code of [
      "cli.foreign_binary",
      "cli.update_busy",
      "cli.release_needed",
      "cli.checksum_mismatch",
      "cli.download_failed",
      "cli.candidate_invalid",
      "cli.install_failed",
      "operation.cancelled",
    ]
  ) {
    const f = cliConverge();
    f.refusalAt = "cli.install";
    f.refusal = code;
    assertEquals(
      (await f.execute()).status,
      code === "operation.cancelled" ? "cancelled" : "failed",
    );
    assertEquals(
      f.last.error_code,
      code === "cli.update_busy"
        ? "node.update_busy"
        : code === "cli.release_needed"
        ? "cli.release_unavailable"
        : code,
    );
    assertEquals(f.commands(), ["cli.install"]);
  }
});
Deno.test("CLI child restart resolves a lost install reply without repeating the invocation", async () => {
  const f = cliConverge();
  f.loseRun = "cli.install";
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.commands().filter((name) => name === "cli.install").length, 1);
  assertEquals(
    f.calls.some((c) => c.path.endsWith("/receipts/cli-install")),
    true,
  );
});
Deno.test("CLI child replays a lost completion reply without another effect", async () => {
  const f = cliConverge();
  f.loseCompletion = true;
  await assertRejects(() => f.execute(), Error, "Lost completion reply");
  const count = f.commands().length;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.commands().length, count);
});
Deno.test("CLI child records a planning failure without invoking the Node", async () => {
  const f = cliConverge();
  f.operation.input.plan_error = "cli.architecture_unsupported";
  f.operation.input.release = null;
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.last.error_code, "cli.architecture_unsupported");
  assertEquals(f.commands(), ["fleet.cli-converge-result"]);
});

Deno.test("restricted rollout and VPN recipe resume only a proven child wait within the window", async () => {
  for (const kind of ["fleet.rollout", "fleet.recipe"]) {
    const f = kind === "fleet.rollout" ? rollout() : new Fixture();
    f.operation.input.daytime = false;
    f.operation.input.vpn = true;
    f.childStatus = "queued";
    assertEquals((await f.execute()).status, "waiting");
    const commands = f.commands().length;
    f.childStatus = "succeeded";
    assertEquals((await f.execute()).status, "succeeded");
    assertEquals(
      f.commands().slice(commands),
      kind === "fleet.rollout"
        ? ["fleet.verify"]
        : ["fleet.verify", "recipe.verify-delivery"],
    );
  }
});
Deno.test("window closure during a proven child wait interrupts without another effect", async () => {
  for (const kind of ["fleet.rollout", "fleet.recipe"]) {
    const f = kind === "fleet.rollout" ? rollout() : new Fixture();
    f.operation.input.daytime = false;
    f.operation.input.vpn = true;
    f.childStatus = "queued";
    assertEquals((await f.execute()).status, "waiting");
    const count = f.commands().length;
    f.childStatus = "failed";
    assertEquals((await f.execute("2026-10-28T08:30:00Z")).status, "failed");
    assertEquals(f.last.error_code, "fleet.visit_interrupted");
    assertEquals(f.commands().length, count);
  }
});
Deno.test("interruption retains the hold while any original or retry effect runs", async () => {
  for (
    const key of ["self-update", "self-update-retry", "self-update-downgrade"]
  ) {
    const f = rollout();
    f.operation.input.daytime = false;
    f.operation.steps = [{
      name: "self-update",
      status: "running",
      error_code: null,
    }];
    f.runningReceipts.add(key);
    assertEquals((await f.execute("2026-10-28T08:30:00Z")).status, "waiting");
    assertEquals(f.calls.some((c) => c.path.endsWith("/complete")), false);
    assertEquals(f.calls.some((c) => c.path.endsWith("/step")), false);
    f.runningReceipts.clear();
    assertEquals((await f.execute()).status, "failed");
    assertEquals(f.last.error_code, "fleet.visit_interrupted");
    assertEquals(f.commands(), []);
  }
});
Deno.test("an unreadable interrupted effect stays held and a pending child settles before stop", async () => {
  const f = rollout();
  f.operation.input.daytime = false;
  f.operation.steps = [{
    name: "self-update",
    status: "running",
    error_code: null,
  }];
  f.loseReceiptRead = true;
  await assertRejects(() => f.execute(), Error, "Receipt read lost");
  assertEquals(f.calls.some((c) => c.path.endsWith("/complete")), false);
  f.childStatus = "running";
  assertEquals((await f.execute()).status, "waiting");
  assertEquals(f.calls.some((c) => c.path.endsWith("/complete")), false);
  f.childStatus = "succeeded";
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.last.error_code, "fleet.visit_interrupted");
});
