import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import type { NodeCall } from "../_lib/node-agent.ts";
import { sha256 } from "./app_step.ts";
import { deployDirect } from "./deploy_direct.ts";

const commit = "a".repeat(40), program = "printf dev-proof";
async function fixture() {
  const args = {
    operation_id: 100,
    node_id: 9,
    kind: "instance.deploy" as const,
    run_id: "ed7cfb7a-64f3-496f-8722-e3fd1c06947d",
  };
  const input = {
    instance_id: 763,
    project_id: 58,
    agent_sha256: "b".repeat(64),
    commit,
    release_name: "20261031020000-op-00000000000000000100",
    timeout_seconds: 960,
    deployment: {
      variant: "development",
      home: "/fast/apps/proof/default",
      repository: "https://github.com/example/proof.git",
      branch: "main",
      node_user: "nckrtl",
      protocols: ["https"],
      user: null,
      relative_root: null,
      application_suffix: null,
      served: [],
      shared_paths: [],
      needs_vp: false,
      projection_route_id: null as number | null,
      steps: [{
        name: "proof",
        phase: "before_activation",
        required: true,
        timeout_seconds: 60,
        command_digest: await sha256(program),
      }],
    },
  };
  let status = "queued", cancel_requested = false, reads = 0;
  const posts: Record<string, unknown>[] = [],
    calls: NodeCall[] = [],
    commands: unknown[] = [],
    paths: string[] = [];
  const http: Http = (method, path, raw) => {
    paths.push(method + " " + path);
    if (
      method === "GET" &&
      path === `/api/v1/projects/58/development-deploy-steps`
    ) {
      return Promise.resolve({
        status: 200,
        body: {
          data: [{ name: "proof", command: program, timeout_seconds: 60 }],
        },
      });
    }
    if (method === "GET" && path.includes("/private-material?")) {
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            git_read: "private-git-token",
            vp_materialize: { user: "nckrtl" },
          },
        },
      });
    }
    if (
      method === "GET" &&
      path === `/api/v1/direct-operations/100?run_id=${args.run_id}`
    ) {
      reads++;
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            id: 100,
            kind: args.kind,
            node_id: 9,
            status,
            cancel_requested,
            input: structuredClone(input),
          },
        },
      });
    }
    if (method === "POST" && path === "/api/v1/direct-operations/100/result") {
      const body = raw as Record<string, unknown>;
      posts.push(structuredClone(body));
      status = String(body.status);
      return Promise.resolve({ status: 200, body: { data: {} } });
    }
    throw new Error("Unexpected Gateway request: " + method + " " + path);
  };
  const invoke = (call: NodeCall) => {
    calls.push(call);
    const results: Record<string, Record<string, unknown>> = {
      "instance.resolve-development-target": { commit },
      "instance.checkout-development-default": { commit },
      "account.lookup": {
        user: "nckrtl",
        uid: 1000,
        group: "developers",
        home: "/home/nckrtl",
        shell: "/bin/zsh",
      },
      "instance.inspect-development-source": {
        origin: input.deployment.repository,
        branch: "main",
        commit,
      },
      "tool.read-vp-project": { package: { dependencies: {} } },
    };
    if (!(call.name in results)) throw new Error("Unexpected local command");
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
      output_tail: "private app output",
      truncated: false,
    });
  };
  const ctx = {
    globalArgs: {
      gatewayUrl: "https://gateway.example.test",
      caFile: "/test/ca",
    },
  };
  return {
    args,
    input,
    http,
    invoke,
    run,
    ctx,
    posts,
    calls,
    commands,
    paths,
    status: (value: string) => {
      status = value;
    },
    cancel: () => {
      cancel_requested = true;
    },
    reads: () => reads,
  };
}

Deno.test("native dev root fetches, checks out and runs admitted steps as configured account, then verifies locally", async () => {
  const f = await fixture();
  const result = await deployDirect({
    http: () => f.http,
    invoke: f.invoke,
    run: f.run,
    now: () => 1000,
  }).methods.run.execute(f.args, f.ctx);
  assertEquals(result, { status: "succeeded" });
  assertEquals(f.calls.map((x) => x.name), [
    "instance.resolve-development-target",
    "instance.checkout-development-default",
    "account.lookup",
    "instance.inspect-development-source",
  ]);
  assertEquals(f.calls.at(-1)?.input, {
    repository: f.input.deployment.repository,
    checkout: "/fast/apps/proof/default",
    root: "/fast/apps",
    user: "nckrtl",
    group: "developers",
    verify_origin: true,
    require_idle: true,
  });
  assertEquals((f.commands[0] as { step: { target: unknown } }).step.target, {
    variant: "checkout",
    user: "nckrtl",
    directory: "/fast/apps/proof/default",
    seed_path: "",
    seed_commit: "",
  });
  assertEquals(f.posts.at(-1)?.result, {
    instance_id: 763,
    commit,
    release: null,
    previous: null,
    selected_release: null,
    selection_confirmed: true,
    failed_step: null,
    warnings: 0,
  });
  assertEquals(JSON.stringify(f.posts).includes("private"), false);
  assertEquals(
    f.paths.some((path) => /children|operation-executor|wait|start/.test(path)),
    false,
  );
});

Deno.test("native root never replays an already running deployment", async () => {
  const f = await fixture();
  f.status("running");
  await assertRejects(
    () =>
      deployDirect({ http: () => f.http, invoke: f.invoke, run: f.run }).methods
        .run.execute(f.args, f.ctx),
    Error,
    "deployment.execution_unconfirmed",
  );
  assertEquals(f.calls, []);
  assertEquals(f.commands, []);
  assertEquals(f.posts.at(-1)?.status, "failed");
});

Deno.test("terminal native root replay makes no local calls or projections", async () => {
  for (const status of ["succeeded", "failed", "cancelled"]) {
    const f = await fixture();
    f.status(status);
    assertEquals(
      await deployDirect({ http: () => f.http, invoke: f.invoke, run: f.run })
        .methods.run.execute(f.args, f.ctx),
      { status },
    );
    assertEquals([f.calls, f.commands, f.posts], [[], [], []]);
  }
});

Deno.test("native cancellation before source records cancelled with zero effects", async () => {
  const f = await fixture();
  f.cancel();
  assertEquals(
    await deployDirect({ http: () => f.http, invoke: f.invoke, run: f.run })
      .methods.run.execute(f.args, f.ctx),
    { status: "cancelled" },
  );
  assertEquals([f.calls, f.commands], [[], []]);
  assertEquals(f.posts.at(-1)?.error_code, null);
});

Deno.test("required command failure retains checked-out commit and bounded failure name without output", async () => {
  const f = await fixture();
  await assertRejects(
    () =>
      deployDirect({
        http: () => f.http,
        invoke: f.invoke,
        run: () =>
          Promise.resolve({
            exit_code: 1,
            timed_out: false,
            output_tail: "private token",
            truncated: false,
          }),
      }).methods.run.execute(f.args, f.ctx),
    Error,
    "deployment.step_failed",
  );
  assertEquals(f.calls.length, 2);
  assertEquals(
    (f.posts.at(-1)?.result as { failed_step: string }).failed_step,
    "before_activation.proof",
  );
  assertEquals((f.posts.at(-1)?.result as { commit: string }).commit, commit);
  assertEquals(JSON.stringify(f.posts).includes("private token"), false);
});

Deno.test("verification refuses a different checked-out commit after app scripts", async () => {
  const f = await fixture();
  await assertRejects(
    () =>
      deployDirect({
        http: () => f.http,
        run: f.run,
        invoke: (call) =>
          call.name === "instance.inspect-development-source"
            ? Promise.resolve({
              origin: f.input.deployment.repository,
              branch: "main",
              commit: "c".repeat(40),
            })
            : f.invoke(call),
      }).methods.run.execute(f.args, f.ctx),
    Error,
    "deployment.interrupted",
  );
  assertEquals(f.posts.at(-1)?.status, "failed");
  assertEquals(
    (f.posts.at(-1)?.result as { failed_step: string }).failed_step,
    "verification",
  );
});

Deno.test("a pending Route repair is refused before source effects", async () => {
  const f = await fixture();
  f.input.deployment.projection_route_id = 42;
  await assertRejects(
    () =>
      deployDirect({ http: () => f.http, invoke: f.invoke }).methods.run
        .execute(f.args, f.ctx),
    Error,
    "deployment.projection_pending",
  );
  assertEquals(f.calls, []);
  assertEquals(f.commands, []);
});

Deno.test("Vite-less project preparation stays local and does not create a prerequisite Operation", async () => {
  const f = await fixture();
  f.input.deployment.needs_vp = true;
  await deployDirect({ http: () => f.http, invoke: f.invoke, run: f.run })
    .methods.run.execute(f.args, f.ctx);
  assertEquals(f.calls.map((x) => x.name), [
    "instance.resolve-development-target",
    "instance.checkout-development-default",
    "tool.read-vp-project",
    "account.lookup",
    "instance.inspect-development-source",
  ]);
  assertEquals(f.paths.some((path) => path.includes("children")), false);
});

Deno.test("Vite+ preparation and app execution share the same root and configured account", async () => {
  const f = await fixture();
  f.input.deployment.needs_vp = true;
  const runtimeCalls: string[] = [];
  const invoke: typeof f.invoke = (call) => {
    runtimeCalls.push(call.name);
    const results: Record<string, Record<string, unknown>> = {
      "tool.read-vp-project": {
        package: {
          dependencies: { "vite-plus": "0.3.3" },
          packageManager: "npm@11.12.0",
        },
        ".node-version": "24.22.0",
        npmrc: "private registry credential",
        locks: {
          "package-lock.json":
            '{"packages":{"node_modules/vite-plus":{"version":"0.3.3"}}}',
        },
      },
      "tool.materialize-vp": { changed: false },
      "tool.inspect-vp-scope": { binary: "/opt/orbit/vite-plus/bin/vp" },
      "tool.vp-version": { version: "vp 1.4.0" },
      "tool.inspect-vp-store": { installed: [true] },
    };
    return call.name in results
      ? Promise.resolve(results[call.name])
      : f.invoke(call);
  };

  assertEquals(
    await deployDirect({ http: () => f.http, invoke, run: f.run }).methods.run
      .execute(f.args, f.ctx),
    { status: "succeeded" },
  );
  assertEquals(
    f.posts.filter((post) =>
      post.status === "running" && Object.keys(post.result as object).length > 0
    )
      .map((
        post,
      ) => ((post.result as { vp_environment: { status: string } })
        .vp_environment.status)
      ),
    ["checking", "active"],
  );
  assertEquals(
    (f.commands[0] as { step: { vp_environment: { owner: string } } }).step
      .vp_environment.owner,
    "nckrtl",
  );
  assertEquals(JSON.stringify(f.posts).includes("private"), false);
  assertEquals(runtimeCalls.includes("tool.install-vp-environment"), false);
});

Deno.test("cancel after checkout stops before any app command", async () => {
  const f = await fixture();
  const invoke: typeof f.invoke = async (call) => {
    const result = await f.invoke(call);
    if (call.name === "instance.checkout-development-default") f.cancel();
    return result;
  };
  assertEquals(
    await deployDirect({ http: () => f.http, invoke, run: f.run }).methods.run
      .execute(f.args, f.ctx),
    { status: "cancelled" },
  );
  assertEquals(f.commands, []);
  assertEquals(f.calls.map((x) => x.name), [
    "instance.resolve-development-target",
    "instance.checkout-development-default",
  ]);
});

Deno.test("changed admitted account on a fresh read stops before the next local effect", async () => {
  const f = await fixture();
  const invoke: typeof f.invoke = async (call) => {
    const result = await f.invoke(call);
    if (call.name === "instance.checkout-development-default") {
      f.input.deployment.node_user = "foreign";
    }
    return result;
  };
  await assertRejects(
    () =>
      deployDirect({ http: () => f.http, invoke, run: f.run }).methods.run
        .execute(f.args, f.ctx),
    Error,
    "deployment.identity_changed",
  );
  assertEquals(f.commands, []);
  assertEquals(f.calls.length, 2);
});

Deno.test("a foreign Node cannot use the root to read material, project or execute", async () => {
  const f = await fixture();
  await assertRejects(
    () =>
      deployDirect({ http: () => f.http, invoke: f.invoke, run: f.run })
        .methods.run.execute({ ...f.args, node_id: 209 }, f.ctx),
    Error,
    "deployment.identity_changed",
  );
  assertEquals([f.calls, f.commands, f.posts], [[], [], []]);
  assertEquals(f.paths.length, 1);
});

Deno.test("optional command failure counts a warning without exposing output", async () => {
  const f = await fixture();
  f.input.deployment.steps[0].required = false;
  await deployDirect({
    http: () => f.http,
    invoke: f.invoke,
    run: () =>
      Promise.resolve({
        exit_code: 1,
        timed_out: false,
        output_tail: "private warning",
        truncated: false,
      }),
  }).methods.run.execute(f.args, f.ctx);
  assertEquals((f.posts.at(-1)?.result as { warnings: number }).warnings, 1);
  assertEquals(f.posts.at(-1)?.status, "succeeded");
  assertEquals(JSON.stringify(f.posts).includes("private warning"), false);
});

Deno.test("final checkout inspection uses the configured account without sudo", async () => {
  const f = await fixture();
  await deployDirect({ http: () => f.http, invoke: f.invoke, run: f.run })
    .methods.run.execute(f.args, f.ctx);
  const inspect = f.calls.find((call) =>
    call.name === "instance.inspect-development-source"
  );
  assertEquals(inspect?.sudo, false);
  assertEquals(inspect?.mutates, false);
});
