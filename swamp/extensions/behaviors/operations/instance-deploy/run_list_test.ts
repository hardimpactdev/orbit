import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { AppStep, sha256 } from "./app_step.ts";
import { ProjectStepList, runProjectStepList } from "./run_list.ts";
const script = "printf 'synthetic parent-step\\n'";
const digest = await sha256(script);
function fixture() {
  const input = ProjectStepList.parse({
    instance_id: 12,
    project_id: 9,
    environment: "development",
    list: "teardown",
    execution: {
      variant: "checkout",
      user: "nckrtl",
      directory: "/fast/apps/example/topic",
      seed_path: "",
      seed_commit: "",
    },
    steps: ["first", "second"].map((name) => ({
      name,
      command_digest: digest,
      timeout_seconds: 60,
    })),
  });
  const effects: AppStep[] = [];
  const port = {
    read: () => Promise.resolve({ input, cancel_requested: false }),
    program: async (
      _step: AppStep,
      execute: (program: string) => Promise<void>,
    ) => {
      await execute(script);
    },
    run: (step: AppStep) => {
      effects.push(step);
      return Promise.resolve({
        exit_code: 0,
        timed_out: false,
        output_tail: "",
        truncated: false,
      });
    },
  };
  return { input, port, effects };
}
Deno.test("native parent runs an ordered private teardown list without a Gateway child or projection", async () => {
  const f = fixture();
  const result = await runProjectStepList(f.input, f.port);
  assertEquals(result, { instance_id: 12, outcome: "succeeded", no_op: false });
  assertEquals(f.effects.map((row) => row.name), ["first", "second"]);
  assertEquals(
    f.effects.every((row) =>
      row.target.variant === "checkout" && row.target.user === "nckrtl" &&
      row.phase === "teardown" && row.timeout_seconds === 60
    ),
    true,
  );
  assertEquals(JSON.stringify(result).includes(script), false);
});
Deno.test("private script mismatch and missing private callback refuse before any parent sudo effect", async () => {
  for (const delivered of [true, false]) {
    const f = fixture();
    f.port.program = async (_step, execute) => {
      if (delivered) await execute("changed private command");
    };
    const result = await runProjectStepList(f.input, f.port);
    assertEquals(result, {
      instance_id: 12,
      outcome: "unconfirmed",
      error_code: delivered
        ? "instance.step_not_admitted"
        : "instance.teardown_step_failed",
      failed_step: "teardown.first",
    });
    assertEquals(f.effects.length, 0);
  }
});
Deno.test("native parent revalidates list and cancellation before every command", async () => {
  for (const cancelled of [true, false]) {
    const f = fixture();
    f.port.read = () =>
      Promise.resolve({
        input: f.effects.length ? { ...f.input, project_id: 10 } : f.input,
        cancel_requested: cancelled && f.effects.length > 0,
      });
    const result = await runProjectStepList(f.input, f.port);
    assertEquals(
      result,
      cancelled ? { cancelled: true } : {
        instance_id: 12,
        outcome: "unconfirmed",
        error_code: "instance.step_not_admitted",
        failed_step: "teardown.second",
      },
    );
    assertEquals(f.effects.length, 1);
  }
});
Deno.test("private transport suppression cannot turn an uncertain sudo effect into successful teardown", async () => {
  const f = fixture();
  f.port.program = async (_step, execute) => {
    try {
      await execute(script);
    } catch { /* Shared private transport hides callback failures. */ }
  };
  f.port.run = (step) => {
    f.effects.push(step);
    throw new Error("private diagnostic");
  };
  assertEquals(await runProjectStepList(f.input, f.port), {
    instance_id: 12,
    outcome: "unconfirmed",
    error_code: "instance.teardown_step_failed",
    failed_step: "teardown.first",
  });
  assertEquals(f.effects.length, 1);
});
Deno.test("private callback replay executes each admitted command only once", async () => {
  const f = fixture();
  f.port.program = async (_step, execute) => {
    await execute(script);
    await execute(script);
  };
  assertEquals(await runProjectStepList(f.input, f.port), {
    instance_id: 12,
    outcome: "succeeded",
    no_op: false,
  });
  assertEquals(f.effects.length, 2);
});

Deno.test("development lists refuse production targets and environments before effects", async () => {
  for (
    const change of [
      { environment: "production" },
      { execution: { variant: "release", release: "foreign" } },
      { execution: { ...fixture().input.execution, user: "root" } },
    ]
  ) {
    const f = fixture();
    await assertRejects(() =>
      runProjectStepList(
        { ...f.input, ...change } as ProjectStepList,
        f.port,
      )
    );
    assertEquals(f.effects, []);
  }
});

Deno.test("private callback retained after list delivery cannot start a late sudo step", async () => {
  const f = fixture();
  let retained: ((program: string) => Promise<void>) | undefined;
  f.port.program = (_step, execute) => {
    retained = execute;
    return Promise.resolve();
  };
  const result = await runProjectStepList(f.input, f.port);
  assertEquals("outcome" in result && result.outcome, "unconfirmed");
  await retained!(script);
  assertEquals(f.effects, []);
});
