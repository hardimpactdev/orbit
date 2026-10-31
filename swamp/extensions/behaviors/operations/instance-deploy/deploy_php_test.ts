import { assertEquals } from "jsr:@std/assert@1.0.19";
import { DeployPhpInput, refreshDeployPhp } from "./deploy_php.ts";
import { PhpLocalError } from "../php/local.ts";

function fixture() {
  const input = DeployPhpInput.parse({
    project_id: 9,
    php: {
      packages: {
        versions: {
          "8.5": {
            packages: [{
              name: "php8.5-cli",
              version: "8.5.0-1",
              architecture: "amd64",
              url: "https://packages.test/php.deb",
              sha256: "a".repeat(64),
            }],
            extensions: ["cli"],
          },
        },
        mirror: "",
        pcov: false,
        opentelemetry: false,
        extras: "keep",
        fpm: "none",
        repack: { package: "dpkg-repack", version: "1.52" },
      },
      profile: {
        version: "8.5",
        runtime_ini: "private-admitted-settings",
        release: { id: "ubuntu", codenames: ["noble"] },
      },
      runtime: {
        user: "orbit-app-9",
        home: "/home/orbit-app-9",
        version: "8.5",
        marker: "owned-instance-900",
        mode: "converge",
        configuration: {
          main: "private-main",
          pool: "private-pool",
          local_defaults: "",
          master_ini: "",
          unit: "",
          initial_pool: "",
        },
        application: "/home/orbit-app-9/current",
        initial_application: "/home/orbit-app-9/releases/new",
      },
      refresh: {
        user: "orbit-app-9",
        home: "/home/orbit-app-9",
        version: "8.5",
        marker: "owned-instance-900",
        deadline_seconds: 30,
      },
    },
  });
  const calls: string[] = [];
  let active = false;
  const port: Parameters<typeof refreshDeployPhp>[2] = {
    now: () => 1000,
    current: () => Promise.resolve({ cancel_requested: false }),
    mutation: async <T>(action: () => Promise<T>) => {
      active = true;
      try {
        return await action();
      } finally {
        active = false;
      }
    },
    install: () => {
      assertEquals(active, true);
      calls.push("install");
      return Promise.resolve({
        outcome: "current",
        data: {
          changed: [],
          repaired: [],
          purged: [],
          disabled: [],
          restarted: [],
          reloaded: [],
          deferred: [],
          stale_masters: [],
          fpm_disabled: false,
        },
      });
    },
    profile: () => {
      assertEquals(active, true);
      calls.push("profile");
      return Promise.resolve({ changed: false, reloaded: false, deferred: [] });
    },
    runtime: () => {
      assertEquals(active, true);
      calls.push("runtime");
      return Promise.resolve({ published: true });
    },
    refresh: () => {
      assertEquals(active, true);
      calls.push("cache");
      return Promise.resolve({ complete: true });
    },
  };
  return { input, calls, port };
}
Deno.test("production PHP composes canonical package profile runtime and cache native APIs under parent checkpoints", async () => {
  const f = fixture();
  assertEquals(await refreshDeployPhp(f.input, 901000, f.port), {
    confirmed: true,
    changed: true,
    deferred_count: 0,
    stale_master_count: 0,
  });
  assertEquals(f.calls, ["install", "profile", "runtime", "cache"]);
});
Deno.test("production PHP refuses app owner home version marker and mode changes before effects", async () => {
  for (const fault of ["user", "home", "version", "marker", "mode"] as const) {
    const f = fixture(), php = f.input.php!;
    if (fault === "user") php.runtime.user = "foreign";
    if (fault === "home") php.refresh.home = "/home/foreign";
    if (fault === "version") php.profile.version = "8.4";
    if (fault === "marker") php.refresh.marker = "foreign";
    if (fault === "mode") php.runtime.mode = "monitor";
    assertEquals(await refreshDeployPhp(f.input, 901000, f.port), {
      error_code: "deployment.identity_changed",
    });
    assertEquals(f.calls, []);
  }
});
Deno.test("production PHP validates the whole native plan before package effects", async () => {
  const f = fixture();
  f.input.php!.runtime.application = "../foreign";
  assertEquals(await refreshDeployPhp(f.input, 901000, f.port), {
    error_code: "deployment.php_runtime_unconfirmed",
  });
  assertEquals(f.calls, []);
});
Deno.test("production PHP mutation uncertainty stops later actions and exposes no diagnostics", async () => {
  for (const fault of ["install", "profile", "runtime", "refresh"] as const) {
    const f = fixture();
    f.port[fault] = () => {
      throw new Error("private-package-or-pool-data");
    };
    assertEquals(await refreshDeployPhp(f.input, 901000, f.port), {
      error_code: "deployment.execution_unconfirmed",
    });
    assertEquals(
      f.calls.length,
      { install: 0, profile: 1, runtime: 2, refresh: 3 }[fault],
    );
  }
});
Deno.test("production PHP respects cancellation and deadline before each next action", async () => {
  for (const cancel of [true, false]) {
    const f = fixture();
    f.port.current = () => Promise.resolve({ cancel_requested: cancel });
    assertEquals(
      await refreshDeployPhp(f.input, cancel ? 901000 : 999, f.port),
      {
        error_code: cancel
          ? "operation.cancelled"
          : "deployment.deadline_exceeded",
      },
    );
    assertEquals(f.calls, []);
  }
  const f = fixture();
  f.port.current = () =>
    Promise.resolve({ cancel_requested: f.calls.length > 0 });
  assertEquals(await refreshDeployPhp(f.input, 901000, f.port), {
    error_code: "operation.cancelled",
  });
  assertEquals(f.calls, ["install"]);
});
Deno.test("non-PHP deploy skips native PHP prerequisites", async () => {
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
  assertEquals(f.calls, []);
});
Deno.test("production PHP exposes only bounded warning counts", async () => {
  const f = fixture(), install = f.port.install!;
  f.port.install = async (...args) => {
    const result = await install(...args);
    result.data.deferred = ["one.service"];
    result.data.stale_masters = ["old.service"];
    return result;
  };
  f.port.profile = () =>
    Promise.resolve({
      changed: true,
      reloaded: false,
      deferred: ["one.service", "two.service"],
    });
  const result = await refreshDeployPhp(f.input, 901000, f.port);
  assertEquals(result, {
    confirmed: true,
    changed: true,
    deferred_count: 2,
    stale_master_count: 1,
  });
  assertEquals(JSON.stringify(result).includes("private"), false);
});

Deno.test("production PHP distinguishes a confirmed restored package failure from unknown effects", async () => {
  for (const restored of [true, false, undefined]) {
    const f = fixture();
    f.port.install = () => {
      throw new PhpLocalError("php.build_failed", restored);
    };
    assertEquals(await refreshDeployPhp(f.input, 901000, f.port), {
      error_code: restored === true
        ? "php.build_failed"
        : "deployment.execution_unconfirmed",
    });
    assertEquals(f.calls, []);
  }
});
