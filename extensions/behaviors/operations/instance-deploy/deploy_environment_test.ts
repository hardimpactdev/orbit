import { assertEquals } from "jsr:@std/assert@1.0.19";
import type { NodeCall } from "../_lib/node-agent.ts";
import { synchronizeDeployEnvironment } from "./deploy_environment.ts";

function fixture() {
  const calls: NodeCall[] = [];
  const input = {
    agent_sha256: "a".repeat(64),
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
    invoke: (call: NodeCall): Promise<Record<string, unknown>> => {
      calls.push(call);
      return Promise.resolve(
        call.name === "instance.inspect-environment-sync"
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
  assertEquals(f.calls.map((c) => c.name), [
    "instance.inspect-environment-sync",
    "instance.write-environment",
    "instance.write-environment",
  ]);
  assertEquals(
    f.calls[1].input.content,
    'APP_ENV="production"\nTOKEN="private-test-value"\n',
  );
  assertEquals(f.calls[1].input.mode, "write");
  assertEquals(f.calls[2].input.mode, "merge");
  assertEquals(f.calls[2].input.file, ".env.testing");
  assertEquals(f.calls[2].input.managed_keys, ["DB_DATABASE"]);
  assertEquals(f.calls.every((c) => c.sudo), true);
  assertEquals(JSON.stringify(result).includes("private-test-value"), false);
});

Deno.test("deploy refuses dropping unowned workload keys before any environment write", async () => {
  const f = fixture(), invoke = f.port.invoke;
  f.port.invoke = (call) =>
    call.name === "instance.inspect-environment-sync"
      ? Promise.resolve({
        preflight: { state: "ok", installed: false },
        existing: {
          state: "ok",
          installed: false,
          content: btoa("export UNKNOWN = private-workload-value\n"),
        },
      })
      : invoke(call);
  assertEquals(await synchronizeDeployEnvironment(f.input, 100000, f.port), {
    error_code: "env.sync_would_drop_keys",
  });
  assertEquals(f.calls.length, 0);
});

Deno.test("missing main file and tracked testing preserve native merge semantics", async () => {
  const f = fixture(), invoke = f.port.invoke;
  f.port.invoke = (call) =>
    call.name === "instance.inspect-environment-sync"
      ? Promise.resolve({
        preflight: { state: "ok", installed: false },
        existing: { state: "missing", installed: false },
      })
      : call.input.file === ".env.testing"
      ? Promise.resolve({ state: "tracked", installed: false })
      : invoke(call);
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
    const f = fixture(), invoke = f.port.invoke;
    f.port.invoke = (call) => {
      if (fault === "throw") throw new Error("private-test-value");
      if (
        call.name === "instance.inspect-environment-sync" && fault === "inspect"
      ) {
        return Promise.resolve({
          preflight: { state: "refused", installed: false },
          existing: null,
        });
      }
      if (call.name === "instance.write-environment" && fault === "write") {
        return Promise.resolve({ state: "failed", installed: true });
      }
      if (call.input.file === ".env.testing" && fault === "tracking") {
        return Promise.resolve({ state: "tracking_unknown", installed: false });
      }
      return invoke(call);
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
