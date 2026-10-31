import { assertEquals } from "jsr:@std/assert@1.0.19";
import { type AppStep, currentProgram, sha256 } from "./app_step.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { DeployAppInput, runDeployPhase } from "./deploy_steps.ts";

const program = "printf 'synthetic deploy\\n'";
const command_digest = await sha256(program);
function fixture(development = false) {
  const admitted = DeployAppInput.parse({
    instance_id: 12,
    project_id: 9,
    target: development
      ? {
        variant: "checkout",
        user: "orbit",
        directory: "/home/orbit/apps/default",
        seed_path: "",
        seed_commit: "",
      }
      : { variant: "release", release: "admitted" },
    steps: [
      {
        name: "install",
        phase: "before_activation",
        command_digest,
        timeout_seconds: 30,
        required: true,
      },
      {
        name: "warm",
        phase: "before_activation",
        command_digest,
        timeout_seconds: 30,
        required: false,
      },
      ...development ? [] : [
        {
          name: "restart",
          phase: "after_activation",
          command_digest,
          timeout_seconds: 30,
          required: true,
        },
      ],
    ],
  });
  const effects: AppStep[] = [], paths: string[] = [];
  const http: Http = (_method, path) => {
    paths.push(path);
    return Promise.resolve({
      status: 200,
      body: {
        data: admitted.steps.map((row) => ({
          name: row.name,
          command: program,
          timeout_seconds: row.timeout_seconds,
          ...development ? {} : { phase: row.phase },
        })),
      },
    });
  };
  const port = {
    program: async (
      step: AppStep,
      execute: (script: string) => Promise<void>,
    ) => {
      await execute(await currentProgram(http, step));
    },
    read: () => Promise.resolve({ input: admitted, cancel_requested: false }),
    now: () => 1000,
    run: (step: AppStep, script: string) => {
      assertEquals(script, program);
      effects.push(step);
      return Promise.resolve({
        exit_code: 0,
        timed_out: false,
        output_tail: "",
        truncated: false,
      });
    },
  };
  return { admitted, effects, paths, port };
}

Deno.test("a retained private script callback cannot execute after delivery closes", async () => {
  const f = fixture();
  let retained: ((script: string) => Promise<void>) | undefined;
  f.port.program = (_step, execute) => {
    retained = execute;
    return Promise.resolve();
  };
  assertEquals(
    await runDeployPhase(f.admitted, "before_activation", 100000, f.port),
    {
      error_code: "instance.step_not_admitted",
      failed_step: "before_activation.install",
    },
  );
  await retained!(program);
  assertEquals(f.effects, []);
});

Deno.test("deploy sudo phases keep admitted order, release and full timeouts", async () => {
  const f = fixture();
  assertEquals(
    await runDeployPhase(f.admitted, "before_activation", 100000, f.port),
    { completed: 2, warnings: 0 },
  );
  assertEquals(f.effects.map((s) => s.name), ["install", "warm"]);
  assertEquals(f.effects.map((s) => s.timeout_seconds), [30, 30]);
  assertEquals(f.effects[0].target, {
    variant: "release",
    release: "admitted",
  });
  assertEquals(
    await runDeployPhase(f.admitted, "after_activation", 100000, f.port),
    { completed: 1, warnings: 0 },
  );
  assertEquals(f.effects[2].name, "restart");
});

Deno.test("development deploy uses Project commands as the admitted Node account", async () => {
  const f = fixture(true);
  assertEquals(
    await runDeployPhase(f.admitted, "before_activation", 100000, f.port),
    { completed: 2, warnings: 0 },
  );
  assertEquals(
    f.paths,
    Array(2).fill("/api/v1/projects/9/development-deploy-steps"),
  );
  assertEquals(f.effects[0].target, f.admitted.target);
});

Deno.test("changed target or full list refuses before another sudo command", async () => {
  for (
    const change of [
      (x: DeployAppInput) => {
        x.project_id++;
      },
      (x: DeployAppInput) => {
        x.target = { variant: "release", release: "foreign" };
      },
      (x: DeployAppInput) => {
        x.steps[0].timeout_seconds++;
      },
      (x: DeployAppInput) => {
        x.steps[0].required = false;
      },
      (x: DeployAppInput) => {
        x.steps.reverse();
      },
    ]
  ) {
    const f = fixture();
    const changed = structuredClone(f.admitted);
    change(changed);
    f.port.read = () =>
      Promise.resolve({ input: changed, cancel_requested: false });
    assertEquals(
      await runDeployPhase(f.admitted, "before_activation", 100000, f.port),
      {
        error_code: "deployment.identity_changed",
        failed_step: "before_activation.install",
      },
    );
    assertEquals(f.effects.length, 0);
  }
});

Deno.test("only a proven best-effort command failure may continue the deploy phase", async () => {
  const f = fixture();
  f.port.run = (step) => {
    f.effects.push(step);
    return Promise.resolve({
      exit_code: step.name === "warm" ? 3 : 0,
      timed_out: false,
      output_tail: "",
      truncated: false,
    });
  };
  assertEquals(
    await runDeployPhase(f.admitted, "before_activation", 100000, f.port),
    { completed: 2, warnings: 1 },
  );
  for (const required of [true, false]) {
    const other = fixture();
    other.admitted.steps[0].required = required;
    const result = await runDeployPhase(
      other.admitted,
      "before_activation",
      100000,
      {
        ...other.port,
        run: () =>
          Promise.resolve({
            error_code: "instance.step_execution_unconfirmed",
          }),
      },
    );
    assertEquals(result, {
      error_code: "deployment.execution_unconfirmed",
      failed_step: "before_activation.install",
    });
    assertEquals(other.effects.length, 0);
  }
});

Deno.test("timeout, deadline shortage and uncertain execution stop even a best-effort step", async () => {
  const f = fixture();
  f.admitted.steps[0].required = false;
  assertEquals(
    await runDeployPhase(f.admitted, "before_activation", 30999, f.port),
    {
      error_code: "deployment.deadline_exceeded",
      failed_step: "before_activation.install",
    },
  );
  assertEquals(f.effects.length, 0);
  assertEquals(
    await runDeployPhase(f.admitted, "before_activation", 100000, {
      ...f.port,
      run: () =>
        Promise.resolve({
          exit_code: 124,
          timed_out: true,
          output_tail: "",
          truncated: false,
        }),
    }),
    {
      error_code: "deployment.command_timed_out",
      failed_step: "before_activation.install",
    },
  );
  assertEquals(
    await runDeployPhase(f.admitted, "before_activation", 100000, {
      ...f.port,
      run: () => {
        throw new Error("private diagnostic");
      },
    }),
    {
      error_code: "deployment.execution_unconfirmed",
      failed_step: "before_activation.install",
    },
  );
});

Deno.test("cancellation between admitted steps prevents the next command", async () => {
  const f = fixture();
  f.port.read = () =>
    Promise.resolve({
      input: f.admitted,
      cancel_requested: f.effects.length > 0,
    });
  assertEquals(
    await runDeployPhase(f.admitted, "before_activation", 100000, f.port),
    {
      error_code: "operation.cancelled",
      failed_step: "before_activation.warm",
    },
  );
  assertEquals(f.effects.map((s) => s.name), ["install"]);
});

Deno.test("changed private commands refuse without returning command content", async () => {
  const f = fixture();
  f.port.program = async (_step, execute) => {
    await execute("private changed");
  };
  assertEquals(
    await runDeployPhase(f.admitted, "before_activation", 100000, f.port),
    {
      error_code: "instance.step_not_admitted",
      failed_step: "before_activation.install",
    },
  );
  assertEquals(f.effects.length, 0);
});

Deno.test("private callback replay and transport failure never repeat a deploy command", async () => {
  const f = fixture();
  f.port.program = async (_step, execute) => {
    await execute(program);
    await execute(program);
    throw new Error("private transport diagnostic");
  };
  assertEquals(
    await runDeployPhase(f.admitted, "before_activation", 100000, f.port),
    { completed: 2, warnings: 0 },
  );
  assertEquals(f.effects.map((step) => step.name), ["install", "warm"]);
});

Deno.test("a private callback cannot suppress an uncertain effect or omit command delivery", async () => {
  for (const delivered of [true, false]) {
    const f = fixture();
    f.port.program = async (_step, execute) => {
      try {
        if (delivered) await execute(program);
      } catch {
        /* Mirrors a private transport that suppresses callback errors. */
      }
    };
    f.port.run = () => {
      throw new Error("private process diagnostic");
    };
    assertEquals(
      await runDeployPhase(f.admitted, "before_activation", 100000, f.port),
      {
        error_code: delivered
          ? "deployment.execution_unconfirmed"
          : "instance.step_not_admitted",
        failed_step: "before_activation.install",
      },
    );
  }
});
