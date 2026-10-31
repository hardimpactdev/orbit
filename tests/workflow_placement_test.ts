import { assertEquals } from "jsr:@std/assert@1.0.14";
import { placementErrors } from "../bin/lint-workflow-placement.ts";

const workflow = (step: Record<string, unknown>, extra = {}) => ({
  ...extra,
  jobs: [{
    name: "work",
    steps: [{ name: "effect", task: { type: "behavior" }, ...step }],
  }],
});

Deno.test("placement lint rejects an unplaced effect", () => {
  assertEquals(placementErrors(workflow({}), "fixture"), [
    "fixture: work/effect has no worker placement",
  ]);
});

Deno.test("native workflow and job placement inherit to steps", () => {
  assertEquals(
    placementErrors(workflow({}, { labels: { pool: "migration" } }), "fixture"),
    [],
  );
  assertEquals(
    placementErrors({
      jobs: [{ labels: { pool: "migration" }, steps: [{ name: "s" }] }],
    }, "fixture"),
    [],
  );
});

Deno.test("empty step labels erase inherited labels but keep an inherited target", () => {
  assertEquals(
    placementErrors(
      workflow({ labels: {} }, { labels: { pool: "migration" } }),
      "fixture",
    ).length,
    1,
  );
  assertEquals(
    placementErrors(
      workflow({ labels: {} }, {
        target: "orbit-node-208",
        labels: { node: "208" },
      }),
      "fixture",
    ),
    [],
  );
});

Deno.test("intrinsic steps also declare placement; malformed labels do not place work", () => {
  for (
    const type of ["assert", "workflow", "manual_approval", "wait_for_signal"]
  ) {
    assertEquals(
      placementErrors(workflow({ task: { type } }), "fixture").length,
      1,
    );
  }
  for (const labels of [{}, { pool: 209 }, [], null]) {
    assertEquals(placementErrors(workflow({ labels }), "fixture").length, 1);
  }
});
