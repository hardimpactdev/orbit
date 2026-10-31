import {
  assertEquals,
  assertFalse,
  assertRejects,
} from "jsr:@std/assert@1.0.19";
import {
  type EnvironmentInvoke,
  EnvironmentRefusal,
  refreshEnvironmentRuntime,
  type RuntimeActions,
  synchronizeEnvironment,
} from "./sync.ts";
import { instanceActions } from "../processes-schedules/instance.ts";
import { environmentRoot } from "./mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
const plan = {
  instance_id: 31,
  node_id: 9,
  environment: "development",
  base: "/fast/apps/example/default",
  user: "nckrtl",
  maximum: 1048576,
  required_capacity: 2097152,
  preserve_workload_keys: true,
  runtime: null,
};
const material = {
  content: 'API_TOKEN="synthetic-secret"\n',
  keys: ["API_TOKEN"],
  owned_keys: ["OLD"],
  testing: null,
};
function fixture() {
  const calls: Parameters<EnvironmentInvoke>[0][] = [];
  const f = {
    existing: "API_TOKEN=old\nOLD=value\n",
    testState: "changed",
    writeState: "changed",
    calls,
    body: structuredClone(material) as Record<string, unknown>,
    invoke: undefined as unknown as EnvironmentInvoke,
  };
  f.invoke = async (call) => {
    await Promise.resolve();
    calls.push(call);
    if (call.name === "instance.inspect-environment-sync") {
      return {
        preflight: { state: "ok" },
        existing: { state: "ok", content: btoa(f.existing) },
      };
    }
    if (call.name === "instance.write-environment") {
      return {
        state: call.input.file === ".env.testing" ? f.testState : f.writeState,
      };
    }
    return { ready: true };
  };
  return {
    ...f,
    get use() {
      return async (
        callback: Parameters<Parameters<typeof synchronizeEnvironment>[1]>[0],
      ) => await callback({ environment_material: f.body });
    },
    get run() {
      return () =>
        synchronizeEnvironment(
          plan,
          async (callback) => await callback({ environment_material: f.body }),
          f.invoke,
        );
    },
    f,
  };
}
Deno.test("environment native action keeps private bytes out of outputs and uses fixed local commands", async () => {
  const f = fixture();
  const result = await f.run();
  assertEquals(result, {
    instance_id: 31,
    changed: true,
    environment_changed: true,
    keys: ["API_TOKEN"],
    key_count: 1,
    testing_status: null,
    test_database: null,
  });
  assertFalse(JSON.stringify(result).includes("synthetic-secret"));
  assertEquals(f.calls.map((c) => c.name), [
    "instance.inspect-environment-sync",
    "instance.write-environment",
  ]);
  assertEquals(
    f.calls.every((c) => Object.keys(c).sort().join() === "input,name"),
    true,
  );
});
Deno.test("unknown workload keys refuse before the first write", async () => {
  const f = fixture();
  f.f.existing = "API_TOKEN=old\nUNIMPORTED=keep\n";
  await assertRejects(f.run, EnvironmentRefusal);
  assertEquals(f.calls.length, 1);
});
Deno.test("native create and clone environment steps use admitted replacement policy", async () => {
  const f = fixture();
  f.f.existing = "EXAMPLE_ONLY=value\n";
  const result = await synchronizeEnvironment(
    { ...plan, preserve_workload_keys: false },
    f.use,
    f.f.invoke,
  );
  assertEquals(result.changed, true);
});
Deno.test("a missing environment file is created", async () => {
  const calls: string[] = [];
  const invoke: EnvironmentInvoke = async (call) => {
    await Promise.resolve();
    calls.push(call.name);
    return call.name === "instance.inspect-environment-sync"
      ? { preflight: { state: "ok" }, existing: { state: "missing" } }
      : { state: "unchanged" };
  };
  assertEquals(
    (await synchronizeEnvironment(plan, fixture().use, invoke)).changed,
    false,
  );
  assertEquals(calls.length, 2);
});
Deno.test("testing merge follows main write and a tracked testing file is preserved", async () => {
  const f = fixture();
  f.f.body = {
    ...material,
    testing: {
      content: 'DB_DATABASE="test"\n',
      managed_keys: ["DB_DATABASE"],
      test_database: "test",
    },
  };
  f.f.testState = "tracked";
  const result = await f.run();
  assertEquals(result.testing_status, "skipped_tracked");
  assertEquals(
    f.calls.filter((c) => c.name === "instance.write-environment").map(
      (c) => [c.input.mode, c.input.file],
    ),
    [["write", ".env"], ["merge", ".env.testing"]],
  );
});
Deno.test("unconfirmed environment writes stop before testing and sanitize the refusal", async () => {
  const f = fixture();
  f.f.writeState = "failed";
  await assertRejects(
    f.run,
    EnvironmentRefusal,
    "Instance environment action refused",
  );
  assertEquals(f.calls.length, 2);
});
Deno.test("oversized raw content refuses before local preflight", async () => {
  const f = fixture();
  f.f.body = { ...material, content: "é".repeat(524289) };
  await assertRejects(f.run, EnvironmentRefusal);
  assertEquals(f.calls.length, 0);
});
Deno.test("missing private callback does not claim synchronization", async () => {
  await assertRejects(
    () => synchronizeEnvironment(plan, () => Promise.resolve()),
    EnvironmentRefusal,
  );
});
Deno.test("late private callback cannot invoke a Node action", async () => {
  const f = fixture();
  let delayed:
    | Parameters<Parameters<typeof synchronizeEnvironment>[1]>[0]
    | undefined;
  await assertRejects(() =>
    synchronizeEnvironment(plan, (callback) => {
      delayed = callback;
      return Promise.resolve();
    }, f.f.invoke), EnvironmentRefusal);
  await assertRejects(
    () => delayed!({ environment_material: material }),
    Error,
  );
  assertEquals(f.calls.length, 0);
});
Deno.test("native environment root projects only bounded outcome without children or executor RPC", async () => {
  const calls: string[] = [];
  const args = {
    operation_id: 17,
    kind: "instance.sync_environment" as const,
    node_id: 9,
    run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
  };
  const http: Http = async (_method, path) => {
    await Promise.resolve();
    calls.push(path);
    return {
      status: 200,
      body: {
        data: {
          id: 17,
          kind: args.kind,
          node_id: 9,
          status: "queued",
          cancel_requested: false,
          input: { instance_id: 31, direct_environment: plan },
          result: null,
        },
      },
    };
  };
  const root = environmentRoot(
    () => http,
    () =>
      Promise.resolve({ instance_id: 31, changed: false, keys: ["API_TOKEN"] }),
    () =>
      Promise.resolve({
        runtime_refresh: "skipped_development",
        restarted_process_ids: [],
      }),
  );
  assertEquals(
    await root.methods.apply.execute(args, {
      globalArgs: {
        gatewayUrl: "https://gateway.orbit",
        caFile: "/test/ca.pem",
      },
    }),
    { status: "succeeded" },
  );
  assertEquals(calls, [
    `/api/v1/direct-operations/17?run_id=${args.run_id}`,
    "/api/v1/direct-operations/17/result",
    "/api/v1/direct-operations/17/result",
  ]);
});

function production() {
  return {
    ...plan,
    environment: "production",
    runtime: {
      config: {
        user: plan.user,
        home: plan.base,
        version: "8.5",
        application: plan.base + "/current",
      },
      php: {
        user: plan.user,
        home: plan.base,
        version: "8.5",
        marker: "owned",
        deadline_seconds: 30,
      },
      processes: [{
        id: 4,
        input: {
          process_id: 4,
          agent_sha256: "a".repeat(64),
          runtime: "systemd",
          name: "orbit-process-4-worker.service",
          on_demand: false,
          vite: null,
        },
      }],
    },
  };
}
function runtimeFixture() {
  const calls: string[] = [];
  const f = {
    calls,
    config: "config_cached" as
      | "config_cached"
      | "config_uncached"
      | "no_current_release",
    fail: "",
  };
  const actions: RuntimeActions = {
    config: () => {
      calls.push("config");
      return f.fail === "config"
        ? Promise.reject(new Error("synthetic-secret"))
        : Promise.resolve({ outcome: f.config });
    },
    php: () => {
      calls.push("php");
      return f.fail === "php"
        ? Promise.reject(new Error("synthetic-secret"))
        : Promise.resolve({ complete: true });
    },
    processes: instanceActions((name, input) => {
      calls.push(
        name + (name === "process.control" ? ":" + input.operation : ""),
      );
      return Promise.resolve(
        f.fail === "process"
          ? { error_code: "process.control_failed" }
          : { state: "active" },
      );
    }),
  };
  return { f, actions };
}
Deno.test("production config, OPcache and canonical Process restart execute in order", async () => {
  const { f, actions } = runtimeFixture();
  assertEquals(await refreshEnvironmentRuntime(production(), actions), {
    runtime_refresh: "config_cache_and_opcache_refreshed",
    restarted_process_ids: [4],
  });
  assertEquals(f.calls, [
    "config",
    "php",
    "process.control:restart",
    "process.status",
  ]);
});
Deno.test("uncached production stays uncached while OPcache and Processes refresh", async () => {
  const { f, actions } = runtimeFixture();
  f.config = "config_uncached";
  assertEquals(
    (await refreshEnvironmentRuntime(production(), actions)).runtime_refresh,
    "opcache_refreshed_config_uncached",
  );
  assertEquals(f.calls, [
    "config",
    "php",
    "process.control:restart",
    "process.status",
  ]);
});
Deno.test("missing production release skips OPcache and Process changes", async () => {
  const { f, actions } = runtimeFixture();
  f.config = "no_current_release";
  assertEquals(await refreshEnvironmentRuntime(production(), actions), {
    runtime_refresh: "skipped_no_current_release",
    restarted_process_ids: [],
  });
  assertEquals(f.calls, ["config"]);
});
Deno.test("runtime failure stops later effects and never exposes application output", async () => {
  for (
    const [stage, code, calls] of [
      ["config", "env.config_cache_refresh_failed", ["config"]],
      ["php", "env.php_runtime_refresh_failed", ["config", "php"]],
      ["process", "env.runtime_refresh_failed", [
        "config",
        "php",
        "process.control:restart",
      ]],
    ] as const
  ) {
    const { f, actions } = runtimeFixture();
    f.fail = stage;
    const error = await assertRejects(
      () => refreshEnvironmentRuntime(production(), actions),
      EnvironmentRefusal,
    );
    assertEquals(error.code, code);
    assertEquals(f.calls, [...calls]);
    assertFalse(error.message.includes("synthetic-secret"));
  }
});
Deno.test("a production Process restart does not require a PHP runtime", async () => {
  const { f, actions } = runtimeFixture();
  const input = production();
  assertEquals(
    await refreshEnvironmentRuntime({
      ...input,
      runtime: { ...input.runtime, config: null, php: null },
    }, actions),
    {
      runtime_refresh: "skipped_no_php_version",
      restarted_process_ids: [4],
    },
  );
  assertEquals(f.calls, ["process.control:restart", "process.status"]);
});
Deno.test("substituted runtime ownership refuses before private environment access", async () => {
  const input = production();
  let reads = 0;
  await assertRejects(() =>
    synchronizeEnvironment({
      ...input,
      runtime: {
        ...input.runtime,
        config: { ...input.runtime.config, user: "other" },
      },
    }, () => {
      reads++;
      return Promise.resolve();
    })
  );
  assertEquals(reads, 0);
});
Deno.test("production runtime failure after file writes reports a failed root and bounded stage", async () => {
  const callbacks: Record<string, unknown>[] = [];
  const input = production();
  const http: Http = (_method, path, body) => {
    if (path.endsWith("/result")) {
      callbacks.push(body as Record<string, unknown>);
    }
    return Promise.resolve({
      status: 200,
      body: {
        data: {
          id: 17,
          kind: "instance.sync_environment",
          node_id: 9,
          status: "queued",
          cancel_requested: false,
          input: { instance_id: 31, direct_environment: input },
          result: null,
        },
      },
    });
  };
  const { f, actions } = runtimeFixture();
  f.fail = "config";
  let written = false;
  const root = environmentRoot(
    () => http,
    () => {
      written = true;
      return Promise.resolve({ changed: true, keys: ["API_TOKEN"] });
    },
    (raw, _actions, signal) => refreshEnvironmentRuntime(raw, actions, signal),
  );
  await assertRejects(
    () =>
      root.methods.apply.execute({
        operation_id: 17,
        kind: "instance.sync_environment",
        node_id: 9,
        run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
      }, {
        globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/unused" },
      }),
    Error,
    "env.config_cache_refresh_failed",
  );
  assertEquals(written, true);
  assertEquals(callbacks.at(-1)?.error_code, "env.config_cache_refresh_failed");
  assertEquals(callbacks.at(-1)?.result, {
    error_code: "env.config_cache_refresh_failed",
    failed_step: "config_cache",
  });
  assertEquals(f.calls, ["config"]);
});
