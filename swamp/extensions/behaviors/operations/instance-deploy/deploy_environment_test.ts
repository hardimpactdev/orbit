import { assertEquals } from "jsr:@std/assert@1.0.19";
import type { EnvironmentAccessInput } from "./environment_local.ts";
import { synchronizeDeployEnvironment } from "./deploy_environment.ts";

function fixture() {
  const calls: EnvironmentAccessInput[] = [];
  const input = {
    environment: {
      base: "/home/orbit-app-9",
      user: "orbit-app-9",
      environment: "production" as const,
      domain: "example.test",
    },
  };
  const material = {
    ...input.environment,
    required_capacity: 2048,
    values: {
      APP_ENV: "{{instance.environment}}",
      TOKEN: "private-test-value",
    },
    owned_keys: ["RETIRED"],
    testing: {
      values: { APP_ENV: "testing", DB_DATABASE: "testdb" },
      managed_keys: ["DB_DATABASE"],
    },
  };
  const port = {
    current: () => Promise.resolve({ cancel_requested: false }),
    material: async (action: (value: unknown) => Promise<void>) => {
      await action(material);
    },
    now: () => 1000,
    access: (
      call: EnvironmentAccessInput,
    ): Promise<Record<string, unknown>> => {
      calls.push(call);
      return Promise.resolve(
        call.mode === "inspect"
          ? {
            preflight: { state: "ok", installed: false },
            existing: {
              state: "ok",
              content: btoa('RETIRED="old"\n'),
              installed: false,
            },
          }
          : { state: "changed", installed: true },
      );
    },
  };
  return { input, material, port, calls };
}

Deno.test("deploy environment inspects locally then writes private main and managed testing files", async () => {
  const f = fixture();
  const result = await synchronizeDeployEnvironment(f.input, 100000, f.port);
  assertEquals(result, {
    changed: true,
    synchronized_keys: ["APP_ENV", "TOKEN"],
    testing_status: "changed",
  });
  assertEquals(f.calls.map((c) => c.mode), [
    "inspect",
    "write",
    "merge",
  ]);
  assertEquals(
    f.calls[1].content,
    'APP_ENV="production"\nTOKEN="private-test-value"\n',
  );
  assertEquals(f.calls[1].mode, "write");
  assertEquals(f.calls[2].mode, "merge");
  assertEquals(f.calls[2].file, ".env.testing");
  assertEquals(f.calls[2].managed_keys, ["DB_DATABASE"]);
  assertEquals(f.calls.every((c) => c.user === "orbit-app-9"), true);
  assertEquals(JSON.stringify(result).includes("private-test-value"), false);
});

Deno.test("deploy refuses dropping unowned workload keys before any environment write", async () => {
  const f = fixture(), access = f.port.access;
  f.port.access = (call) =>
    call.mode === "inspect"
      ? Promise.resolve({
        preflight: { state: "ok", installed: false },
        existing: {
          state: "ok",
          installed: false,
          content: btoa("export UNKNOWN = private-workload-value\n"),
        },
      })
      : access(call);
  assertEquals(await synchronizeDeployEnvironment(f.input, 100000, f.port), {
    error_code: "env.sync_would_drop_keys",
  });
  assertEquals(f.calls.length, 0);
});

Deno.test("missing main file and tracked testing preserve native merge semantics", async () => {
  const f = fixture(), access = f.port.access;
  f.port.access = (call) =>
    call.mode === "inspect"
      ? Promise.resolve({
        preflight: { state: "ok", installed: false },
        existing: { state: "missing", installed: false },
      })
      : call.file === ".env.testing"
      ? Promise.resolve({ state: "tracked", installed: false })
      : access(call);
  assertEquals(await synchronizeDeployEnvironment(f.input, 100000, f.port), {
    changed: true,
    synchronized_keys: ["APP_ENV", "TOKEN"],
    testing_status: "tracked",
  });
});

Deno.test("changed private target and unresolved domain refuse without local effects", async () => {
  for (const fault of ["target", "reference", "empty"] as const) {
    const f = fixture();
    if (fault === "target") f.material.base = "/home/foreign";
    if (fault === "reference") f.material.values.APP_ENV = "{{unavailable}}";
    if (fault === "empty") f.material.values = {} as typeof f.material.values;
    assertEquals(await synchronizeDeployEnvironment(f.input, 100000, f.port), {
      error_code: fault === "target"
        ? "deployment.identity_changed"
        : fault === "reference"
        ? "env.reference_unavailable"
        : "env.configuration_missing",
    });
    assertEquals(f.calls.length, 0);
  }
});

Deno.test("cancel, expired deadline and missing private callback refuse with no effects", async () => {
  for (const fault of ["cancel", "deadline", "material"] as const) {
    const f = fixture();
    if (fault === "cancel") {
      f.port.current = () => Promise.resolve({ cancel_requested: true });
    }
    if (fault === "material") f.port.material = () => Promise.resolve();
    assertEquals(
      await synchronizeDeployEnvironment(
        f.input,
        fault === "deadline" ? 999 : 100000,
        f.port,
      ),
      {
        error_code: fault === "cancel"
          ? "operation.cancelled"
          : fault === "deadline"
          ? "deployment.deadline_exceeded"
          : "env.sync_unconfirmed",
      },
    );
    assertEquals(f.calls.length, 0);
  }
});

Deno.test("failed inspect, unconfirmed write and private errors return bounded codes only", async () => {
  for (const fault of ["inspect", "write", "throw", "tracking"] as const) {
    const f = fixture(), access = f.port.access;
    f.port.access = (call) => {
      if (fault === "throw") throw new Error("private-test-value");
      if (
        call.mode === "inspect" && fault === "inspect"
      ) {
        return Promise.resolve({
          preflight: { state: "refused", installed: false },
          existing: null,
        });
      }
      if (call.mode !== "inspect" && fault === "write") {
        return Promise.resolve({ state: "failed", installed: true });
      }
      if (call.file === ".env.testing" && fault === "tracking") {
        return Promise.resolve({ state: "tracking_unknown", installed: false });
      }
      return access(call);
    };
    const result = await synchronizeDeployEnvironment(f.input, 100000, f.port);
    assertEquals(result, {
      error_code: fault === "tracking"
        ? "env.testing_tracking_unknown"
        : "env.sync_unconfirmed",
    });
    assertEquals(JSON.stringify(result).includes("private-test-value"), false);
  }
});

Deno.test("private environment delivery cannot repeat writes or access after delivery closes", async () => {
  const f = fixture();
  f.port.material = async (use) => {
    await use(f.material);
    await use(f.material);
  };
  assertEquals(
    "error_code" in await synchronizeDeployEnvironment(f.input, 100000, f.port),
    false,
  );
  assertEquals(f.calls.filter((call) => call.mode !== "inspect").length, 2);
  f.calls.length = 0;
  let retained: ((value: unknown) => Promise<void>) | undefined;
  f.port.material = (use) => {
    retained = use;
    return Promise.resolve();
  };
  assertEquals(await synchronizeDeployEnvironment(f.input, 100000, f.port), {
    error_code: "env.sync_unconfirmed",
  });
  await retained!(f.material);
  assertEquals(f.calls, []);
});

Deno.test("environment delivery failure after a local write preserves the local outcome", async () => {
  const f = fixture();
  f.port.material = async (use) => {
    await use(f.material);
    throw new Error("private transport diagnostic");
  };
  assertEquals(await synchronizeDeployEnvironment(f.input, 100000, f.port), {
    changed: true,
    synchronized_keys: ["APP_ENV", "TOKEN"],
    testing_status: "changed",
  });
});

Deno.test("lost environment write reply is uncertain and cannot be replayed by the private callback", async () => {
  const f = fixture(), access = f.port.access;
  f.port.access = (call) => {
    if (call.mode === "inspect") return access(call);
    f.calls.push(call);
    throw new Error("private native diagnostic");
  };
  f.port.material = async (use) => {
    await use(f.material);
    await use(f.material);
  };
  assertEquals(await synchronizeDeployEnvironment(f.input, 100000, f.port), {
    error_code: "deployment.execution_unconfirmed",
  });
  assertEquals(f.calls.filter((call) => call.mode !== "inspect").length, 1);
});
