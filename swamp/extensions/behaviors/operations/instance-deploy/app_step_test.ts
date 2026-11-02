import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  AppStep,
  currentProgram,
  sha256,
  stepRunner,
  sudoAppStep,
} from "./app_step.ts";
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
Deno.test("production deploy phases read their admitted Instance command list", async () => {
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


Deno.test("development deploy commands use only the Project before-activation list", async () => {
  const target = {
    variant: "checkout" as const,
    user: "orbit",
    directory: "/home/orbit/apps/default",
    seed_path: "",
    seed_commit: "",
  };
  const admitted = { ...step, target, phase: "before_activation" as const };
  const paths: string[] = [];
  const http: Http = (_method, path) => {
    paths.push(path);
    // Canonical Gateway route: routes/api/project.php. Other paths are absent.
    if (path !== "/api/v1/projects/9/dev-deploy-steps") {
      return Promise.resolve({ status: 404, body: {} });
    }
    return Promise.resolve({
      status: 200,
      body: {
        data: [{ name: "install", command: script, timeout_seconds: 30 }],
      },
    });
  };
  assertEquals(await currentProgram(http, admitted), script);
  assertEquals(paths, ["/api/v1/projects/9/dev-deploy-steps"]);
  await assertRejects(
    () => currentProgram(http, { ...admitted, phase: "after_activation" }),
    Error,
    "instance.step_not_admitted",
  );
  assertEquals(paths.length, 1);
  await assertRejects(
    () =>
      currentProgram(() =>
        Promise.resolve({
          status: 200,
          body: {
            data: [{
              name: "install",
              command: script,
              timeout_seconds: 30,
              phase: "before_activation",
            }],
          },
        }), admitted),
    Error,
    "instance.step_not_admitted",
  );
});

Deno.test("step runner identity is public and bound to Operation run and admitted step", () => {
  assertEquals(
    stepRunner({
      operation_id: 100,
      run_id: "ed7cfb7a-64f3-496f-8722-e3fd1c06947d",
    }, step),
    "orbit-deploy-step:100:ed7cfb7a-64f3-496f-8722-e3fd1c06947d:setup.install",
  );
});

Deno.test("step runner normalizes run UUID case for the Node absence probe", () => {
  const execution = {
    operation_id: 100,
    run_id: "ED7CFB7A-64F3-496F-8722-E3FD1C06947D",
  };
  assertEquals(
    stepRunner(execution, step),
    "orbit-deploy-step:100:ed7cfb7a-64f3-496f-8722-e3fd1c06947d:setup.install",
  );
});
