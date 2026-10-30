import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { AppStep, currentProgram, sha256, sudoAppStep } from "./app_step.ts";
import { setupDirect } from "./setup_direct.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

const script = "printf 'synthetic step\\n'";
const digest = await sha256(script);
const step = AppStep.parse({
  project_id: 9,
  instance_id: 12,
  target: { variant: "release", release: "admitted" },
  name: "install",
  phase: "setup",
  command_digest: digest,
  timeout_seconds: 30,
});
Deno.test("app command read is fixed to admitted Project and verifies current digest and timeout", async () => {
  const paths: string[] = [];
  const http: Http = (_method, path) => {
    paths.push(path);
    return Promise.resolve({
      status: 200,
      body: {
        data: [{ name: "install", command: script, timeout_seconds: 30 }],
      },
    });
  };
  assertEquals(await currentProgram(http, step), script);
  assertEquals(paths, ["/api/v1/projects/9/setup-steps"]);
  const deploy = { ...step, phase: "after_activation" as const };
  await currentProgram((_m, p) => {
    paths.push(p);
    return Promise.resolve({
      status: 200,
      body: {
        data: [{
          name: "install",
          phase: "after_activation",
          command: script,
          timeout_seconds: 30,
        }],
      },
    });
  }, deploy);
  assertEquals(paths[1], "/api/v1/instances/12/deploy-steps");
  for (
    const row of [
      { name: "install", command: "private changed", timeout_seconds: 30 },
      { name: "install", command: script, timeout_seconds: 31 },
      { name: "other", command: script, timeout_seconds: 30 },
    ]
  ) {
    await assertRejects(
      () =>
        currentProgram(
          () => Promise.resolve({ status: 200, body: { data: [row] } }),
          step,
        ),
      Error,
      "instance.step_not_admitted",
    );
  }
});
Deno.test("app command errors never return private programs or raw HTTP errors", async () => {
  for (
    const http of [
      () => {
        throw new Error("private material");
      },
      () =>
        Promise.resolve({
          status: 403,
          body: { error: { message: "private material" } },
        }),
      () => Promise.resolve({ status: 200, body: { data: [] } }),
    ] satisfies Http[]
  ) {
    const error = await assertRejects(() => currentProgram(http, step));
    assertEquals(
      error instanceof Error ? error.message : "unexpected",
      "instance.step_not_admitted",
    );
  }
  assertEquals(await sudoAppStep(step, "wrong digest"), {
    error_code: "instance.step_not_admitted",
  });
});
const args = {
  operation_id: 44,
  kind: "instance.run_steps" as const,
  node_id: 4,
  run_id: "f34f444b-601b-4444-b814-ce7361337489",
};
const ctx = {
  globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "fixture" },
};
function fixture(status = "queued", list: "setup" | "teardown" = "setup") {
  const effects: string[] = [], projections: Record<string, unknown>[] = [];
  const input = {
    instance_id: 12,
    project_id: 9,
    list,
    execution: { variant: "release", release: "admitted" },
    steps: [{ name: "install", command_digest: digest, timeout_seconds: 30 }, {
      name: "build",
      command_digest: digest,
      timeout_seconds: 30,
    }],
  };
  const http: Http = (method, path, body) => {
    if (method === "POST") {
      projections.push(body as Record<string, unknown>);
      status = String((body as Record<string, unknown>).status);
      return Promise.resolve({ status: 200, body: { data: {} } });
    }
    if (path.includes("/direct-operations/")) {
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            id: 44,
            kind: args.kind,
            node_id: 4,
            status,
            input,
            result: null,
            cancel_requested: false,
          },
        },
      });
    }
    if (path === `/api/v1/projects/9/${list}-steps`) {
      return Promise.resolve({
        status: 200,
        body: { data: input.steps.map((x) => ({ ...x, command: script })) },
      });
    }
    throw new Error("unexpected path");
  };
  return { effects, projections, http };
}
Deno.test("setup executes admitted commands in order and projects only bounded summary", async () => {
  const f = fixture();
  const behavior = setupDirect(() => f.http, (admitted, program) => {
    f.effects.push(admitted.name);
    assertEquals(program, script);
    return Promise.resolve({
      exit_code: 0,
      timed_out: false,
      output_tail: "[REDACTED]",
      truncated: false,
    });
  });
  assertEquals(await behavior.methods.run.execute(args, ctx), {
    status: "succeeded",
  });
  assertEquals(f.effects, ["install", "build"]);
  assertEquals(f.projections[1].result, {
    instance_id: 12,
    outcome: "succeeded",
    no_op: false,
  });
  assertEquals(JSON.stringify(f.projections).includes(script), false);
});
Deno.test("terminal setup replay does not execute or project again", async () => {
  const f = fixture("succeeded");
  await setupDirect(() => f.http, () => {
    throw new Error("repeated effect");
  }).methods.run.execute(args, ctx);
  assertEquals(f.projections, []);
});
Deno.test("setup stops at first proven failure and does not execute later commands", async () => {
  const f = fixture();
  await assertRejects(
    () =>
      setupDirect(() => f.http, (admitted) => {
        f.effects.push(admitted.name);
        return Promise.resolve({
          exit_code: 124,
          timed_out: true,
          output_tail: "",
          truncated: false,
        });
      }).methods.run.execute(args, ctx),
    Error,
    "instance.setup_step_failed",
  );
  assertEquals(f.effects, ["install"]);
  assertEquals(f.projections[1].result, {
    instance_id: 12,
    outcome: "failed",
    error_code: "instance.setup_step_failed",
    failed_step: "setup.install",
  });
});

Deno.test("setup cancellation during a settled command projects cancelled and starts no later command", async () => {
  const f = fixture(), controller = new AbortController();
  const result = await setupDirect(() => f.http, (admitted) => {
    f.effects.push(admitted.name);
    controller.abort();
    return Promise.resolve({
      exit_code: 130,
      timed_out: false,
      output_tail: "",
      truncated: false,
    });
  }).methods.run.execute(args, { ...ctx, signal: controller.signal });
  assertEquals(result, { status: "cancelled" });
  assertEquals(f.effects, ["install"]);
  assertEquals(f.projections[1].status, "cancelled");
  assertEquals(f.projections[1].result, {});
});
Deno.test("setup cancellation between commands projects cancelled before a new effect", async () => {
  const f = fixture();
  let reads = 0;
  const http: Http = async (method, path, body) => {
    const response = await f.http(method, path, body);
    if (
      method === "GET" && path.includes("/direct-operations/") && ++reads === 2
    ) {
      (response.body as { data: { cancel_requested: boolean } }).data
        .cancel_requested = true;
    }
    return response;
  };
  assertEquals(
    await setupDirect(() => http, () => {
      throw new Error("cancelled effect started");
    }).methods.run.execute(args, ctx),
    { status: "cancelled" },
  );
  assertEquals(f.projections[1].status, "cancelled");
});

Deno.test("teardown and production deploy phases use their admitted command list and full timeout", async () => {
  const f = fixture("queued", "teardown");
  await setupDirect(() => f.http, (admitted) => {
    assertEquals(admitted.phase, "teardown");
    assertEquals(admitted.timeout_seconds, 30);
    f.effects.push(admitted.name);
    return Promise.resolve({
      exit_code: 0,
      timed_out: false,
      output_tail: "",
      truncated: false,
    });
  }).methods.run.execute(args, ctx);
  assertEquals(f.effects, ["install", "build"]);
  for (const phase of ["before_activation", "after_activation"] as const) {
    const admitted = { ...step, phase };
    assertEquals(
      await currentProgram((_m, path) => {
        assertEquals(path, "/api/v1/instances/12/deploy-steps");
        return Promise.resolve({
          status: 200,
          body: {
            data: [{
              name: "install",
              phase,
              command: script,
              timeout_seconds: 30,
            }],
          },
        });
      }, admitted),
      script,
    );
  }
});
Deno.test("changed current command refuses before sudo and unknown local failure never authorizes cleanup", async () => {
  const f = fixture();
  await assertRejects(() =>
    setupDirect(
      () => f.http,
      () =>
        Promise.resolve({
          exit_code: 125,
          timed_out: false,
          output_tail: "",
          truncated: false,
        }),
    ).methods.run.execute(args, ctx)
  );
  assertEquals(
    (f.projections[1].result as Record<string, unknown>).outcome,
    "unconfirmed",
  );
  const second = fixture();
  await assertRejects(
    () =>
      setupDirect(
        () => async (method, path, body) =>
          path.endsWith("/setup-steps")
            ? {
              status: 200,
              body: {
                data: [{
                  name: "install",
                  command: "changed",
                  timeout_seconds: 30,
                }],
              },
            }
            : await second.http(method, path, body),
        () => {
          throw new Error("must not run");
        },
      ).methods.run.execute(args, ctx),
    Error,
    "instance.step_not_admitted",
  );
  assertEquals(
    (second.projections[1].result as Record<string, unknown>).outcome,
    "unconfirmed",
  );
});

Deno.test("an interrupted running list refuses replay before another sudo effect", async () => {
  const f = fixture("running");
  await assertRejects(
    () =>
      setupDirect(() => f.http, () => {
        throw new Error("must not repeat script");
      }).methods.run.execute(args, ctx),
    Error,
    "instance.step_execution_unconfirmed",
  );
  assertEquals(f.effects, []);
  assertEquals(f.projections.length, 1);
  assertEquals(f.projections[0].status, "failed");
  assertEquals(f.projections[0].result, {
    instance_id: 12,
    outcome: "unconfirmed",
    error_code: "instance.step_execution_unconfirmed",
    failed_step: null,
  });
});
