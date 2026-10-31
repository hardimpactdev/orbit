import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  metricsPaths,
  metricsRuntimeActions as nativeRuntime,
  type MetricsRuntimeDeps,
} from "./metrics_runtime_actions.ts";
import type { Runner } from "./metrics_system.ts";

function metricsRuntimeActions(deps: MetricsRuntimeDeps = {}) {
  return nativeRuntime({
    ...deps,
    ...(deps.run
      ? {
        engine: {
          call: async (args, _signal, timeoutMs, input) => {
            const reply = await deps.run!("/usr/bin/docker", [...args], {
              input,
              timeoutMs,
            });
            return { success: reply.code === 0, stdout: reply.stdout };
          },
        },
      }
      : {}),
  });
}

const managed = "com.orbit.managed";
const configuration = () =>
  Object.fromEntries(
    metricsPaths.map((path) => [
      path,
      path.endsWith("admin-password")
        ? "PRIVATE_RUNTIME_SENTINEL"
        : `content:${path}`,
    ]),
  );
const input = (files = configuration()) => ({
  address: "10.44.0.17",
  action: { operation: "converge", assignment: 41, configuration: files },
});
class Host {
  containers = new Map<
    string,
    { labels: Record<string, string>; running: boolean; health: string }
  >();
  volumes = new Map<string, Record<string, string>>();
  files: Record<string, string> | null = null;
  failure = "";
  afterEffect = false;
  unhealthy = "";
  unknown = "";
  calls: string[][] = [];
  publications = 0;
  restored = 0;
  run: Runner = (program, args, options = {}) =>
    Promise.resolve().then(() => {
      if (program === "/usr/bin/python3") {
        const value = JSON.parse(options.input!);
        if (value.operation === "snapshot") {
          return {
            code: 0,
            stdout: JSON.stringify({
              files: this.files,
              existed: this.files !== null,
            }),
          };
        }
        if (value.operation === "publish") {
          this.files = { ...value.configuration };
          this.publications++;
        }
        if (value.operation === "restore") {
          this.files = value.snapshot.files;
          this.restored++;
        }
        if (value.operation === "remove") this.files = null;
        return { code: 0, stdout: "{}" };
      }
      assertEquals(program, "/usr/bin/docker");
      this.calls.push(args);
      const command = args.join(" "),
        hit = this.failure !== "" && command.includes(this.failure);
      if (this.unknown && command.includes(this.unknown)) {
        throw new Error("PRIVATE_RUNTIME_SENTINEL");
      }
      if (hit && !this.afterEffect) {
        this.failure = "";
        return { code: 1, stdout: "" };
      }
      const result = this.docker(args);
      if (hit) {
        this.failure = "";
        return { code: 1, stdout: "" };
      }
      return result;
    });
  docker(args: string[]) {
    const resource = args.at(-1)!;
    if (args[1] === "inspect") {
      if (args[0] === "image") return { code: 0, stdout: "image" };
      const container = this.containers.get(resource),
        volume = this.volumes.get(resource);
      if (args[0] === "volume") {
        return volume
          ? { code: 0, stdout: JSON.stringify(volume) }
          : { code: 1, stdout: "" };
      }
      if (!container) return { code: 1, stdout: "" };
      if (args[2].includes("Config.Labels")) {
        return { code: 0, stdout: JSON.stringify(container.labels) };
      }
      if (args[2].includes("State.Running")) {
        return { code: 0, stdout: String(container.running) };
      }
      return {
        code: 0,
        stdout: `${container.running ? "running" : "exited"} ${
          this.unhealthy === resource ? "unhealthy" : container.health
        }`,
      };
    }
    if (args[1] === "ls") return { code: 0, stdout: "" };
    if (args[0] === "volume") {
      if (args[1] === "rm") this.volumes.delete(resource);
      else this.volumes.set(resource, this.fromArgs(args));
    } else if (args[1] === "rename") {
      const old = this.containers.get(args[2]);
      if (!old) return { code: 1, stdout: "" };
      this.containers.delete(args[2]);
      this.containers.set(args[3], old);
    } else if (args[1] === "stop" || args[1] === "start") {
      const old = this.containers.get(resource);
      if (old) old.running = args[1] === "start";
    } else if (args[1] === "rm") this.containers.delete(resource);
    else if (args[1] === "run" && args.includes("--detach")) {
      this.containers.set(args[args.indexOf("--name") + 1], {
        labels: this.fromArgs(args),
        running: true,
        health: "healthy",
      });
    }
    return { code: 0, stdout: "" };
  }
  fromArgs(args: string[]) {
    return Object.fromEntries(
      args.flatMap((v, i) => v === "--label" ? [args[i + 1].split("=")] : []),
    );
  }
  actions() {
    return metricsRuntimeActions({
      run: this.run,
      sleep: () => Promise.resolve(),
    });
  }
}
Deno.test("runtime uses fixed owned Docker policy and repeats without replacing healthy containers", async () => {
  const h = new Host(), actions = h.actions();
  assertEquals(await actions.runtime(input()), { changed: true });
  h.calls = [];
  assertEquals(await actions.runtime(input()), { changed: true });
  assertEquals(h.containers.size, 2);
  assertEquals(h.volumes.size, 2);
  assertEquals(h.calls.filter((a) => a.includes("--detach")).length, 0);
  const grafana = h.containers.get("orbit-metrics-grafana")!;
  assertEquals(grafana.labels[managed], "metrics");
  assertEquals(
    JSON.stringify(h.calls).includes("PRIVATE_RUNTIME_SENTINEL"),
    false,
  );
  const changed = configuration();
  changed[metricsPaths[4]] = "dashboard";
  changed[metricsPaths[7]] = "OTHER_PRIVATE";
  assertEquals(await actions.runtime(input(changed)), { changed: true });
  assertEquals(h.calls.filter((a) => a.includes("--detach")).length, 0);
});
Deno.test("foreign containers backups and purge volumes refuse before publishing or mutation", async () => {
  for (const kind of ["container", "backup", "volume"]) {
    const h = new Host(),
      resource = "orbit-metrics-grafana" +
        (kind === "backup" ? "-orbit-rollback" : "");
    if (kind === "volume") h.volumes.set("orbit-metrics-grafana-data", {});
    else {h.containers.set(resource, {
        labels: {},
        running: true,
        health: "healthy",
      });}
    assertEquals(await h.actions().runtime(input()), {
      error_code: `metrics.${
        kind === "volume" ? "volume" : "container"
      }_ownership_drift`,
    });
    assertEquals(h.publications, 0);
    assertEquals(
      await h.actions().runtime({
        address: "10.44.0.17",
        action: { operation: "remove", purge_data: true },
      }),
      {
        error_code: `metrics.${
          kind === "volume" ? "volume" : "container"
        }_ownership_drift`,
      },
    );
    assertEquals(h.files, null);
  }
});
Deno.test("failed replacement transitions restore both containers and private configuration", async () => {
  for (
    const failure of [
      "stop --time 30 -- orbit-metrics-grafana",
      "rename orbit-metrics-grafana ",
      "run --detach --pull=never --name orbit-metrics-grafana",
    ]
  ) {
    const h = new Host();
    await h.actions().runtime(input());
    const original = structuredClone([...h.containers]);
    const old = configuration();
    const changed = configuration();
    changed[metricsPaths[1]] = "changed settings";
    h.failure = failure;
    assertEquals(await h.actions().runtime(input(changed)), {
      error_code: "metrics.container_convergence_failed",
    });
    assertEquals([...h.containers], original);
    assertEquals(h.files, old);
    assertEquals(h.restored, 1);
  }
});
Deno.test("observed stop rename create and cleanup success wins over a lost command exit", async () => {
  for (
    const failure of [
      "stop --time 30 -- orbit-metrics-grafana",
      "rename orbit-metrics-grafana ",
      "run --detach --pull=never --name orbit-metrics-grafana",
      "rm --force -- orbit-metrics-grafana-orbit-rollback",
    ]
  ) {
    const h = new Host();
    await h.actions().runtime(input());
    const changed = configuration();
    changed[metricsPaths[1]] = "changed settings";
    h.failure = failure;
    h.afterEffect = true;
    assertEquals(await h.actions().runtime(input(changed)), { changed: true });
    assertEquals(h.containers.size, 2);
    assertEquals(h.files, changed);
  }
});
Deno.test("first-run failure rolls back created volumes files and containers", async () => {
  const h = new Host();
  h.unhealthy = "orbit-metrics-grafana";
  assertEquals(await h.actions().runtime(input()), {
    error_code: "metrics.container_convergence_failed",
  });
  assertEquals(h.files, null);
  assertEquals(h.containers.size, 0);
  assertEquals(h.volumes.size, 0);
});
Deno.test("cleanup failure keeps committed configuration and a later converge removes the backup", async () => {
  const h = new Host();
  await h.actions().runtime(input());
  const changed = configuration();
  changed[metricsPaths[1]] = "changed settings";
  h.failure = "rm --force -- orbit-metrics-grafana-orbit-rollback";
  assertEquals(await h.actions().runtime(input(changed)), {
    error_code: "metrics.container_cleanup_failed",
  });
  assertEquals(h.files, changed);
  assertEquals(h.restored, 0);
  assertEquals(await h.actions().runtime(input(changed)), { changed: true });
  assertEquals(h.containers.size, 2);
});
Deno.test("Docker transport uncertainty preserves recovery resources without false rollback", async () => {
  const h = new Host();
  await h.actions().runtime(input());
  const changed = configuration();
  changed[metricsPaths[1]] = "changed settings";
  h.unknown = "run --detach --pull=never --name orbit-metrics-grafana";
  await assertRejects(
    () => h.actions().runtime(input(changed)),
    Error,
    "metrics.outcome_unknown",
  );
  assertEquals(h.restored, 0);
  assert(h.containers.has("orbit-metrics-grafana-orbit-rollback"));
});
Deno.test("remove preserves data unless explicitly purging and health observes owned running state", async () => {
  const h = new Host(), actions = h.actions();
  await actions.runtime(input());
  assertEquals(await actions.health("grafana"), { healthy: true });
  h.containers.get("orbit-metrics-grafana")!.running = false;
  assertEquals(await actions.health("grafana"), { healthy: false });
  assertEquals(
    await actions.runtime({
      address: "10.44.0.17",
      action: { operation: "remove", purge_data: false },
    }),
    { changed: true },
  );
  assertEquals(h.containers.size, 0);
  assertEquals(h.volumes.size, 2);
  assertEquals(h.files, null);
  assertEquals(
    await actions.runtime({
      address: "10.44.0.17",
      action: { operation: "remove", purge_data: true },
    }),
    { changed: true },
  );
  assertEquals(h.volumes.size, 0);
});
Deno.test("Grafana verify/reset authenticate inside fetch and never invoke a process", async () => {
  const seen: {
    url: string;
    method: string;
    auth: string;
    body: string | undefined;
  }[] = [];
  const actions = metricsRuntimeActions({
    run: () => {
      throw new Error("No process allowed");
    },
    fetch: ((url, init) => {
      seen.push({
        url: String(url),
        method: init?.method ?? "",
        auth: String((init?.headers as Record<string, string>).Authorization),
        body: init?.body as string | undefined,
      });
      return Promise.resolve(new Response("", { status: 200 }));
    }) as typeof fetch,
  });
  assertEquals(
    await actions.credential({
      address: "10.44.0.17",
      action: { operation: "verify", password: "active" },
    }),
    { verified: true },
  );
  assertEquals(
    await actions.credential({
      address: "10.44.0.17",
      action: {
        operation: "apply",
        active_password: "active",
        pending_password: "pending",
      },
    }),
    { changed: true },
  );
  assertEquals(seen, [{
    url: "http://10.44.0.17:3000/api/user",
    method: "GET",
    auth: "Basic " + btoa("admin:active"),
    body: undefined,
  }, {
    url: "http://10.44.0.17:3000/api/admin/users/1/password",
    method: "PUT",
    auth: "Basic " + btoa("admin:active"),
    body: '{"password":"pending"}',
  }]);
  const refused = metricsRuntimeActions({
    fetch: (() =>
      Promise.resolve(new Response("", { status: 401 }))) as typeof fetch,
  });
  assertEquals(
    await refused.credential({
      address: "10.44.0.17",
      action: { operation: "verify", password: "active" },
    }),
    { verified: false },
  );
  assertEquals(
    await refused.credential({
      address: "10.44.0.17",
      action: {
        operation: "apply",
        active_password: "active",
        pending_password: "pending",
      },
    }),
    { error_code: "metrics.credentials_reset_failed" },
  );
});

Deno.test("real fixed-path filesystem refuses foreign roots and ancestor symlinks before Docker", async () => {
  const { runMetricsProcess } = await import("./metrics_system.ts");
  for (const symlink of [false, true]) {
    const root = await Deno.makeTempDir({ prefix: "metrics-runtime-fs-" });
    try {
      const base = root + "/etc/orbit/metrics";
      await Deno.mkdir(base, { recursive: true });
      if (symlink) {
        await Deno.writeTextFile(base + "/.orbit-owner", "metrics\n");
        await Deno.symlink(root, base + "/grafana");
      }
      let docker = 0;
      const run: Runner = (program, args, options) => {
        if (program === "/usr/bin/docker") docker++;
        return runMetricsProcess(program, args, options);
      };
      assertEquals(
        await metricsRuntimeActions({ root, run }).runtime(input()),
        { error_code: "metrics.configuration_ownership_drift" },
      );
      assertEquals(docker, 0);
    } finally {
      await Deno.remove(root, { recursive: true });
    }
  }
});

Deno.test("Grafana network and server uncertainty stay recoverable with bounded diagnostics", async () => {
  for (const operation of ["verify", "apply"]) {
    for (const status of [0, 502]) {
      const actions = metricsRuntimeActions({
        fetch: (() => {
          if (status === 0) throw new Error("PRIVATE_RUNTIME_SENTINEL");
          return Promise.resolve(
            new Response("PRIVATE_RUNTIME_SENTINEL", { status }),
          );
        }) as typeof fetch,
      });
      const error = await assertRejects(
        () =>
          actions.credential({
            address: "10.44.0.17",
            action: {
              operation,
              password: "private",
              active_password: "private",
              pending_password: "pending",
            },
          }),
        Error,
        "metrics.outcome_unknown",
      );
      assertEquals(error.message, "metrics.outcome_unknown");
    }
  }
});

Deno.test("real private files restore exact bytes owner and mode after replacement failure", async () => {
  const { runMetricsProcess } = await import("./metrics_system.ts");
  const root = await Deno.makeTempDir({ prefix: "metrics-runtime-private-" });
  const h = new Host();
  const run: Runner = (program, args, options) =>
    program === "/usr/bin/python3"
      ? runMetricsProcess(program, args, options)
      : h.run(program, args, options);
  try {
    const actions = metricsRuntimeActions({
      root,
      run,
      sleep: () => Promise.resolve(),
    });
    assertEquals(await actions.runtime(input()), { changed: true });
    const query = () =>
      runMetricsProcess("/usr/bin/python3", [
        "-c",
        "import os,pathlib,json,sys;p=pathlib.Path(sys.argv[1]);s=p.stat();print(json.dumps({'bytes':p.read_text(),'mode':s.st_mode&0o777,'uid':s.st_uid,'gid':s.st_gid}))",
        root + metricsPaths[7],
      ]);
    const before = JSON.parse((await query()).stdout);
    assertEquals(before, {
      bytes: "PRIVATE_RUNTIME_SENTINEL",
      mode: 0o400,
      uid: 472,
      gid: 472,
    });
    const changed = configuration();
    changed[metricsPaths[1]] = "replacement settings";
    changed[metricsPaths[7]] = "NEW_PRIVATE_RUNTIME_SENTINEL";
    h.failure = "run --detach --pull=never --name orbit-metrics-grafana";
    assertEquals(await actions.runtime(input(changed)), {
      error_code: "metrics.container_convergence_failed",
    });
    assertEquals(JSON.parse((await query()).stdout), before);
    assertEquals(
      await actions.runtime({
        address: "10.44.0.17",
        action: { operation: "remove", purge_data: true },
      }),
      { changed: true },
    );
  } finally {
    await runMetricsProcess("/usr/bin/rm", ["-rf", "--", root]);
  }
});
