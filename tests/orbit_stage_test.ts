// Method tests for @nckrtl/orbit-stage with @swamp-club/swamp-testing.
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { behavior } from "../extensions/behaviors/orbit_stage.ts";

const args = {
  workItem: "bump-x",
  stage: "handoff" as const,
  key: "bump-x.handoff.c2",
  worktree: "/nonexistent",
  subProject: "apps/x",
  planCommit: "8bd95343a",
};
const globalArgs = { factory: "orbit-subtasks", composer: "composer", scratchRoot: "/tmp" };

Deno.test("claim records the run id and this process", async () => {
  const { context, getWrittenResources } = createModelTestContext({ globalArgs });
  await behavior.methods.claim.execute({ ...args, runId: "run-1" }, context as never);
  const [w] = getWrittenResources();
  assertEquals(w.name, "claim-bump-x.handoff.c2");
  assertEquals(w.data.runId, "run-1");
  assertEquals(w.data.pid, Deno.pid);
});

Deno.test("report records nothing when this stage entry already has its evidence", async () => {
  const { context, getWrittenResources } = createModelTestContext({ globalArgs });
  const calls: unknown[] = [];
  const ctx = Object.assign(context, {
    queryData: (predicate: string) =>
      Promise.resolve(
        predicate.includes("evidence-bump-x-handoff-run")
          ? [{ name: "evidence-bump-x-handoff-run", attributes: { stageId: "handoff", cycle: 2 } }]
          : [],
      ),
    runModel: (o: unknown) => {
      calls.push(o);
      return Promise.resolve({ ok: true });
    },
  });
  await behavior.methods.report.execute({ ...args, runId: "run-2" }, ctx as never);
  assertEquals(calls, []);
  assertEquals(getWrittenResources()[0].data.status, "already-recorded");
});
