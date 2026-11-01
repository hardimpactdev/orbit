import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import type { NodeCall } from "../_lib/node-agent.ts";
import { sha256 } from "./app_step.ts";
import { deployDirect } from "./deploy_direct.ts";
import { LocalDeployError } from "./deploy_local.ts";
import { localFixture } from "./deploy_test_fixture.ts";

const commit = "a".repeat(40),
  program = "printf private-command",
  unit = "private-unit-content",
  scheduleCommand = "/usr/bin/true # private-schedule";
async function fixture() {
  const args = {
    operation_id: 200,
    node_id: 4,
    kind: "instance.deploy" as const,
    run_id: "d91d65ed-217c-4822-9b44-dcc8e9fafcf0",
  };
  const release = { name: "admitted", commit },
    previous = { name: "previous", commit: "b".repeat(40) };
  const process = {
    process_id: 14,
    runtime: "systemd",
    name: "orbit-process-14-worker.service",
    desired: "running",
    on_demand: false,
    directory: "/home/orbit-app-9/current",
    retired: null,
    content_hash: await sha256(unit),
    activation_pending: true,
  };
  const schedule = {
    id: "9a067a27-c2ce-45c5-83f5-735e03dc5b2f",
    command_hash: await sha256(scheduleCommand),
    user: "orbit-app-9",
    home: "/home/orbit-app-9",
    working_directory: "/home/orbit-app-9/current",
    shell: "/bin/bash",
    login_shell: false,
    calendar: "2099-01-01 00:00:00",
    timeout_seconds: 30,
    desired: "disabled",
  };
  const input = {
    instance_id: 900,
    project_id: 9,
    node_id: 4,
    platform: "linux",
    architecture: "x86_64",
    commit,
    release_name: release.name,
    deployment: {
      variant: "production",
      home: "/home/orbit-app-9",
      user: "orbit-app-9",
      repository: "https://example.test/project.git",
      branch: "main",
      node_user: "orbit",
      protocols: ["https"],
      relative_root: "public",
      application_suffix: "",
      served: [],
      shared_paths: [],
      needs_vp: false,
      steps: [{
        name: "migrate",
        phase: "before_activation",
        required: true,
        timeout_seconds: 30,
        command_digest: await sha256(program),
      }, {
        name: "warm",
        phase: "after_activation",
        required: true,
        timeout_seconds: 30,
        command_digest: await sha256(program),
      }],
    },
    environment: {
      base: "/home/orbit-app-9",
      user: "orbit-app-9",
      environment: "production",
      domain: "proof.test",
    },
    php_input: null as Record<string, unknown> | null,
    runtime_definitions: {
      processes: [process],
      schedules: [schedule],
      restart: [{
        process_id: 14,
        runtime: "systemd",
        name: process.name,
        on_demand: false,
        directory: process.directory,
        vite: null,
      }],
    },
  };
  const material = {
    git_read: "private-token",
    environment_material: {
      ...input.environment,
      required_capacity: 2048,
      values: { APP_ENV: "production", TOKEN: "private-environment" },
      owned_keys: [],
      testing: null,
    },
    definitions_material: {
      schedules: { [schedule.id]: { command: scheduleCommand } },
    },
  };
  let status = "running",
    cancel = false,
    selected: typeof release | null = previous;
  const calls: NodeCall[] = [],
    posts: Record<string, unknown>[] = [],
    commands: unknown[] = [],
    paths: string[] = [],
    resources = new Map<string, Record<string, unknown>>();
  const http: Http = (method, path, raw) =>
    Promise.resolve((() => {
      paths.push(method + " " + path);
      if (method === "GET" && path.includes("/private-material?")) {
        return { status: 200, body: { data: material } };
      }
      if (
        method === "GET" &&
        path === `/api/v1/direct-operations/200?run_id=${args.run_id}`
      ) {
        return {
          status: 200,
          body: {
            data: {
              id: 200,
              kind: args.kind,
              node_id: args.node_id,
              status,
              cancel_requested: cancel,
              input: structuredClone(input),
            },
          },
        };
      }
      if (method === "GET" && path === "/api/v1/instances/900/deploy-steps") {
        return {
          status: 200,
          body: {
            data: input.deployment.steps.map((step) => ({
              ...step,
              command: program,
            })),
          },
        };
      }
      if (
        method === "POST" && path === "/api/v1/direct-operations/200/result"
      ) {
        const body = raw as Record<string, unknown>;
        posts.push(structuredClone(body));
        status = String(body.status);
        return { status: 200, body: { data: {} } };
      }
      throw new Error("Unexpected endpoint");
    })());
  const invoke = (call: NodeCall): Promise<Record<string, unknown>> => {
    calls.push(call);
    if (call.name === "instance.inspect-release") {
      return Promise.resolve({ release: selected });
    }
    if (call.name === "instance.prepare-release") {
      return Promise.resolve(release);
    }
    if (call.name === "instance.activate-release") {
      selected = release;
      return Promise.resolve(release);
    }
    const results: Record<string, Record<string, unknown>> = {
      "instance.inspect-environment-sync": {
        preflight: { state: "ok", installed: false },
        existing: { state: "missing", installed: false },
      },
      "instance.write-environment": { state: "changed", installed: true },
      "process.check-release": { available: true },
      "process.stage-unit": {
        staged: true,
        previous: false,
        state: "inactive",
      },
      "process.activate-unit": { backup_left: false },
      "process.status": { state: "active" },
      "process.control": { state: "active" },
      "schedule.install": {},
      "account.lookup": {
        user: "orbit-app-9",
        uid: 1001,
        group: "applications",
        home: "/home/orbit-app-9",
        shell: "/usr/sbin/nologin",
      },
      "php.install-build": {
        outcome: "current",
        data: { deferred: [], stale_masters: [] },
      },
      "php.configure-runtime": {
        changed: false,
        reloaded: false,
        deferred: [],
      },
      "php.converge-production-runtime": { published: false },
      "php.refresh-production-cache": { complete: true },
      "instance.prune-releases": { removed: [], in_use: [] },
    };
    if (!(call.name in results)) throw new Error("Unexpected command");
    return Promise.resolve(results[call.name]);
  };
  const run: NonNullable<Parameters<typeof deployDirect>[0]>["run"] = (
    step,
    script,
  ) => {
    commands.push({ step, script });
    return Promise.resolve({
      exit_code: 0,
      timed_out: false,
      output_tail: "",
      truncated: false,
    });
  };
  const ports = {
    http: () => http,
    invoke,
    run,
    now: () => Date.parse("2026-10-31T07:00:00Z"),
  };
  const ctx = {
    signal: undefined as AbortSignal | undefined,
    globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/tmp/ca" },
    readResource: (name: string) =>
      Promise.resolve(structuredClone(resources.get(name) ?? null)),
    writeResource: (
      _spec: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      resources.set(name, structuredClone(value));
      return Promise.resolve();
    },
  };
  const execute = async () => {
    const trace = async (
      name: string,
      input: Record<string, unknown>,
      mutates = false,
    ) => {
      const reply = await ports.invoke({
        name,
        input,
        mutates,
        sudo: true,
        pin: "fixture",
        deadlineMs: 900000,
      });
      if (typeof reply.error_code === "string") {
        if (/unconfirmed|uncertain/.test(reply.error_code)) {
          throw new Error(reply.error_code);
        }
        throw new LocalDeployError(reply.error_code);
      }
      return reply;
    };
    const methods: Record<string, string> = {
      inspectRelease: "instance.inspect-release",
      prepareRelease: "instance.prepare-release",
      activateRelease: "instance.activate-release",
      pruneReleases: "instance.prune-releases",
    };
    const source = localFixture((call) =>
      trace(methods[call.name] ?? call.name, call.input, call.mutates)
    );
    const model = deployDirect({
      http: ports.http,
      now: ports.now,
      run: ports.run,
      source,
      inspectRunner: (runner) =>
        trace("instance.inspect-deploy-step", { runner }) as Promise<
          { runner: string; gone: boolean }
        >,
      production: {
        environment: {
          access: (input) =>
            trace(
              input.mode === "inspect"
                ? "instance.inspect-environment-sync"
                : "instance.write-environment",
              input,
              input.mode !== "inspect",
            ),
        },
        processControl: (name, input, _digest) =>
          ports.invoke({
            name,
            input,
            pin: "fixture",
            mutates: !["process.check-release", "process.status"].includes(
              name,
            ),
            sudo: true,
            deadlineMs: 900000,
          }),
        schedule: (name, input) =>
          ports.invoke({
            name,
            input,
            pin: "fixture",
            mutates: true,
            sudo: true,
            deadlineMs: 900000,
          }),
        account: (user) =>
          trace("account.lookup", { user }) as Promise<
            {
              user: string;
              uid: number;
              gid: number;
              group: string;
              home: string;
              shell: string;
            }
          >,
      },
    });
    const prepared = await model.methods.prepareProduction.execute(
      args,
      ctx,
    );
    if (!("ready" in prepared)) return prepared;
    return await model.methods.finishProduction.execute(args, ctx);
  };
  return {
    args,
    input,
    release,
    previous,
    material,
    ports,
    ctx,
    execute,
    failure: () =>
      deployDirect({ http: ports.http, now: ports.now }).methods.failure
        .execute(args, ctx),
    calls,
    posts,
    commands,
    paths,
    resources,
    cancel: () => {
      cancel = true;
    },
    status: (value: string) => {
      status = value;
    },
  };
}

Deno.test("production composes source environment app steps activation definitions restart and cleanup in one root", async () => {
  const f = await fixture();
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(f.calls.map((call) => call.name), [
    "instance.inspect-release",
    "instance.prepare-release",
    "instance.inspect-environment-sync",
    "instance.write-environment",
    "instance.activate-release",
    "account.lookup",
    "schedule.install",
    "process.check-release",
    "process.control",
    "process.status",
    "instance.inspect-release",
    "instance.prune-releases",
    "instance.inspect-release",
  ]);
  assertEquals(f.commands.length, 2);
  assertEquals((f.commands[0] as { step: { target: unknown } }).step.target, {
    variant: "release",
    release: "admitted",
  });
  assertEquals(
    f.calls.find((call) => call.name === "schedule.install")!.input.group,
    "applications",
  );
  assertEquals(
    f.calls.find((call) => call.name === "schedule.install")!.input.shell,
    "/bin/bash",
  );
  assertEquals(
    f.calls.find((call) => call.name === "instance.prune-releases")!.input
      .limit,
    3,
  );
  const result = f.posts.at(-1)!.result as Record<string, unknown>;
  assertEquals(result.selected_release, f.release);
  assertEquals(result.previous, f.previous);
  assertEquals(result.runtime_definitions, {
    processes: [{ process_id: 14, ready: true, state: "active" }],
    schedules: [{
      id: f.input.runtime_definitions.schedules[0].id,
      ready: true,
    }],
    restart: [{ process_id: 14, ready: true, state: "active" }],
  });
  assertEquals(
    f.paths.some((path) => /children|operation-executor|receipt/.test(path)),
    false,
  );
  const publicState = JSON.stringify({
    posts: f.posts,
    resources: [...f.resources.values()],
  });
  for (
    const secret of [
      program,
      unit,
      scheduleCommand,
      "private-token",
      "private-environment",
    ]
  ) assertEquals(publicState.includes(secret), false);
});

Deno.test("production cancellation before activation preserves previous release and skips later effects", async () => {
  const f = await fixture(), invoke = f.ports.invoke;
  f.ports.invoke = async (call) => {
    const reply = await invoke(call);
    if (call.name === "instance.prepare-release") f.cancel();
    return reply;
  };
  assertEquals(await f.execute(), { status: "cancelled" });
  assertEquals(f.calls.map((call) => call.name), [
    "instance.inspect-release",
    "instance.prepare-release",
  ]);
  assertEquals(
    (f.posts.at(-1)!.result as Record<string, unknown>).selected_release,
    f.previous,
  );
  assertEquals(f.commands, []);
});

Deno.test("production cancellation after activation finishes Process restart and after steps", async () => {
  const f = await fixture(), invoke = f.ports.invoke;
  f.ports.invoke = async (call) => {
    const reply = await invoke(call);
    if (call.name === "instance.activate-release") f.cancel();
    return reply;
  };
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(f.calls.some((call) => call.name === "process.control"), true);
  assertEquals(f.commands.length, 2);
});

Deno.test("production failure before activation preserves previous selection and returns only redacted output", async () => {
  const f = await fixture();
  f.ports.run = () =>
    Promise.resolve({
      exit_code: 1,
      timed_out: false,
      output_tail: "redacted failure\0",
      truncated: false,
    });
  await assertRejects(f.execute, Error, "deployment.step_failed");
  const terminal = f.posts.at(-1)!;
  assertEquals(terminal.status, "failed");
  assertEquals(
    (terminal.result as Record<string, unknown>).selected_release,
    f.previous,
  );
  assertEquals(
    (terminal.result as Record<string, unknown>).output_tail,
    "redacted failure",
  );
  assertEquals(
    f.calls.some((call) => call.name === "instance.activate-release"),
    false,
  );
});

Deno.test("production restart failure leaves newly activated release selected", async () => {
  const f = await fixture(), invoke = f.ports.invoke;
  f.ports.invoke = (call) =>
    call.name === "process.control"
      ? Promise.resolve({ error_code: "process.synthetic_refusal" })
      : invoke(call);
  await assertRejects(f.execute, Error, "process.synthetic_refusal");
  const result = f.posts.at(-1)!.result as Record<string, unknown>;
  assertEquals(result.selected_release, f.release);
  assertEquals(result.failed_step, "process_restart");
  assertEquals(f.commands.length, 1);
});

Deno.test("production refuses foreign app account before effects", async () => {
  const f = await fixture();
  f.input.deployment.user = "orbit";
  await assertRejects(f.execute, Error, "deployment.identity_changed");
  assertEquals(f.calls, []);
  assertEquals(f.commands, []);
});

Deno.test("production lost mutation response holds the target and never replays on restart", async () => {
  const f = await fixture(), invoke = f.ports.invoke;
  f.ports.invoke = (call) => {
    if (call.name === "instance.prepare-release") {
      f.calls.push(call);
      throw new Error("private diagnostic");
    }
    return invoke(call);
  };
  await assertRejects(f.execute, Error, "deployment.execution_unconfirmed");
  const count = f.calls.length;
  await assertRejects(f.execute, Error, "deployment.execution_unconfirmed");
  assertEquals(f.calls.length, count);
  assertEquals(f.posts.filter((post) => post.status !== "running"), []);
});

Deno.test("production restart at inactive boundary fails safely without repeating source or environment effects", async () => {
  const f = await fixture(),
    write = f.ctx.writeResource,
    stopped = new AbortController();
  f.ctx.signal = stopped.signal;
  f.ctx.writeResource = (spec, name, value) => {
    const reply = write(spec, name, value);
    if (value.stage === 2) {
      stopped.abort();
      throw new Error("worker stopped after durable boundary");
    }
    return reply;
  };
  await assertRejects(f.execute, Error, "deployment.execution_unconfirmed");
  f.ctx.signal = undefined;
  f.ctx.writeResource = write;
  await assertRejects(f.execute, Error, "deployment.interrupted");
  assertEquals(
    f.calls.filter((call) => call.name === "instance.prepare-release").length,
    1,
  );
  assertEquals(
    f.calls.filter((call) => call.name === "instance.write-environment").length,
    1,
  );
});

Deno.test("production terminal replay never calls local effects again", async () => {
  const f = await fixture();
  await f.execute();
  const count = f.calls.length;
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(f.calls.length, count);
});

for (const gone of [true, false]) {
  Deno.test(`production restarted app step cancellation requires exact runner absence: gone=${gone}`, async () => {
    const f = await fixture();
    let attempts = 0;
    f.ports.run = () => {
      attempts++;
      throw new Error("worker stopped in sudo step");
    };
    await assertRejects(f.execute, Error, "deployment.execution_unconfirmed");
    f.cancel();
    const invoke = f.ports.invoke;
    f.ports.invoke = (call) => {
      if (call.name === "instance.inspect-deploy-step") {
        f.calls.push(call);
        return Promise.resolve({ runner: call.input.runner, gone });
      }
      return invoke(call);
    };
    await assertRejects(
      f.execute,
      Error,
      gone ? "deployment.interrupted" : "deployment.execution_unconfirmed",
    );
    assertEquals(attempts, 1);
    const terminal = f.posts.filter((post) => post.status !== "running");
    assertEquals(terminal.length, gone ? 1 : 0);
    if (gone) {
      const result = terminal[0].result as Record<string, unknown>;
      assertEquals(result.failed_step, "before_activation.migrate");
      assertEquals(result.step_outcome, "unknown");
      assertEquals(result.selected_release, f.previous);
    }
  });
}

Deno.test("production known source refusal records the previously inspected selected release", async () => {
  const f = await fixture(), invoke = f.ports.invoke;
  f.ports.invoke = (call) =>
    call.name === "instance.prepare-release"
      ? Promise.resolve({ error_code: "deployment.synthetic_refusal" })
      : invoke(call);
  await assertRejects(f.execute, Error, "deployment.synthetic_refusal");
  const result = f.posts.at(-1)!.result as Record<string, unknown>;
  assertEquals(result.selected_release, f.previous);
  assertEquals(result.selection_confirmed, true);
});

Deno.test("production uncertain activation never asserts a selected release or emits terminal projection", async () => {
  const f = await fixture(), invoke = f.ports.invoke;
  f.ports.invoke = (call) =>
    call.name === "instance.activate-release"
      ? Promise.resolve({ name: "foreign", commit })
      : invoke(call);
  await assertRejects(f.execute, Error, "deployment.execution_unconfirmed");
  assertEquals(f.posts.filter((post) => post.status !== "running"), []);
  const resource = [...f.resources.values()][0] as {
    result: { selection_confirmed: boolean };
  };
  assertEquals(resource.result.selection_confirmed, false);
  // The durable active marker fences the activation; a restart never adopts the older selection as current.
  const count = f.calls.length;
  await assertRejects(f.execute, Error, "deployment.execution_unconfirmed");
  assertEquals(f.calls.length, count);
});

Deno.test("production Schedule-only aggregate accepts PHP empty Process map without guessing account group", async () => {
  const f = await fixture();
  f.input.runtime_definitions.processes = [];
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(
    f.calls.some((call) => call.name === "process.stage-unit"),
    false,
  );
  assertEquals(
    f.calls.find((call) => call.name === "schedule.install")!.input.group,
    "applications",
  );
});

Deno.test("production Schedule identity mismatch is refused without a Schedule effect", async () => {
  const f = await fixture();
  f.input.runtime_definitions.schedules[0].login_shell = true;
  await assertRejects(f.execute, Error, "deployment.identity_changed");
  assertEquals(f.calls.some((call) => call.name === "schedule.install"), false);
  assertEquals(
    (f.posts.at(-1)!.result as Record<string, unknown>).selected_release,
    f.release,
  );
});

Deno.test("production failed dependency holds every active effect without rendering public intent", async () => {
  const f = await fixture();
  f.ports.run = () => {
    throw new Error("worker interrupted");
  };
  await assertRejects(f.execute, Error, "deployment.execution_unconfirmed");
  const paths = f.paths.length;
  assertEquals(await f.failure(), { status: "held" });
  assertEquals(f.paths.length, paths);
  assertEquals(f.posts.filter((post) => post.status !== "running"), []);
});
Deno.test("production failed dependency settles inactive progress without a public intent GET", async () => {
  const f = await fixture(),
    write = f.ctx.writeResource,
    stopped = new AbortController();
  f.ctx.signal = stopped.signal;
  f.ctx.writeResource = (spec, name, value) => {
    const result = write(spec, name, value);
    if (value.stage === 2) {
      stopped.abort();
      throw new Error("lost worker after inactive progress");
    }
    return result;
  };
  await assertRejects(f.execute, Error, "deployment.execution_unconfirmed");
  f.ctx.signal = undefined;
  // The callback uses only the existing inactive journal and result endpoint.
  const gets = f.paths.filter((path) => path.startsWith("GET ")).length;
  assertEquals(await f.failure(), { status: "failed" });
  assertEquals(f.paths.filter((path) => path.startsWith("GET ")).length, gets);
});
Deno.test("production projection reports synchronized environment keys without material", async () => {
  const f = await fixture();
  await f.execute();
  assertEquals(
    (f.posts.at(-1)!.result as Record<string, unknown>).environment,
    { keys: ["APP_ENV", "TOKEN"] },
  );
});

Deno.test("a successor production root cannot execute or clear a foreign active predecessor", async () => {
  const f = await fixture(),
    owner = {
      operation_id: 199,
      run_id: "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee",
      active: true,
    };
  f.resources.set("activeExecution", owner);
  await assertRejects(f.execute, Error, "deployment.target_busy");
  assertEquals(f.calls, []);
  assertEquals(f.commands, []);
  assertEquals(await f.failure(), { status: "held" });
  assertEquals(f.resources.get("activeExecution"), owner);
  assertEquals(f.posts, []);
});
Deno.test("an inactive predecessor permits a fresh production root", async () => {
  const f = await fixture();
  f.resources.set("activeExecution", {
    operation_id: 199,
    run_id: "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee",
    active: false,
  });
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(f.resources.get("activeExecution"), {
    operation_id: f.args.operation_id,
    run_id: f.args.run_id,
    active: false,
  });
});
Deno.test("an active same-root pointer without its immutable journal cannot become fresh execution", async () => {
  const f = await fixture();
  f.resources.set("activeExecution", {
    operation_id: f.args.operation_id,
    run_id: f.args.run_id,
    active: true,
  });
  await assertRejects(f.execute, Error, "deployment.execution_unconfirmed");
  assertEquals(f.calls, []);
  assertEquals(await f.failure(), { status: "held" });
  assertEquals(f.posts, []);
});
