import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { directOperation, DirectOperationCancelled } from "../_lib/direct.ts";
import { script, ScriptInput, ScriptResult } from "./script.ts";

const input = ScriptInput.parse({
  group_id: 8,
  instance_id: 9,
  node_id: 30,
  subtask: 10,
  checkout_path: "/srv/task",
  script: "scripts/dependencies",
  args: ["literal; $(nope)"],
  ssr_port: 4177,
});
const result = {
  outcome: "passed" as const,
  exit_code: 0,
  output: "Done",
  output_truncated: false,
  changed: false,
  head: "a".repeat(40),
  tree: "b".repeat(40),
};
Deno.test("script uses one operation key and preserves literal arguments", async () => {
  let admitted: Record<string, unknown> = {};
  assertEquals(
    await script(input, 11, (value) => {
      admitted = value;
      return Promise.resolve(result);
    }),
    result,
  );
  assertEquals(admitted, { ...input, execution_key: "operation-11" });
});
Deno.test("confirmed cancellation has no running effect", async () => {
  await assertRejects(
    () =>
      script(input, 11, () =>
        Promise.resolve({
          ...result,
          cancelled: true,
          outcome: "failed",
          exit_code: null,
        })),
    DirectOperationCancelled,
  );
});
Deno.test("nonzero scripts complete as business failures", async () => {
  const postings: unknown[] = [];
  const args = {
    operation_id: 11,
    node_id: 30,
    kind: "tasks.script",
    run_id: "849dd6d8-a55d-4318-b18b-3afaf96a52c4",
  };
  await directOperation(
    args,
    {
      read: () =>
        Promise.resolve({
          id: 11,
          node_id: 30,
          kind: "tasks.script",
          status: "queued",
          input,
          result: null,
          cancel_requested: false,
        }),
      project: (body) => {
        postings.push(body);
        return Promise.resolve({});
      },
    },
    () =>
      script(
        input,
        11,
        () => Promise.resolve({ ...result, outcome: "failed", exit_code: 23 }),
      ),
    (value) => ScriptResult.parse(value),
  );
  assertEquals((postings[1] as Record<string, unknown>).status, "succeeded");
  assertEquals(
    ((postings[1] as Record<string, unknown>).result as Record<string, unknown>)
      .exit_code,
    23,
  );
});
Deno.test("terminal replay dispatches no repository process", async () => {
  const args = {
    operation_id: 11,
    node_id: 30,
    kind: "tasks.script",
    run_id: "849dd6d8-a55d-4318-b18b-3afaf96a52c4",
  };
  let starts = 0;
  await directOperation(args, {
    read: () =>
      Promise.resolve({
        id: 11,
        node_id: 30,
        kind: "tasks.script",
        status: "succeeded",
        input,
        result,
        cancel_requested: false,
      }),
    project: () => {
      throw new Error("No second projection");
    },
  }, () => {
    starts++;
    return Promise.resolve(result);
  }, (value) => ScriptResult.parse(value));
  assertEquals(starts, 0);
});
