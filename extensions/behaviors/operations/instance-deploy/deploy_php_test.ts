import { assertEquals } from "jsr:@std/assert@1.0.19";
import type { NodeCall } from "../_lib/node-agent.ts";
import { refreshDeployPhp } from "./deploy_php.ts";
function fixture() {
  const input = {
    project_id: 9,
    agent_sha256: "a".repeat(64),
    php: {
      packages: { versions: ["8.5"], fpm: "none", extras: "keep" },
      profile: { version: "8.5", runtime_ini: "admitted runtime settings" },
      runtime: {
        user: "orbit-app-9",
        home: "/home/orbit-app-9",
        version: "8.5",
        marker: "admitted-marker",
        mode: "converge",
      },
      refresh: {
        user: "orbit-app-9",
        home: "/home/orbit-app-9",
        version: "8.5",
        marker: "admitted-marker",
        deadline_seconds: 30,
      },
    },
  };
  const calls: NodeCall[] = [];
  const port = {
    now: () => 1000,
    current: () => Promise.resolve({ cancel_requested: false }),
    invoke: (call: NodeCall): Promise<Record<string, unknown>> => {
      calls.push(call);
      return Promise.resolve(
        ({
          "php.install-build": {
            outcome: "current",
            data: { deferred: [], stale_masters: [] },
          },
          "php.configure-runtime": { changed: false, reloaded: false },
          "php.converge-production-runtime": { published: true },
          "php.refresh-production-cache": { complete: true },
        } as Record<string, Record<string, unknown>>)[call.name],
      );
    },
  };
  return { input, calls, port };
}
Deno.test("deploy PHP installs recipe packages then publishes module and pool before cache refresh", async () => {
  const f = fixture();
  assertEquals(await refreshDeployPhp(f.input, 901000, f.port), {
    confirmed: true,
    changed: true,
    deferred_count: 0,
    stale_master_count: 0,
  });
  assertEquals(f.calls.map((x) => x.name), [
    "php.install-build",
    "php.configure-runtime",
    "php.converge-production-runtime",
    "php.refresh-production-cache",
  ]);
  assertEquals(
    f.calls.every((x) => x.sudo && x.mutates && x.pin === f.input.agent_sha256),
    true,
  );
});
Deno.test("deploy PHP refuses changed app ownership version or marker before effects", async () => {
  for (const fault of ["user", "home", "version", "marker"] as const) {
    const f = fixture();
    if (fault === "user") f.input.php.runtime.user = "nckrtl";
    if (fault === "home") f.input.php.refresh.home = "/home/foreign";
    if (fault === "version") f.input.php.profile.version = "8.4";
    if (fault === "marker") f.input.php.refresh.marker = "foreign";
    assertEquals(await refreshDeployPhp(f.input, 901000, f.port), {
      error_code: "deployment.identity_changed",
    });
    assertEquals(f.calls.length, 0);
  }
});
Deno.test("native PHP error or unconfirmed response prevents every later action", async () => {
  for (const fault of ["install", "profile", "pool", "cache"] as const) {
    const f = fixture(), invoke = f.port.invoke;
    const command = {
      install: "php.install-build",
      profile: "php.configure-runtime",
      pool: "php.converge-production-runtime",
      cache: "php.refresh-production-cache",
    }[fault];
    f.port.invoke = (call) => {
      if (call.name !== command) return invoke(call);
      f.calls.push(call);
      return Promise.resolve(
        fault === "cache"
          ? { complete: false }
          : { error_code: "php.synthetic_refusal" },
      );
    };
    assertEquals(await refreshDeployPhp(f.input, 901000, f.port), {
      error_code: fault === "cache"
        ? "deployment.php_runtime_unconfirmed"
        : "php.synthetic_refusal",
    });
    assertEquals(f.calls.at(-1)?.name, command);
  }
});
Deno.test("PHP recipe diagnostics and configuration never enter public summaries", async () => {
  const f = fixture();
  f.port.invoke = () => {
    throw new Error("private synthetic diagnostic");
  };
  assertEquals(await refreshDeployPhp(f.input, 901000, f.port), {
    error_code: "deployment.php_runtime_unconfirmed",
  });
});
Deno.test("cancellation and expired parent deadline stop the next PHP action", async () => {
  for (const cancelled of [true, false]) {
    const f = fixture();
    f.port.current = () => Promise.resolve({ cancel_requested: cancelled });
    assertEquals(
      await refreshDeployPhp(f.input, cancelled ? 901000 : 999, f.port),
      {
        error_code: cancelled
          ? "operation.cancelled"
          : "deployment.deadline_exceeded",
      },
    );
    assertEquals(f.calls.length, 0);
  }
});
Deno.test("non-PHP deployment skips all PHP prerequisites", async () => {
  const f = fixture();
  assertEquals(
    await refreshDeployPhp({ ...f.input, php: null }, 901000, f.port),
    {
      confirmed: true,
      changed: false,
      deferred_count: 0,
      stale_master_count: 0,
    },
  );
  assertEquals(f.calls.length, 0);
});

Deno.test("PHP warning summaries deduplicate deferred units without returning configuration", async () => {
  const f = fixture(), invoke = f.port.invoke;
  f.port.invoke = (call) =>
    call.name === "php.install-build"
      ? Promise.resolve({
        outcome: "applied",
        data: {
          deferred: ["orbit-php.service"],
          stale_masters: ["orbit-old.service"],
        },
      })
      : call.name === "php.configure-runtime"
      ? Promise.resolve({
        changed: true,
        reloaded: false,
        deferred: ["orbit-php.service", "orbit-other.service"],
      })
      : invoke(call);
  assertEquals(await refreshDeployPhp(f.input, 901000, f.port), {
    confirmed: true,
    changed: true,
    deferred_count: 2,
    stale_master_count: 1,
  });
});
