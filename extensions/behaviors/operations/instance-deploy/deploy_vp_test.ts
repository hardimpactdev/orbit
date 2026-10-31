import { assertEquals } from "jsr:@std/assert@1.0.19";
import type { NodeCall } from "../_lib/node-agent.ts";
import type { VpRuntime } from "./vp_requirements.ts";
import { prepareDeployVp } from "./deploy_vp.ts";

function fixture(installed = true) {
  const calls: NodeCall[] = [], references: string[] = [];
  const input = {
    agent_sha256: "a".repeat(64),
    path: "/home/orbit/apps/default",
    user: "orbit",
    node_user: "orbit",
    materialize: { user: "orbit" },
  };
  const configuration = {
    package: {
      dependencies: { "vite-plus": "^0.3.0" },
      packageManager: "npm@11.12.0",
    },
    ".node-version": "24.22.0",
    locks: {
      "package-lock.json":
        '{"packages":{"node_modules/vite-plus":{"version":"0.3.3","engines":{"node":">=24"}}}}',
    },
    npmrc: "//registry.example.test/:_authToken=private-registry-token",
  };
  let inspections = 0;
  const port = {
    invoke: (call: NodeCall) => {
      calls.push(call);
      const results: Record<string, Record<string, unknown>> = {
        "tool.read-vp-project": configuration,
        "tool.materialize-vp": { changed: false },
        "tool.inspect-vp-scope": { binary: "/opt/orbit/vite-plus/bin/vp" },
        "tool.vp-version": { version: "vp 1.4.0" },
        "tool.install-vp-environment": { verified: {} },
      };
      return Promise.resolve(
        call.name === "tool.inspect-vp-store"
          ? { installed: [installed || inspections++ > 0] }
          : results[call.name],
      );
    },
    current: () => Promise.resolve({ cancel_requested: false }),
    reference: (
      _runtime: VpRuntime,
      status: "checking" | "active" | "failed",
    ) => {
      references.push(status);
      return Promise.resolve();
    },
    now: () => 1000,
  };
  return { input, port, calls, configuration, references };
}

Deno.test("native deploy Vite+ uses local prerequisites and projects only public runtime pins", async () => {
  for (const installed of [true, false]) {
    const f = fixture(installed);
    const result = await prepareDeployVp(f.input, 901000, f.port);
    assertEquals("runtime" in result, true);
    assertEquals(f.references, ["checking", "active"]);
    assertEquals(
      f.calls.filter((x) => x.name === "tool.install-vp-environment").length,
      installed ? 0 : 1,
    );
    assertEquals(
      JSON.stringify(result).includes("private-registry-token"),
      false,
    );
    assertEquals(f.calls.filter((x) => x.mutates).every((x) => x.sudo), true);
    assertEquals(
      f.calls.find((x) => x.name === "tool.inspect-vp-scope")?.sudo,
      false,
    );
  }
});

Deno.test("a Project without Vite+ triggers no manager or runtime effects", async () => {
  const f = fixture();
  f.configuration.package.dependencies =
    {} as typeof f.configuration.package.dependencies;
  assertEquals(await prepareDeployVp(f.input, 901000, f.port), {
    runtime: null,
  });
  assertEquals(f.calls.map((x) => x.name), ["tool.read-vp-project"]);
  assertEquals(f.references, []);
});

Deno.test("runtime activation requires matching account, compatible manager and verified installed bytes", async () => {
  for (const fault of ["user", "version", "verify"] as const) {
    const f = fixture(false), original = f.port.invoke;
    if (fault === "user") f.input.materialize.user = "foreign";
    f.port.invoke = (call) =>
      call.name === "tool.vp-version" && fault === "version"
        ? Promise.resolve({ version: "0.1.0" })
        : call.name === "tool.inspect-vp-store" && fault === "verify"
        ? Promise.resolve({ installed: [false] })
        : original(call);
    const result = await prepareDeployVp(f.input, 901000, f.port);
    assertEquals(result, {
      error_code: fault === "user"
        ? "deployment.identity_changed"
        : "deployment.vp_store_failed",
    });
    assertEquals(f.references.includes("active"), false);
  }
});

Deno.test("failed checking projection blocks runtime installation and raw failures stay bounded", async () => {
  const f = fixture(false);
  const result = await prepareDeployVp(f.input, 901000, {
    ...f.port,
    reference: () => {
      throw new Error("private-registry-token");
    },
  });
  assertEquals(result, { error_code: "deployment.vp_store_failed" });
  assertEquals(
    f.calls.some((x) => x.name === "tool.install-vp-environment"),
    false,
  );
});

Deno.test("native deploy Vite+ cancellation prevents the next local effect", async () => {
  const f = fixture(false);
  f.port.current = () =>
    Promise.resolve({ cancel_requested: f.calls.length > 0 });
  assertEquals(await prepareDeployVp(f.input, 901000, f.port), {
    error_code: "operation.cancelled",
  });
  assertEquals(f.calls.map((x) => x.name), ["tool.read-vp-project"]);
});

Deno.test("uncertain runtime mutation stops later calls and keeps its checking reference", async () => {
  for (
    const mutation of ["tool.materialize-vp", "tool.install-vp-environment"]
  ) {
    const f = fixture(false), original = f.port.invoke;
    const attempted: string[] = [];
    const result = await prepareDeployVp(f.input, 901000, {
      ...f.port,
      invoke: (call) => {
        attempted.push(call.name);
        if (call.name === mutation) {
          throw new Error("private uncertain runtime");
        }
        return original(call);
      },
    });
    assertEquals(result, { error_code: "deployment.execution_unconfirmed" });
    assertEquals(attempted.at(-1), mutation);
    assertEquals(
      f.references,
      mutation === "tool.materialize-vp" ? [] : ["checking"],
    );
  }
});
