import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  configureRoleClickhouse,
  installRoleRuntime,
  type MetricsRuntimeActions,
  type NativeRuntimeActions,
  removeRoleRuntime,
} from "./role_runtime_local.ts";
import type { SystemTransport } from "./system_command.ts";

const base = {
  node_id: 13,
  assignment_id: 28,
  agent_sha256: "a".repeat(64),
  material: { "$secret": "node.role_runtime", id: 13 },
};
const metrics = {
  ...base,
  role: "metrics",
  address: "10.44.0.13",
  targets: [],
  fleet: [],
};
const password = "synthetic-private-grafana-password";
const material = {
  withPrivateMaterial: async (
    callback: (value: Record<string, unknown>) => Promise<void>,
  ) =>
    await callback({
      runtime_material: {
        configuration: {
          "/etc/orbit/metrics/grafana/admin-password": password,
        },
        password,
      },
    }),
};
function metricsActions(
  invoke: (
    kind: "runtime" | "credential",
    input: Record<string, unknown>,
  ) => Promise<Record<string, unknown>>,
): MetricsRuntimeActions {
  return {
    runtime: (input) => invoke("runtime", input),
    credential: (input) => invoke("credential", input),
  };
}
const noSystem: SystemTransport = () =>
  Promise.reject(new Error("Unexpected system call"));
Deno.test("Metrics runtime uses native lower actions with callback-local credentials", async () => {
  const calls: Record<string, unknown>[] = [];
  const result = await installRoleRuntime(
    metrics,
    material,
    undefined,
    noSystem,
    metricsActions((kind, input) => {
      calls.push({ kind, ...input });
      return Promise.resolve(
        kind === "runtime" ? { changed: true } : { verified: true },
      );
    }),
  );
  assertEquals(result, { completed: true, changed: true });
  assertEquals(calls, [{
    kind: "runtime",
    address: metrics.address,
    action: {
      operation: "converge",
      assignment: 28,
      configuration: { "/etc/orbit/metrics/grafana/admin-password": password },
    },
  }, {
    kind: "credential",
    address: metrics.address,
    action: { operation: "verify", password },
  }]);
  assertEquals(JSON.stringify(result).includes(password), false);
});
Deno.test("Metrics credential refusal remains failure after native installation", async () => {
  assertEquals(
    await installRoleRuntime(
      metrics,
      material,
      undefined,
      noSystem,
      metricsActions((kind) =>
        Promise.resolve(
          kind === "runtime" ? { changed: false } : { verified: false },
        )
      ),
    ),
    { error_code: "metrics.credentials_unverified" },
  );
});
Deno.test("Metrics native uncertainty is bounded and cannot become completed", async () => {
  const error = await assertRejects(() =>
    installRoleRuntime(
      metrics,
      material,
      undefined,
      noSystem,
      metricsActions(() => Promise.reject(new Error(password))),
    )
  );
  assertEquals(String(error).includes(password), false);
});
Deno.test("Cancellation and foreign material refuse before private reads or system effects", async () => {
  let reads = 0, effects = 0;
  const gateway = {
    withPrivateMaterial: () => {
      reads++;
      return Promise.resolve();
    },
  };
  const actions = metricsActions(() => {
    effects++;
    return Promise.resolve({});
  });
  await assertRejects(() =>
    installRoleRuntime(metrics, gateway, AbortSignal.abort(), noSystem, actions)
  );
  await assertRejects(() =>
    installRoleRuntime(
      { ...metrics, material: { ...base.material, id: 209 } },
      gateway,
      undefined,
      noSystem,
      actions,
    )
  );
  assertEquals({ reads, effects }, { reads: 0, effects: 0 });
});
const installation = {
  process_id: 91,
  agent_sha256: base.agent_sha256,
  runtime: "docker",
  name: "orbit-process-91-plausible",
  desired: "running",
  on_demand: false,
  retired: null,
  spec: "b".repeat(64),
};
const analytics = {
  ...base,
  role: "analytics",
  installation,
  health_url: "http://10.44.0.13:8000/api/health",
  clickhouse: { node_id: 9, agent_sha256: base.agent_sha256, process_id: 92 },
};
const environment = { SECRET_KEY_BASE: "synthetic-private-plausible-key" };
function analyticsMaterial(patch: Record<string, unknown> = {}) {
  return {
    installation: {
      runtime: "docker",
      spec: installation.spec,
      definition: {
        image: "ghcr.io/plausible/community-edition:v3.2.1",
        command: ["/entrypoint.sh", "run"],
        restart: "unless-stopped",
        working_directory: null,
        ports: ["10.44.0.13:8000:8000/tcp"],
        mounts: [],
        environment,
      },
      ...patch,
    },
  };
}
Deno.test("Analytics performs initialization then native Process activation/readiness entirely inside its callback", async () => {
  const calls: { name: string; input: Record<string, unknown> }[] = [];
  let opened = false;
  const native: NativeRuntimeActions = {
    process: async (name, input, _signal, before) => {
      assertEquals(opened, true);
      calls.push({ name, input });
      if (name === "process.converge-container") {
        const initialized = await before!();
        assertEquals(initialized, { ready: true });
        return {};
      }
      return { state: "running" };
    },
    initialize: (desired, env, action, signal, run) => {
      assertEquals(opened, true);
      assertEquals(env, environment);
      assertEquals(desired.command, ["/entrypoint.sh", "run"]);
      assertEquals(action, native.process);
      assertEquals(signal, undefined);
      assertEquals(typeof run, "function");
      calls.push({ name: "initialize", input: {} });
      return Promise.resolve({ ready: true });
    },
  };
  const result = await installRoleRuntime(
    analytics,
    {
      withPrivateMaterial: async (callback) => {
        opened = true;
        try {
          await callback({ runtime_material: analyticsMaterial() });
        } finally {
          opened = false;
        }
      },
    },
    undefined,
    noSystem,
    undefined,
    native,
  );
  assertEquals(result, { completed: true });
  assertEquals(calls.map((call) => call.name), [
    "process.converge-container",
    "initialize",
    "process.status",
  ]);
  assertEquals(calls[0].input.command, ["/entrypoint.sh", "run"]);
  assertEquals(calls[0].input.environment, environment);
  assertEquals(calls[0].input.health, {
    url: analytics.health_url,
    attempts: 90,
    interval_seconds: 2,
  });
  assertEquals(
    JSON.stringify(result).includes(environment.SECRET_KEY_BASE),
    false,
  );
});
Deno.test("Analytics rejects spec drift and agent service commands before system effects", async () => {
  for (
    const patch of [{ spec: "c".repeat(64) }, {
      definition: {
        ...(analyticsMaterial().installation.definition),
        command: ["/usr/local/bin/orbit-agent", "service", "plausible"],
      },
    }]
  ) {
    let effects = 0;
    const native: NativeRuntimeActions = {
      process: () => {
        effects++;
        return Promise.resolve({});
      },
      initialize: () => Promise.resolve({ ready: true }),
    };
    await assertRejects(
      () =>
        installRoleRuntime(
          analytics,
          {
            withPrivateMaterial: async (callback) =>
              await callback({ runtime_material: analyticsMaterial(patch) }),
          },
          undefined,
          noSystem,
          undefined,
          native,
        ),
      Error,
      "Private Analytics installation did not complete",
    );
    assertEquals(effects, 0);
  }
});
Deno.test("Analytics initialization/readiness refusal cannot report runtime completion", async () => {
  for (
    const code of ["analytics.initialization_failed", "process.health_failed"]
  ) {
    const calls: string[] = [];
    const native: NativeRuntimeActions = {
      process: (name) => {
        calls.push(name);
        return Promise.resolve({ error_code: code });
      },
      initialize: () => Promise.resolve({ ready: true }),
    };
    assertEquals(
      await installRoleRuntime(
        analytics,
        {
          withPrivateMaterial: async (callback) =>
            await callback({ runtime_material: analyticsMaterial() }),
        },
        undefined,
        noSystem,
        undefined,
        native,
      ),
      { error_code: code },
    );
    assertEquals(calls, ["process.converge-container"]);
  }
});
Deno.test("Analytics requires a running native Process after activation and bounds private exceptions", async () => {
  for (const state of ["stopped", "unknown"]) {
    const native: NativeRuntimeActions = {
      process: (name) =>
        Promise.resolve(name === "process.status" ? { state } : {}),
      initialize: () => Promise.resolve({ ready: true }),
    };
    assertEquals(
      await installRoleRuntime(
        analytics,
        {
          withPrivateMaterial: async (callback) =>
            await callback({ runtime_material: analyticsMaterial() }),
        },
        undefined,
        noSystem,
        undefined,
        native,
      ),
      { error_code: "analytics.runtime_not_ready" },
    );
  }
  const native: NativeRuntimeActions = {
    process: () => Promise.reject(new Error(environment.SECRET_KEY_BASE)),
    initialize: () => Promise.resolve({ ready: true }),
  };
  const error = await assertRejects(() =>
    installRoleRuntime(
      analytics,
      {
        withPrivateMaterial: async (callback) =>
          await callback({ runtime_material: analyticsMaterial() }),
      },
      undefined,
      noSystem,
      undefined,
      native,
    )
  );
  assertEquals(String(error).includes(environment.SECRET_KEY_BASE), false);
});
Deno.test("Runtime removal calls native Process teardown and preserves Metrics purge intent", async () => {
  const calls: Record<string, unknown>[] = [];
  const native: NativeRuntimeActions = {
    process: (name, input) => {
      calls.push({ name, input });
      return Promise.resolve({});
    },
    initialize: () => Promise.resolve({ ready: true }),
  };
  assertEquals(
    await removeRoleRuntime(
      {
        role: "analytics",
        node_id: 13,
        agent_sha256: base.agent_sha256,
        process_id: 91,
        name: installation.name,
        purge_data: true,
      },
      undefined,
      noSystem,
      undefined,
      native,
    ),
    { completed: true },
  );
  assertEquals(calls, [{
    name: "process.remove",
    input: {
      process_id: 91,
      runtime: "docker",
      name: installation.name,
      vite: null,
    },
  }]);
  const inputs: Record<string, unknown>[] = [];
  assertEquals(
    await removeRoleRuntime(
      {
        role: "metrics",
        node_id: 13,
        agent_sha256: base.agent_sha256,
        address: metrics.address,
        purge_data: false,
      },
      undefined,
      noSystem,
      metricsActions((_kind, input) => {
        inputs.push(input);
        return Promise.resolve({ changed: true });
      }),
    ),
    { completed: true, changed: true },
  );
  assertEquals(inputs, [{
    address: metrics.address,
    action: { operation: "remove", purge_data: false },
  }]);
});
Deno.test("ClickHouse placed helper validates its admitted identity before native configuration and has bounded errors", async () => {
  let effects = 0;
  const transport: SystemTransport = () => {
    effects++;
    return Promise.resolve({
      success: false,
      stdout: "synthetic-private-diagnostic",
    });
  };
  await assertRejects(() =>
    configureRoleClickhouse(
      { ...analytics.clickhouse, process_id: 0 },
      transport,
    )
  );
  await assertRejects(() =>
    configureRoleClickhouse(
      analytics.clickhouse,
      transport,
      AbortSignal.abort(),
    )
  );
  assertEquals(effects, 0);
  const result = await configureRoleClickhouse(analytics.clickhouse, transport);
  assertEquals("error_code" in result, true);
  assertEquals(
    JSON.stringify(result).includes("synthetic-private-diagnostic"),
    false,
  );
  assertEquals(effects > 0, true);
});

const websocket = {
  ...base,
  role: "websocket",
  user: "orbit",
  install_path: "/opt/orbit/websocket",
  repository: "https://github.com/hardimpactdev/orbit-reverb.git",
  ref: "a".repeat(40),
  port: 8080,
};
const websocketMaterial = { websocket_environment: "APP_KEY=fixture-only\n" };

Deno.test("role parent keeps WebSocket configuration inside one private-data boundary", async () => {
  let opened = false, reads = 0, effects = 0;
  const gateway = {
    withPrivateMaterial: async (
      use: (data: Record<string, unknown>) => Promise<void>,
    ) => {
      reads++;
      opened = true;
      try {
        await use({ runtime_material: websocketMaterial });
      } finally {
        opened = false;
      }
    },
  };
  const transport: SystemTransport = (command) => {
    assertEquals(opened, true);
    if (command.command === "/usr/bin/python3") {
      return Promise.resolve({
        success: true,
        stdout: '{"user":"orbit","group":"orbit","home":"/home/orbit"}',
      });
    }
    effects++;
    assertEquals(
      JSON.parse(command.stdin!).environment,
      websocketMaterial.websocket_environment,
    );
    return Promise.resolve({
      success: true,
      stdout: JSON.stringify({
        release: "/opt/orbit/websocket.releases/release." + "b".repeat(32),
      }),
    });
  };
  assertEquals(
    await installRoleRuntime(websocket, gateway, undefined, transport),
    { completed: true },
  );
  assertEquals({ opened, reads, effects }, {
    opened: false,
    reads: 1,
    effects: 1,
  });
});

Deno.test("WebSocket cancellation while parent data resolves precedes runtime effects", async () => {
  const abort = new AbortController();
  let reads = 0, effects = 0;
  assertEquals(
    await installRoleRuntime(
      websocket,
      {
        withPrivateMaterial: async (use) => {
          reads++;
          abort.abort();
          await use({ runtime_material: websocketMaterial });
        },
      },
      abort.signal,
      () => {
        effects++;
        throw new Error("No runtime effect expected");
      },
    ),
    { error_code: "operation.cancelled" },
  );
  assertEquals({ reads, effects }, { reads: 1, effects: 0 });
});
