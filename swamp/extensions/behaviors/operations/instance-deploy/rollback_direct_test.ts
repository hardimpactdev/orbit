import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { rollbackDirect } from "./rollback_direct.ts";
import { deployDirect } from "./deploy_direct.ts";
import { PhpLocalError } from "../php/local.ts";
import { LocalDeployError } from "./deploy_local.ts";
import { localFixture } from "./deploy_test_fixture.ts";

const selected = { name: "current", commit: "a".repeat(40) };
const retained = { name: "retained", commit: "b".repeat(40) };
function fixture() {
  const args = {
    operation_id: 100,
    kind: "instance.rollback" as const,
    node_id: 9,
    run_id: "ed7cfb7a-64f3-496f-8722-e3fd1c06947d",
  };
  const input = {
    instance_id: 50,
    node_id: 9,
    platform: "linux",
    architecture: "x86_64",
    project_id: 58,
    commit: null,
    release: "retained",
    deployment: {
      variant: "rollback",
      node_user: "nckrtl",
      home: "/home/orbit-app-58",
      user: "orbit-app-58",
      repository: "https://example.test/app.git",
      branch: "main",
      protocols: ["https"],
      relative_root: "public",
      application_suffix: "",
      served: [],
      shared_paths: [],
    },
    php_input: null,
    runtime_definitions: {
      processes: [],
      schedules: [],
      restart: [] as {
        process_id: number;
        runtime: "systemd" | "docker";
        name: string;
        on_demand: boolean;
        vite: null;
      }[],
    },
  };
  let status = "running", cancel_requested = false, activeSelection = selected;
  const posts: Record<string, unknown>[] = [],
    effects: string[] = [],
    journal = new Map<string, Record<string, unknown>>();
  const http: Http = (method, path, body) => {
    if (method === "GET") {
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            id: args.operation_id,
            node_id: 9,
            kind: args.kind,
            status,
            cancel_requested,
            input,
          },
        },
      });
    }
    if (!path.endsWith("/result")) {
      throw new Error("No private material or child endpoint is allowed");
    }
    const data = body as Record<string, unknown>;
    posts.push(data);
    status = String(data.status);
    return Promise.resolve({ status: 200, body: { data: {} } });
  };
  const source = localFixture((call) => {
    effects.push(call.name);
    assertEquals(call.input.user, "orbit-app-58");
    if (call.name === "inspectRelease") {
      return Promise.resolve({
        release: call.input.name === "current"
          ? selected
          : call.input.name
          ? retained
          : activeSelection,
      });
    }
    if (call.name === "activateRelease") {
      activeSelection = call.input.name === "current" ? selected : retained;
      return Promise.resolve(activeSelection);
    }
    throw new Error(
      "Rollback must not fetch, checkout, prepare or prune releases",
    );
  });
  const ctx = {
    globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/fixture/ca" },
    readResource: (name: string) => Promise.resolve(journal.get(name) ?? null),
    writeResource: (
      _: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      journal.set(name, value);
      return Promise.resolve();
    },
  };
  const ports = {
    source,
    http: () => http,
    now: () => Date.parse("2026-10-31T10:00:00Z"),
  };
  const run = (override: Parameters<typeof rollbackDirect>[0] = {}) =>
    deployDirect({ rollback: { ...ports, ...override } }).methods.rollback
      .execute(args, ctx);
  const failure = () =>
    deployDirect({ rollback: ports }).methods.rollbackFailure.execute(
      args,
      ctx,
    );
  return {
    args,
    input,
    ctx,
    ports,
    posts,
    effects,
    journal,
    run,
    failure,
    cancel: () => {
      cancel_requested = true;
    },
    selectCurrent: () => {
      input.release = "current";
    },
    setStatus: (value: string) => {
      status = value;
    },
  };
}

Deno.test("fresh running rollback selects one retained release without Git or application commands", async () => {
  const f = fixture();
  assertEquals(await f.run(), { status: "succeeded" });
  assertEquals(f.effects, [
    "inspectRelease",
    "inspectRelease",
    "activateRelease",
  ]);
  assertEquals(f.posts.at(-1)?.result, {
    instance_id: 50,
    commit: retained.commit,
    release: retained,
    previous: selected,
    selected_release: retained,
    selection_confirmed: true,
    failed_step: null,
    runtime_definitions: { processes: [], schedules: [], restart: [] },
  });
  await f.run();
  assertEquals(f.effects.length, 3);
});
Deno.test("a pre-activation cancellation has no Node effect", async () => {
  const f = fixture();
  f.cancel();
  assertEquals(await f.run(), { status: "cancelled" });
  assertEquals(f.effects, []);
});
Deno.test("development intent is refused before release inspection", async () => {
  const f = fixture();
  f.input.deployment.variant = "development";
  await assertRejects(() => f.run());
  assertEquals(f.effects, []);
});
Deno.test("an admitted wrong production account is refused before effects", async () => {
  const f = fixture();
  f.input.deployment.user = "nckrtl";
  await assertRejects(() => f.run(), Error, "deployment.identity_changed");
  assertEquals(f.effects, []);
});
Deno.test("an inactive restart journal fails without replaying activation", async () => {
  const f = fixture();
  f.journal.set(`rollback-${f.args.operation_id}-${f.args.run_id}`, {
    operation_id: 100,
    run_id: f.args.run_id,
    started_at: "2026-10-31T09:59:00Z",
    active: false,
    phase: "php_refresh",
  });
  await assertRejects(() => f.run(), Error, "deployment.interrupted");
  assertEquals(f.effects, []);
  assertEquals(f.posts.at(-1)?.status, "failed");
});
Deno.test("an active interrupted effect keeps the hold even when cancellation is requested", async () => {
  const f = fixture();
  f.cancel();
  f.journal.set(`rollback-${f.args.operation_id}-${f.args.run_id}`, {
    operation_id: 100,
    run_id: f.args.run_id,
    started_at: "2026-10-31T09:59:00Z",
    active: true,
    phase: "activation",
  });
  await assertRejects(() => f.run(), Error, "deployment.execution_unconfirmed");
  assertEquals(await f.failure(), { status: "held" });
  assertEquals(f.posts, []);
  assertEquals(f.effects, []);
});
Deno.test("unknown activation never projects terminal success or guesses selection", async () => {
  const f = fixture();
  const source = {
    ...f.ports.source,
    activateRelease: () => Promise.reject(new Error("transport interrupted")),
  };
  await assertRejects(
    () => f.run({ source }),
    Error,
    "deployment.execution_unconfirmed",
  );
  assertEquals(await f.failure(), { status: "held" });
  assertEquals(f.posts.map((post) => post.status), ["running"]);
});
Deno.test("a known activation failure records a fresh readback of the selected release", async () => {
  const f = fixture();
  const source = {
    ...f.ports.source,
    activateRelease: () =>
      Promise.reject(new LocalDeployError("deployment.selection_invalid")),
  };
  await assertRejects(
    () => f.run({ source }),
    Error,
    "deployment.selection_invalid",
  );
  const result = f.posts.at(-1)?.result as Record<string, unknown>;
  assertEquals(result.selected_release, selected);
  assertEquals(result.selection_confirmed, true);
});
Deno.test("a failed activation readback marks selection unknown instead of projecting the old release as confirmed", async () => {
  const f = fixture();
  let reads = 0;
  const source = {
    ...f.ports.source,
    inspectRelease: (
      ...args: Parameters<typeof f.ports.source.inspectRelease>
    ) => {
      if (++reads === 3) return Promise.reject(new Error("read unavailable"));
      return f.ports.source.inspectRelease(...args);
    },
    activateRelease: () =>
      Promise.reject(new LocalDeployError("deployment.selection_invalid")),
  };
  await assertRejects(
    () => f.run({ source }),
    Error,
    "deployment.selection_invalid",
  );
  assertEquals(
    (f.posts.at(-1)?.result as Record<string, unknown>).selection_confirmed,
    false,
  );
});
Deno.test("cancellation after activation still finishes PHP and captured Process restart", async () => {
  const f = fixture();
  f.input.runtime_definitions.restart.push(
    {
      process_id: 1,
      runtime: "systemd",
      name: "orbit-process-1-worker.service",
      on_demand: false,
      vite: null,
    },
  );
  let refreshed = false;
  const source = {
    ...f.ports.source,
    activateRelease: () => {
      f.cancel();
      return Promise.resolve(retained);
    },
  };
  const php: NonNullable<Parameters<typeof rollbackDirect>[0]>["php"] = async (
    _input,
    _deadline,
    port,
  ) => {
    assertEquals(await port.current(), { cancel_requested: false });
    refreshed = true;
    return {
      confirmed: true,
      changed: false,
      deferred_count: 0,
      stale_master_count: 0,
    };
  };
  let restarts = 0;
  assertEquals(
    await f.run({
      source,
      php,
      restart: () => {
        restarts++;
        return Promise.resolve({ ready: true, state: "running" });
      },
    }),
    { status: "succeeded" },
  );
  assertEquals(refreshed, true);
  assertEquals(restarts, 1);
});

Deno.test("rolling to the currently selected retained release is allowed without preparing another release", async () => {
  const f = fixture();
  f.selectCurrent();
  assertEquals(await f.run(), { status: "succeeded" });
  assertEquals(
    (f.posts.at(-1)?.result as Record<string, unknown>).selected_release,
    selected,
  );
  assertEquals(f.effects, [
    "inspectRelease",
    "inspectRelease",
    "activateRelease",
  ]);
});

Deno.test("a release removed after admission fails before activation", async () => {
  const f = fixture();
  const source = {
    ...f.ports.source,
    inspectRelease: (
      home: Parameters<typeof f.ports.source.inspectRelease>[0],
      name?: string,
    ) =>
      name
        ? Promise.reject(new LocalDeployError("rollback.release_invalid"))
        : f.ports.source.inspectRelease(home),
  };
  await assertRejects(
    () => f.run({ source }),
    Error,
    "rollback.release_invalid",
  );
  assertEquals(f.effects, ["inspectRelease"]);
  assertEquals(
    (f.posts.at(-1)?.result as Record<string, unknown>).selected_release,
    selected,
  );
});

Deno.test("a captured Process failure leaves the rollback release selected and reports the failed member", async () => {
  const f = fixture();
  f.input.runtime_definitions.restart.push(
    {
      process_id: 1,
      runtime: "systemd",
      name: "orbit-process-1-worker.service",
      on_demand: false,
      vite: null,
    },
  );
  await assertRejects(
    () =>
      f.run({
        restart: () =>
          Promise.resolve({ error_code: "process.restart_failed" }),
      }),
    Error,
    "process.restart_failed",
  );
  const result = f.posts.at(-1)?.result as Record<string, unknown>;
  assertEquals(result.selected_release, retained);
  assertEquals(result.failed_step, "process_restart");
  assertEquals(
    (result.runtime_definitions as Record<string, unknown>).restart,
    [{
      process_id: 1,
      error_code: "process.restart_failed",
    }],
  );
});

Deno.test("a root failure before input hydration settles without Node effects", async () => {
  const f = fixture();
  assertEquals(await f.failure(), { status: "failed" });
  assertEquals(f.effects, []);
  assertEquals(f.posts.at(-1)?.result, {
    failed_step: "native_execution",
    selection_confirmed: false,
  });
});

Deno.test("an interrupted deployment on the same model blocks a newer rollback before effects", async () => {
  const f = fixture();
  const predecessor = {
    operation_id: 99,
    run_id: "402775cd-6ab4-46d1-a92e-f84ae368fe62",
    active: true,
  };
  f.journal.set("activeExecution", predecessor);
  await assertRejects(() => f.run(), Error, "deployment.target_busy");
  assertEquals(f.effects, []);
  assertEquals(await f.failure(), { status: "held" });
  assertEquals(f.journal.get("activeExecution"), predecessor);
  assertEquals(f.posts, []);
});

Deno.test("an active shared owner without its perrun journal is never replayed", async () => {
  const f = fixture();
  f.journal.set("activeExecution", {
    operation_id: f.args.operation_id,
    run_id: f.args.run_id,
    active: true,
  });
  await assertRejects(() => f.run(), Error, "deployment.execution_unconfirmed");
  assertEquals(await f.failure(), { status: "held" });
  assertEquals(f.effects, []);
  assertEquals(f.posts, []);
});

for (const restored of [true, false, undefined]) {
  Deno.test(`PHP restoration ${restored} settles only a confirmed restored effect`, async () => {
    const f = fixture();
    await assertRejects(
      () =>
        f.run({
          php: async (_input, _deadline, port) => {
            try {
              await port.mutation!(() =>
                Promise.reject(new PhpLocalError("php.build_failed", restored))
              );
            } catch {
              return {
                error_code: restored === true
                  ? "php.build_failed"
                  : "deployment.execution_unconfirmed",
              };
            }
            throw new Error("Expected fixture failure");
          },
        }),
      Error,
      restored === true
        ? "php.build_failed"
        : "deployment.execution_unconfirmed",
    );
    assertEquals(f.journal.get("activeExecution")?.active, restored !== true);
    if (restored === true) {
      assertEquals(f.posts.at(-1)?.status, "failed");
      assertEquals(f.posts.at(-1)?.error_code, "php.build_failed");
      assertEquals(await f.failure(), { status: "failed" });
    } else {
      assertEquals(f.posts.at(-1)?.status, "running");
      assertEquals(await f.failure(), { status: "held" });
    }
  });
}
