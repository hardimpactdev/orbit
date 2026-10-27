import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import {
  reconcileStageLaunch,
  type StageLaunch,
} from "../extensions/behaviors/_lib/stage_launch.ts";
import {
  behavior as driver,
  stageLaunchReceipt,
} from "../extensions/behaviors/orbit_driver.ts";

Deno.test("stage launch with a lost reply reconciles its exact receipt without repeating transport", async () => {
  const intent: StageLaunch = {
    key: "tg-7.work.c1",
    workflow: "stage-work",
    launches: 2,
    log: "/fixture/log",
    lastLaunchedAt: "2026-10-27T12:00:00Z",
    pending: true,
    inputs: { key: "tg-7.work.c1", workItem: "tg-7" },
    priorRunIds: ["earlier"],
  };
  let saved: StageLaunch | null = null;
  const result = await reconcileStageLaunch(intent, () =>
    Promise.resolve([
      { id: "earlier", inputs: intent.inputs! },
      { id: "foreign", inputs: { key: "tg-8.work.c1", workItem: "tg-8" } },
      { id: "accepted", inputs: intent.inputs! },
    ]), (receipt) => {
    saved = receipt;
    return Promise.resolve();
  }, 180);
  assertEquals(result.waiting, null);
  assertEquals(result.receipt.runId, "accepted");
  assertEquals(result.receipt.pending, false);
  assertEquals(result.receipt.launches, 2);
  assertEquals(saved, result.receipt);
  for (
    const matches of [[], [{ id: "a", inputs: intent.inputs! }, {
      id: "b",
      inputs: intent.inputs!,
    }]]
  ) {
    const parked = await reconcileStageLaunch(
      intent,
      () => Promise.resolve(matches),
      () => {
        throw new Error("must retain uncertain intent");
      },
      180,
    );
    assert(parked.waiting?.startsWith("assistance:"));
    assertEquals(parked.receipt, intent);
  }
  const changed = await reconcileStageLaunch(
    { ...intent, inputs: { ...intent.inputs, baseClone: "expected" } },
    () =>
      Promise.resolve([{
        id: "foreign-payload",
        inputs: { ...intent.inputs, baseClone: "other" },
      }]),
    () => {
      throw new Error("cannot adopt changed inputs");
    },
    180,
  );
  assert(changed.waiting?.startsWith("assistance:"));
  const waiting = await reconcileStageLaunch(
    intent,
    () => Promise.resolve([]),
    () => Promise.resolve(),
    180,
    Date.parse(intent.lastLaunchedAt) + 1000,
  );
  assert(!waiting.waiting?.startsWith("assistance:"));
});

Deno.test("stage receipts migrate once from the fleet driver and remain private to each group", async () => {
  const fleet: StageLaunch = {
    key: "tg-7.work.c1",
    workflow: "stage-work",
    launches: 1,
    log: "/fixture/log",
    lastLaunchedAt: "2026-10-27T12:00:00Z",
    runId: "existing",
  };
  const writes: unknown[] = [];
  let own: StageLaunch | null = null;
  const ctx = {
    definitionName: "orbit-group-tg-7",
    globalArgs: driver.globalArguments.parse({ preapprovalModel: "fleet" }),
    queryData: (p: string) =>
      Promise.resolve(
        p.includes('modelName == "fleet"') ? [fleet] : own ? [own] : [],
      ),
    writeResource: (
      _spec: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      writes.push({ name, value });
      own = value as unknown as StageLaunch;
      return Promise.resolve({ name });
    },
    runModel: () => {
      throw new Error("migration cannot dispatch work");
    },
    logger: { info() {}, warning() {} },
  };
  assertEquals(await stageLaunchReceipt(ctx, fleet.key), fleet);
  fleet.runId = "changed-by-fleet";
  assertEquals((await stageLaunchReceipt(ctx, fleet.key))?.runId, "existing");
  assertEquals(writes.length, 1);
});

Deno.test("workflow-mode stage saves the per-group intent before starting transport", async () => {
  const root = await Deno.makeTempDir({ prefix: "stage-launch-contract-" });
  const originalPath = Deno.env.get("PATH");
  try {
    await Deno.mkdir(`${root}/bin`);
    await Deno.writeTextFile(
      `${root}/bin/setsid`,
      `#!/bin/sh\ntouch '${root}/transport-started'\n`,
    );
    await Deno.chmod(`${root}/bin/setsid`, 0o700);
    Deno.env.set("PATH", `${root}/bin:${originalPath ?? ""}`);
    const stage = {
      started: true,
      stage: { id: "work", cycle: 1, terminal: false },
      unresolvedBindings: [],
      transitions: [],
      dispatch: { required: true, executed: true },
      work: {
        mode: "workflow",
        resultEvidence: "result",
        workflow: { name: "fixture-stage", inputs: {} },
      },
    };
    let refused = false;
    const ctx = {
      definition: { name: "orbit-group-tg-7" },
      globalArgs: driver.globalArguments.parse({
        repoDir: root,
        scratchRoot: root,
        factory: "factory",
      }),
      queryData: (p: string) => {
        if (p.includes('name == "status-tg-7"')) {
          return Promise.resolve([stage]);
        }
        if (p.includes('name == "upgrade-plan"')) {
          return Promise.resolve([{
            commit: "fixture",
            subtasks: [{ id: "tg-7", subProject: "." }],
          }]);
        }
        return Promise.resolve([]);
      },
      writeResource: (
        _spec: string,
        name: string,
        value: Record<string, unknown>,
      ) => {
        if (name.startsWith("launch-")) {
          assertEquals(value.pending, true);
          assertEquals(value.inputs, {
            workItem: "tg-7",
            key: "tg-7.work.c1",
            worktree: `${root}/wt2/tg-7`,
            branch: "swamp/v2/tg-7",
            subProject: ".",
            planCommit: "fixture",
            baseClone: "/fast/apps/orbit-swamp-scratch/base",
          });
          refused = true;
          throw new Error("receipt write refused");
        }
        return Promise.resolve({ name });
      },
      runModel: () => Promise.resolve({ ok: true }),
      logger: { info() {}, warning() {} },
    };
    await driver.methods.tick.execute(
      { workItems: ["tg-7"], maxSteps: 1 },
      ctx,
    );
    assert(refused);
    assertEquals(
      await Deno.stat(`${root}/transport-started`).then(() => true).catch(() =>
        false
      ),
      false,
    );
  } finally {
    if (originalPath === undefined) Deno.env.delete("PATH");
    else Deno.env.set("PATH", originalPath);
    await Deno.remove(root, { recursive: true });
  }
});
